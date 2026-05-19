<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\Request;
use App\Exceptions\AuthException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class AuthService
{
    public function requireAdmin(Request $request): array
    {
        $claims = $this->requireClaims($request);

        if (!$this->isAdmin($claims)) {
            throw new AuthException('Admin access is required.', 403);
        }

        return $claims;
    }

    public function requireAdminOrPublishToken(Request $request): array
    {
        $publishToken = trim((string) ($request->getHeader('X-Project-Roost-Publish-Token') ?? ''));
        if ($publishToken !== '' && hash_equals(Env::required('PUBLISH_EVENT_TOKEN'), $publishToken)) {
            return [
                'sub' => 'publish-script',
                'role' => 'admin',
                'auth_type' => 'publish-token',
            ];
        }

        return $this->requireAdmin($request);
    }

    private function requireClaims(Request $request): array
    {
        $authorization = $request->getHeader('Authorization') ?? '';

        if (!preg_match('/Bearer\s+(.+)$/i', $authorization, $matches)) {
            throw new AuthException('Authentication is required.', 401, [
                'login_url' => Env::required('WEBHATCHERY_LOGIN_URL'),
            ]);
        }

        try {
            $decoded = JWT::decode($matches[1], new Key(Env::required('JWT_SECRET'), 'HS256'));
        } catch (\Throwable) {
            throw new AuthException('Invalid or expired token.', 401, [
                'login_url' => Env::required('WEBHATCHERY_LOGIN_URL'),
            ]);
        }

        return json_decode((string) json_encode($decoded), true) ?: [];
    }

    private function isAdmin(array $claims): bool
    {
        if (($claims['is_admin'] ?? false) === true) {
            return true;
        }

        $role = strtolower((string) ($claims['role'] ?? ''));
        if ($role === 'admin') {
            return true;
        }

        $roles = $claims['roles'] ?? [];
        if (!is_array($roles)) {
            return false;
        }

        foreach ($roles as $candidate) {
            if (strtolower((string) $candidate) === 'admin') {
                return true;
            }
        }

        return false;
    }
}
