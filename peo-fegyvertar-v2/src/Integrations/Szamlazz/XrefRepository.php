<?php

declare(strict_types=1);

namespace Peoft\Integrations\Szamlazz;

use Peoft\Core\Db\Connection;
use Peoft\Core\Env;

defined('ABSPATH') || exit;

/**
 * All access to `peoft_szamlazz_xref` lives here.
 *
 * This table is the orchestrator's **hard idempotency oracle** for Számlázz
 * writes, independent of the nightly DWH cache. Write once on successful
 * invoice/storno creation; read it before every retry to short-circuit
 * duplicates.
 *
 * Primary key: (env, stripe_ref, ref_kind).
 *   - ref_kind='invoice' → stripe_ref is a Stripe invoice id (in_xxx)
 *   - ref_kind='storno'  → stripe_ref is a Stripe charge id  (ch_xxx),
 *                          and linked_original holds the original
 *                          Számlázz document number being reversed
 */
final class XrefRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Env $env,
    ) {}

    public function findInvoice(string $stripeInvoiceId): ?XrefEntry
    {
        return $this->find($stripeInvoiceId, 'invoice');
    }

    public function findStorno(string $stripeChargeId): ?XrefEntry
    {
        return $this->find($stripeChargeId, 'storno');
    }

    public function recordInvoice(string $stripeInvoiceId, string $szamlazzDocumentNumber): void
    {
        $this->record($stripeInvoiceId, 'invoice', $szamlazzDocumentNumber, linkedOriginal: null);
    }

    public function recordStorno(string $stripeChargeId, string $szamlazzStornoDocumentNumber, string $linkedOriginal): void
    {
        $this->record($stripeChargeId, 'storno', $szamlazzStornoDocumentNumber, linkedOriginal: $linkedOriginal);
    }

    private function find(string $stripeRef, string $refKind): ?XrefEntry
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_szamlazz_xref');
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT stripe_ref, ref_kind, szamlazz_document_number, linked_original, created_at
                   FROM `{$table}`
                  WHERE env = %s AND stripe_ref = %s AND ref_kind = %s
                  LIMIT 1",
                $this->env->value,
                $stripeRef,
                $refKind
            ),
            ARRAY_A
        );
        if ($row === null) {
            return null;
        }
        return new XrefEntry(
            stripeRef:              (string) $row['stripe_ref'],
            refKind:                (string) $row['ref_kind'],
            szamlazzDocumentNumber: (string) $row['szamlazz_document_number'],
            linkedOriginal:         $row['linked_original'] !== null ? (string) $row['linked_original'] : null,
            createdAt:              (string) $row['created_at'],
        );
    }

    private function record(string $stripeRef, string $refKind, string $szamlazzDocumentNumber, ?string $linkedOriginal): void
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_szamlazz_xref');
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$table}` (env, stripe_ref, ref_kind, szamlazz_document_number, linked_original, created_at)
                 VALUES (%s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    szamlazz_document_number = VALUES(szamlazz_document_number),
                    linked_original = VALUES(linked_original)",
                $this->env->value,
                $stripeRef,
                $refKind,
                $szamlazzDocumentNumber,
                $linkedOriginal,
                gmdate('Y-m-d H:i:s')
            )
        );
    }
}
