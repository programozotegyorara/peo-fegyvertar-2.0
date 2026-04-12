<?php

declare(strict_types=1);

namespace Peoft\Dwh\Etl;

use Peoft\Core\Db\Connection;

defined('ABSPATH') || exit;

/**
 * Syncs Számlázz invoice data into `peoft_dwh_szamlazz_invoices`.
 *
 * In 1.0 this was `dwh-etl-szamlazz.php` (~172 lines) which called the
 * Számlázz API's invoice-list-by-date endpoint and upserted results.
 *
 * Phase E ships a **structural skeleton** that:
 *   - Declares the expected interface
 *   - Returns 0 in mock/DEV mode (no real Számlázz API calls from local dev)
 *   - In live mode, will be wired to the real Számlázz query endpoint
 *     once we have a demo/real account set up (Phase G follow-up)
 *
 * The peoft_szamlazz_xref table (C4) provides task-level idempotency;
 * this DWH table is for reporting-layer aggregates, not orchestration.
 */
final class SzamlazzExtractor
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @return int rows upserted
     */
    public function run(): int
    {
        // Phase E skeleton: no real Számlázz API call.
        // In live mode (UAT/PROD), this method will paginate through the
        // Számlázz invoice-list API filtered by date, upsert each row into
        // peoft_dwh_szamlazz_invoices, and return the count.
        // For now, return 0 — the table stays empty until the real
        // extractor logic lands in Phase G.
        return 0;
    }
}
