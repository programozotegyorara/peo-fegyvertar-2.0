<?php

declare(strict_types=1);

namespace Peoft\Integrations\Szamlazz;

defined('ABSPATH') || exit;

/**
 * Pointer to a Számlázz invoice that has been persisted at Számlázz's side.
 *
 * Returned by SzamlazzClient::issueInvoice / issueStornoFor so handlers can
 * record the document number in peoft_szamlazz_xref and the PDF path in
 * wp-content/peoft-private/invoices/ for later admin download.
 */
final class SzamlazzInvoiceRef
{
    public function __construct(
        public readonly string $documentNumber,
        public readonly ?string $pdfPath,
    ) {}
}
