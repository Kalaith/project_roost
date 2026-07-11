<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Request;
use App\Services\BugReportGuard;
use App\Services\BugReportRateLimitStore;
use PHPUnit\Framework\TestCase;

final class BugReportGuardTest extends TestCase
{
    private const SECRET = 'test-nonce-secret';

    protected function setUp(): void
    {
        $_ENV['BUG_REPORT_NONCE_SECRET'] = self::SECRET;
        $_ENV['BUG_REPORT_IP_SALT'] = 'test-ip-salt';
        $_ENV['JWT_SECRET'] = 'test-jwt-secret';
    }

    public function testHoneypotIsSilentlyAcceptedWithoutStoring(): void
    {
        $guard = $this->guard(fn () => 0);

        $verdict = $guard->inspect(new Request(), [
            'website' => 'http://spam.example',
            'summary' => 'x',
            'description' => 'y',
        ]);

        self::assertSame(BugReportGuard::RESULT_HONEYPOT, $verdict['result']);
        self::assertSame(200, $verdict['status']);
        self::assertSame([], $verdict['data']);
    }

    public function testMissingChallengeIsRejected(): void
    {
        $guard = $this->guard(fn () => 0);

        $verdict = $guard->inspect(new Request(), $this->validFields());

        self::assertSame('rejected', $verdict['result']);
        self::assertSame(422, $verdict['status']);
    }

    public function testForgedChallengeIsRejected(): void
    {
        $guard = $this->guard(fn () => 0);

        $verdict = $guard->inspect(new Request(), $this->validFields([
            'challenge' => 'not-a-real-token',
        ]));

        self::assertSame(422, $verdict['status']);
    }

    public function testSubmissionFasterThanMinimumThinkTimeIsRejected(): void
    {
        $guard = $this->guard(fn () => 0);
        $fresh = $guard->issueChallenge()['challenge'];

        $verdict = $guard->inspect(new Request(), $this->validFields([
            'challenge' => $fresh,
        ]));

        self::assertSame(422, $verdict['status']);
    }

    public function testRateLimitBlocksAfterThreshold(): void
    {
        $guard = $this->guard(fn () => 99); // pretend the IP already flooded us

        $verdict = $guard->inspect(new Request(), $this->validFields([
            'challenge' => $this->challengeAged(30),
        ]));

        self::assertSame(429, $verdict['status']);
    }

    public function testValidSubmissionIsNormalised(): void
    {
        $guard = $this->guard(fn () => 0);

        $verdict = $guard->inspect(new Request(), $this->validFields([
            'challenge' => $this->challengeAged(30),
            'summary' => "  Crash on   load\n ",
            'description' => "It crashes\r\n\r\n\r\nevery   time I press start.",
            'contact' => 'player@example.com',
        ]));

        self::assertSame(BugReportGuard::RESULT_OK, $verdict['result']);
        self::assertSame(201, $verdict['status']);
        self::assertSame('rust_ai_defense', $verdict['data']['project_slug']);
        self::assertSame('Crash on load', $verdict['data']['summary']);
        self::assertStringNotContainsString("\r", $verdict['data']['description']);
        self::assertStringNotContainsString("\n\n\n", $verdict['data']['description']);
        self::assertSame('player@example.com', $verdict['data']['contact']);
        self::assertSame(64, strlen((string) $verdict['data']['ip_hash']));
    }

    public function testInvalidSlugIsRejected(): void
    {
        $guard = $this->guard(fn () => 0);

        $verdict = $guard->inspect(new Request(), $this->validFields([
            'challenge' => $this->challengeAged(30),
            'project_slug' => 'not_a_rust_slug',
        ]));

        self::assertSame(422, $verdict['status']);
    }

    public function testOversizeSummaryIsRejected(): void
    {
        $guard = $this->guard(fn () => 0);

        $verdict = $guard->inspect(new Request(), $this->validFields([
            'challenge' => $this->challengeAged(30),
            'summary' => str_repeat('a', 200),
        ]));

        self::assertSame(422, $verdict['status']);
    }

    /**
     * @param callable(string, string): int $countStub
     */
    private function guard(callable $countStub): BugReportGuard
    {
        $repo = new class ($countStub) implements BugReportRateLimitStore {
            /** @var callable */
            private $countStub;

            public function __construct(callable $countStub)
            {
                $this->countStub = $countStub;
            }

            public function countRecentByIpHash(string $ipHash, string $since): int
            {
                return ($this->countStub)($ipHash, $since);
            }
        };

        return new BugReportGuard($repo);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validFields(array $overrides = []): array
    {
        return array_merge([
            'project_slug' => 'rust_ai_defense',
            'summary' => 'Crash on load',
            'description' => 'The game crashes every time I press start.',
        ], $overrides);
    }

    private function challengeAged(int $ageSeconds): string
    {
        $issuedAt = time() - $ageSeconds;
        $signature = hash_hmac('sha256', 'roost-bug-report|' . $issuedAt, self::SECRET);
        $raw = $issuedAt . '.' . $signature;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
