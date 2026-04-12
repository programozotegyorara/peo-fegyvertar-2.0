<?php

declare(strict_types=1);

namespace Peoft\Integrations\Szamlazz;

defined('ABSPATH') || exit;

/**
 * Writes Számlázz invoice PDFs to a **non-web-accessible** location on disk.
 *
 * Per plan §16 item 18, invoice PDFs contain PII (customer name, address,
 * VAT, tax breakdown) and MUST NOT live under `wp-content/uploads/` where
 * the web server would serve them by direct URL. We put them under
 * `wp-content/peoft-private/invoices/` and expose downloads via an
 * authenticated REST endpoint in Phase D.
 *
 * Defense in depth:
 *   - Filename regex guard (/^[A-Z0-9\-._]+$/i) blocks path traversal at
 *     write time (plan §16 item 3).
 *   - `.htaccess` with `Require all denied` + an `index.php` stub seeded on
 *     first write, in case the directory is ever accidentally exposed.
 *   - Parent dir mode 0750 so only the web user and its group can read.
 */
final class PdfStore
{
    private bool $initialized = false;

    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * Absolute path where a PDF with the given document number will be written.
     * Validates the document number for safety before concatenating.
     */
    public function pathFor(string $documentNumber): string
    {
        if (preg_match('/^[A-Z0-9._-]+$/i', $documentNumber) !== 1) {
            throw new \InvalidArgumentException("Invalid Számlázz document number for PDF path: '{$documentNumber}'");
        }
        return rtrim($this->basePath, '/') . '/' . $documentNumber . '.pdf';
    }

    /**
     * Write PDF bytes to disk. Creates the base directory with restrictive
     * permissions + the deny-all .htaccess on first use.
     */
    public function save(string $documentNumber, string $pdfContent): string
    {
        $this->ensureDirectory();
        $path = $this->pathFor($documentNumber);
        $bytes = file_put_contents($path, $pdfContent);
        if ($bytes === false) {
            throw new \RuntimeException("Failed to write PDF to '{$path}'");
        }
        @chmod($path, 0o600);
        return $path;
    }

    public function exists(string $documentNumber): bool
    {
        return is_file($this->pathFor($documentNumber));
    }

    private function ensureDirectory(): void
    {
        if ($this->initialized) {
            return;
        }
        $dir = $this->basePath;
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0o750, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create PDF directory: {$dir}");
            }
        }

        // Deny-all .htaccess (Apache 2.4 syntax). Fine to overwrite on each
        // ensureDirectory call — content is constant.
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents(
                $htaccess,
                "# Deny-all. PDFs served via authenticated admin REST endpoint only.\n"
                . "Require all denied\n"
                . "<FilesMatch \"\\.(pdf|php|phtml|phar)$\">\n"
                . "    Require all denied\n"
                . "</FilesMatch>\n"
            );
            @chmod($htaccess, 0o644);
        }

        // Empty index.php so directory listings don't reveal content, even
        // in the pathological case that the deny-all is ignored.
        $index = $dir . '/index.php';
        if (!is_file($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
            @chmod($index, 0o644);
        }

        $this->initialized = true;
    }
}
