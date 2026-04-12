<?php

declare(strict_types=1);

namespace Peoft\Integrations\Szamlazz;

defined('ABSPATH') || exit;

/**
 * Immutable view of one `peoft_szamlazz_xref` row.
 */
final class XrefEntry
{
    public function __construct(
        public readonly string $stripeRef,
        public readonly string $refKind,           // 'invoice' | 'storno'
        public readonly string $szamlazzDocumentNumber,
        public readonly ?string $linkedOriginal,   // for storno: the original invoice's document number
        public readonly string $createdAt,
    ) {}
}
