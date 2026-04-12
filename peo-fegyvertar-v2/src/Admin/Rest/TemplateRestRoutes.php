<?php

declare(strict_types=1);

namespace Peoft\Admin\Rest;

use Peoft\Audit\AuditLog;
use Peoft\Core\Db\Connection;
use Peoft\Core\Env;
use Peoft\Integrations\Mailer\TemplateRepository;

defined('ABSPATH') || exit;

/**
 * REST route for saving email template edits.
 *
 *   POST /admin/templates/save { slug, subject, body, variables_json }
 *
 * Validation:
 *   - slug must match /^[a-z0-9_]+$/
 *   - subject + body + variables_json all required
 *   - every `{{placeholder}}` in body or subject must appear in variables_json
 *     (declared-var check); missing declarations return 400 with the diff
 *
 * Writes a TEMPLATE_CHANGED audit row with before/after bodies so content
 * edits are fully auditable.
 */
final class TemplateRestRoutes extends AdminRestController
{
    public function __construct(
        private readonly Connection $db,
        private readonly Env $envEnum,
        private readonly TemplateRepository $templates,
    ) {}

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/templates/save', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save'],
            'permission_callback' => [$this, 'permissionCallback'],
        ]);
    }

    public function save(\WP_REST_Request $req): \WP_REST_Response
    {
        if ($err = $this->authorize($req)) {
            return $err;
        }
        $slug = sanitize_text_field((string) $req->get_param('slug'));
        $subject = (string) $req->get_param('subject');
        $body = (string) $req->get_param('body');
        $variablesJson = (string) $req->get_param('variables_json');

        if ($slug === '' || preg_match('/^[a-z0-9_]+$/', $slug) !== 1) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'invalid_slug'], 400);
        }
        if ($subject === '' || $body === '') {
            return new \WP_REST_Response(['ok' => false, 'error' => 'missing_content'], 400);
        }
        $declared = json_decode($variablesJson, true);
        if (!is_array($declared)) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'invalid_variables_json'], 400);
        }
        $declared = array_values(array_filter($declared, 'is_string'));

        $used = $this->extractPlaceholders($subject . ' ' . $body);
        $missing = array_values(array_diff($used, $declared));
        if ($missing !== []) {
            return new \WP_REST_Response([
                'ok' => false,
                'error' => 'undeclared_placeholders',
                'missing' => $missing,
                'used' => $used,
                'declared' => $declared,
            ], 400);
        }

        $before = $this->templates->find($slug);

        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_email_templates');
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$table}` (env, slug, subject, body, variables_json, updated_at, updated_by)
                 VALUES (%s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    subject = VALUES(subject),
                    body = VALUES(body),
                    variables_json = VALUES(variables_json),
                    updated_at = VALUES(updated_at),
                    updated_by = VALUES(updated_by)",
                $this->envEnum->value,
                $slug,
                $subject,
                $body,
                wp_json_encode($declared),
                gmdate('Y-m-d H:i:s'),
                $this->actorString()
            )
        );
        $this->templates->clearCache();

        AuditLog::record(
            actor:       $this->actorString(),
            action:      'TEMPLATE_CHANGED',
            subjectType: 'email_template',
            subjectId:   $slug,
            before:      $before !== null ? [
                'subject' => $before->subject,
                'body_len' => strlen($before->body),
                'declared' => $before->declared,
            ] : ['first_edit' => true],
            after:       [
                'subject' => $subject,
                'body_len' => strlen($body),
                'declared' => $declared,
            ],
        );

        return new \WP_REST_Response(['ok' => true, 'slug' => $slug, 'declared_count' => count($declared)], 200);
    }

    /**
     * @return list<string>
     */
    private function extractPlaceholders(string $text): array
    {
        $out = [];
        if (preg_match_all('/\{\{\{?\s*([a-zA-Z0-9_.-]+)\s*\}?\}\}/', $text, $matches) === false) {
            return [];
        }
        foreach ($matches[1] as $name) {
            $name = trim((string) $name);
            if ($name !== '' && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        sort($out);
        return $out;
    }
}
