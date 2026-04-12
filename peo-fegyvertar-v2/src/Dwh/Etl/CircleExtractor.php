<?php

declare(strict_types=1);

namespace Peoft\Dwh\Etl;

use Peoft\Core\Db\Connection;

defined('ABSPATH') || exit;

/**
 * Syncs Circle community members into `peoft_dwh_circle_members`.
 *
 * In 1.0 this was `dwh-etl-circle.php` (~188 lines). The existing extractor
 * had a known bug: it returned only ~10 rows despite the community having
 * thousands of members (plan §17 item 2 — likely pagination issue in the
 * Circle v2 API call, only processing page 1).
 *
 * Phase E ships a **structural skeleton**. The real paginated extraction
 * (using `GET /api/admin/v2/community_members` with cursor/offset pagination)
 * lands in Phase G when the Circle API pagination behavior is verified.
 *
 * In DEV mock mode this returns 0 — the table stays empty.
 */
final class CircleExtractor
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @return int rows upserted
     */
    public function run(): int
    {
        // Phase E skeleton: no Circle API call.
        // In live mode the method will paginate through all community members,
        // upsert into peoft_dwh_circle_members, and return the count.
        return 0;
    }
}
