<?php

declare(strict_types=1);

namespace Peoft\Integrations\Mailer;

use Peoft\Core\Db\Connection;
use Peoft\Core\Env;

defined('ABSPATH') || exit;

/**
 * Reads templates from `peoft_email_templates`, env-scoped.
 *
 * Request-scoped cache so multiple emails rendered in one worker tick only
 * hit the DB once per template slug.
 */
final class TemplateRepository
{
    /** @var array<string, EmailTemplate|null> slug => template (null = cached miss) */
    private array $cache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly Env $env,
    ) {}

    public function find(string $slug): ?EmailTemplate
    {
        if (array_key_exists($slug, $this->cache)) {
            return $this->cache[$slug];
        }

        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_email_templates');
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT slug, subject, body, variables_json FROM `{$table}` WHERE env = %s AND slug = %s LIMIT 1",
                $this->env->value,
                $slug
            ),
            ARRAY_A
        );

        if ($row === null) {
            $this->cache[$slug] = null;
            return null;
        }

        $declared = [];
        if (is_string($row['variables_json']) && $row['variables_json'] !== '') {
            $decoded = json_decode($row['variables_json'], true);
            if (is_array($decoded)) {
                $declared = array_values(array_filter($decoded, 'is_string'));
            }
        }

        $template = new EmailTemplate(
            slug:      (string) $row['slug'],
            subject:   (string) $row['subject'],
            body:      (string) $row['body'],
            declared:  $declared,
        );
        $this->cache[$slug] = $template;
        return $template;
    }

    public function mustFind(string $slug): EmailTemplate
    {
        $t = $this->find($slug);
        if ($t === null) {
            throw new \RuntimeException(
                "Email template not found: slug='{$slug}' env='{$this->env->value}'"
            );
        }
        return $t;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
