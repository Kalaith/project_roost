<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\Request;

/**
 * Built-in (no third-party) abuse controls for the public bug-report endpoint.
 *
 * Layers, cheapest first:
 *  1. Honeypot          - a hidden field bots tend to fill; filled => silently dropped.
 *  2. Signed challenge  - an HMAC nonce issued when the form opens; enforces a minimum
 *                         human think-time and a maximum age, and can't be forged.
 *  3. Rate limiting     - per hashed-IP caps over short and daily windows (DB-backed).
 *  4. Strict validation - required fields, length caps, control-char stripping.
 *
 * Nothing here trusts submitted content as HTML; sanitisation is about storage hygiene,
 * and escaping happens at render time in the admin dashboard.
 */
final class BugReportGuard
{
    public const RESULT_OK = 'ok';
    public const RESULT_HONEYPOT = 'honeypot';

    private const SUMMARY_MAX = 140;
    private const DESCRIPTION_MAX = 4000;
    private const DESCRIPTION_MIN = 10;
    private const CONTACT_MAX = 190;
    private const VERSION_MAX = 40;
    private const URL_MAX = 500;
    private const USER_AGENT_MAX = 400;

    // Rate-limit windows: (seconds, max submissions in that window).
    private const RATE_LIMITS = [
        [600, 5],     // 5 per 10 minutes
        [86400, 20],  // 20 per day
    ];

    private const MIN_THINK_SECONDS = 2;
    private const MAX_CHALLENGE_AGE = 3600; // 1 hour to fill the form

    private readonly BugReportRateLimitStore $reports;

    public function __construct(?BugReportRateLimitStore $reports = null)
    {
        $this->reports = $reports ?? new \App\Repositories\BugReportRepository();
    }

    /**
     * Issue a signed challenge token embedded in the form when it opens. Format:
     * base64url("<issuedAt>.<hmac(issuedAt)>"). Stateless, so it needs no storage.
     */
    public function issueChallenge(): array
    {
        $issuedAt = time();
        $token = $this->encodeChallenge($issuedAt);

        return [
            'challenge' => $token,
            'min_seconds' => self::MIN_THINK_SECONDS,
        ];
    }

    /**
     * Validate a submission. Returns a normalised payload plus a result code.
     *
     * @return array{result: string, status: int, message: string, data: array<string, mixed>}
     */
    public function inspect(Request $request, array $payload): array
    {
        // 1. Honeypot: any value in the decoy field means "bot". Accept silently so the
        //    bot gets a 200 and no signal, but we store nothing.
        $honeypot = trim((string) ($payload['website'] ?? $payload['url_confirm'] ?? ''));
        if ($honeypot !== '') {
            return $this->result(self::RESULT_HONEYPOT, 200, 'Thanks for your report.');
        }

        // 2. Signed challenge / timing gate.
        $challenge = (string) ($payload['challenge'] ?? '');
        $challengeError = $this->verifyChallenge($challenge);
        if ($challengeError !== null) {
            return $this->reject(422, $challengeError);
        }

        // 3. Rate limit by hashed IP.
        $ipHash = $this->hashIp($request->clientIp());
        foreach (self::RATE_LIMITS as [$windowSeconds, $maxCount]) {
            $since = date('Y-m-d H:i:s', time() - $windowSeconds);
            if ($this->reports->countRecentByIpHash($ipHash, $since) >= $maxCount) {
                return $this->reject(429, 'Too many reports from this connection. Please try again later.');
            }
        }

        // 4. Strict field validation.
        $summary = $this->clean((string) ($payload['summary'] ?? ''));
        $description = $this->clean((string) ($payload['description'] ?? ''), true);
        $contact = $this->clean((string) ($payload['contact'] ?? ''));
        $gameVersion = $this->clean((string) ($payload['game_version'] ?? ''));
        $pageUrl = $this->clean((string) ($payload['page_url'] ?? ''));

        if ($summary === '') {
            return $this->reject(422, 'A short summary is required.');
        }
        if (mb_strlen($summary) > self::SUMMARY_MAX) {
            return $this->reject(422, 'Summary is too long.');
        }
        if (mb_strlen($description) < self::DESCRIPTION_MIN) {
            return $this->reject(422, 'Please describe the bug in a little more detail.');
        }
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            return $this->reject(422, 'Description is too long.');
        }
        if ($contact !== '' && mb_strlen($contact) > self::CONTACT_MAX) {
            return $this->reject(422, 'Contact is too long.');
        }
        if ($contact !== '' && !$this->looksLikeContact($contact)) {
            return $this->reject(422, 'Contact must be an email or short handle.');
        }

        $slug = strtolower(trim((string) ($payload['project_slug'] ?? '')));
        if (!preg_match('/^rust_[a-z0-9_]{1,110}$/', $slug)) {
            return $this->reject(422, 'Invalid game reference.');
        }

        $userAgent = $this->clean((string) ($request->getHeader('User-Agent') ?? ''));

        return [
            'result' => self::RESULT_OK,
            'status' => 201,
            'message' => 'Thanks for your report.',
            'data' => [
                'project_slug' => $slug,
                'summary' => $summary,
                'description' => $description,
                'contact' => $contact !== '' ? mb_substr($contact, 0, self::CONTACT_MAX) : null,
                'game_version' => $gameVersion !== '' ? mb_substr($gameVersion, 0, self::VERSION_MAX) : null,
                'page_url' => $pageUrl !== '' ? mb_substr($pageUrl, 0, self::URL_MAX) : null,
                'user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, self::USER_AGENT_MAX) : null,
                'ip_hash' => $ipHash,
            ],
        ];
    }

    private function verifyChallenge(string $token): ?string
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false || !str_contains($decoded, '.')) {
            return 'Your session expired. Please reopen the form and try again.';
        }

        [$issuedAtRaw, $signature] = explode('.', $decoded, 2);
        if (!ctype_digit($issuedAtRaw)) {
            return 'Your session expired. Please reopen the form and try again.';
        }

        $issuedAt = (int) $issuedAtRaw;
        $expected = $this->signChallenge($issuedAt);
        if (!hash_equals($expected, $signature)) {
            return 'Your session expired. Please reopen the form and try again.';
        }

        $age = time() - $issuedAt;
        if ($age < self::MIN_THINK_SECONDS) {
            return 'That was a little too fast — please try again.';
        }
        if ($age > self::MAX_CHALLENGE_AGE) {
            return 'Your session expired. Please reopen the form and try again.';
        }

        return null;
    }

    private function encodeChallenge(int $issuedAt): string
    {
        $raw = $issuedAt . '.' . $this->signChallenge($issuedAt);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function signChallenge(int $issuedAt): string
    {
        return hash_hmac('sha256', 'roost-bug-report|' . $issuedAt, $this->challengeSecret());
    }

    private function challengeSecret(): string
    {
        // Prefer a dedicated secret; fall back to the JWT secret so the endpoint still
        // works on deployments that have not added the new variable yet.
        return Env::optional('BUG_REPORT_NONCE_SECRET') ?: Env::required('JWT_SECRET');
    }

    private function hashIp(string $ip): string
    {
        if ($ip === '') {
            $ip = 'unknown';
        }

        $salt = Env::optional('BUG_REPORT_IP_SALT') ?: Env::required('JWT_SECRET');

        return hash('sha256', $salt . '|' . $ip);
    }

    private function clean(string $value, bool $keepNewlines = false): string
    {
        // Drop control characters (optionally keep newlines/tabs for the description),
        // normalise whitespace, and trim. Purely storage hygiene — not a security border.
        $pattern = $keepNewlines ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
        $value = (string) preg_replace($pattern, '', $value);

        if ($keepNewlines) {
            $value = (string) preg_replace('/\r\n?/', "\n", $value);
            $value = (string) preg_replace('/\n{3,}/', "\n\n", $value);
            $value = (string) preg_replace('/[ \t]{2,}/', ' ', $value);
        } else {
            $value = (string) preg_replace('/\s+/', ' ', $value);
        }

        return trim($value);
    }

    private function looksLikeContact(string $contact): bool
    {
        if (filter_var($contact, FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }

        // Otherwise accept a short handle (letters, numbers, and a few safe symbols),
        // with no whitespace or angle brackets.
        return (bool) preg_match('/^[A-Za-z0-9._@#\/+-]{2,}$/', $contact);
    }

    private function result(string $code, int $status, string $message): array
    {
        return ['result' => $code, 'status' => $status, 'message' => $message, 'data' => []];
    }

    private function reject(int $status, string $message): array
    {
        return ['result' => 'rejected', 'status' => $status, 'message' => $message, 'data' => []];
    }
}
