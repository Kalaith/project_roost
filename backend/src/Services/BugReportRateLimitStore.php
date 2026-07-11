<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The single storage capability BugReportGuard needs for rate limiting. Kept as a
 * narrow interface so the guard can be unit-tested without a database connection.
 */
interface BugReportRateLimitStore
{
    public function countRecentByIpHash(string $ipHash, string $since): int;
}
