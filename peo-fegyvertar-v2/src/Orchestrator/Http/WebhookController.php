<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Http;

use Peoft\Audit\AuditLog;
use Peoft\Core\Config\Config;
use Peoft\Core\Db\Connection;
use Peoft\Core\Env;
use Peoft\Integrations\Stripe\StripeWebhookVerifier;
use Peoft\Orchestrator\Queue\TaskEnqueuer;
use Peoft\Orchestrator\Routing\EventRouter;

defined('ABSPATH') || exit;

/**
 * REST endpoint: POST /wp-json/peo-fegyvertar/v2/stripe-webhook
 *
 * Responsibilities (in strict order):
 *   1. Read raw body (BEFORE any WP JSON parsing).
 *   2. Verify Stripe signature. Failure → 400 + audit.
 *   3. INSERT IGNORE the event id into peoft_webhook_events. Duplicate → 200 + audit.
 *   4. Route via EventRouter into a list of TaskSpecs.
 *   5. Enqueue via TaskEnqueuer (INSERT IGNORE on idempotency_key).
 *   6. Audit WEBHOOK_RECEIVED with the enqueued task ids.
 *   7. Return 200 with a minimal JSON body.
 *
 * The entire handler is non-blocking: no downstream API calls, no sleep, no
 * external service contact. All real work happens later in the Worker tick.
 */
final class WebhookController
{
    public const NAMESPACE = 'peo-fegyvertar/v2';
    public const ROUTE     = '/stripe-webhook';

    public function __construct(
        private readonly Connection $db,
        private readonly Env $env,
        private readonly StripeWebhookVerifier $verifier,
        private readonly EventRouter $router,
        private readonly TaskEnqueuer $enqueuer,
    ) {}

    public function registerRoute(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $rawBody = (string) $request->get_body();
        $sigHeader = (string) $request->get_header('stripe_signature');

        // Correlate every audit row for this request with a fresh request_id.
        $requestId = AuditLog::requestId();

        $secret = (string) (Config::for('stripe')->get('webhook_secret') ?? '');
        if ($secret === '') {
            AuditLog::record(
                actor:  'stripe',
                action: 'WEBHOOK_SIG_FAILED',
                subjectType: 'webhook',
                error:  'stripe.webhook_secret is not configured',
            );
            return new \WP_REST_Response(['ok' => false, 'error' => 'webhook_secret_missing'], 503);
        }

        try {
            $event = $this->verifier->verify($rawBody, $sigHeader, $secret);
        } catch (\Throwable $e) {
            AuditLog::record(
                actor:  'stripe',
                action: 'WEBHOOK_SIG_FAILED',
                subjectType: 'webhook',
                before: ['stripe_signature_header_len' => strlen($sigHeader), 'body_len' => strlen($rawBody)],
                error:  substr($e->getMessage(), 0, 500),
            );
            return new \WP_REST_Response(['ok' => false, 'error' => 'invalid_signature'], 400);
        }

        $eventId = (string) $event->id;

        // Dedupe note: isDuplicate + recordEvent is a classic TOCTOU — two
        // concurrent requests for the same event_id can both pass isDuplicate,
        // both attempt to recordEvent, only one INSERT IGNORE succeeds. Both
        // then route + enqueue. The UNIQUE index on peoft_tasks.idempotency_key
        // is what prevents actual duplicate side-effects: the second enqueueMany
        // simply no-ops. The observable symptom of a race is an extra
        // WEBHOOK_RECEIVED audit row with enqueued_count=0, which is
        // information, not a correctness bug. Under `flock`-guarded worker
        // traffic (the usual case), this path effectively never races.
        if ($this->isDuplicate($eventId)) {
            AuditLog::record(
                actor:      'stripe',
                action:     'WEBHOOK_DUPLICATE',
                subjectType:'webhook_event',
                subjectId:  $eventId,
                after:      ['event_type' => $event->type],
            );
            return new \WP_REST_Response(['ok' => true, 'dedupe' => true], 200);
        }

        // Everything past this point is wrapped so an unexpected failure in
        // the router / enqueuer can't leak a stack trace to the unauthenticated
        // caller. The controller audits the failure and returns a generic 500.
        try {
            $this->recordEvent($eventId, $rawBody);

            $specs = $this->router->routeStripe($event);
            $result = $this->enqueuer->enqueueMany($specs, sourceEventId: $eventId);

            AuditLog::record(
                actor:      'stripe',
                action:     'WEBHOOK_RECEIVED',
                subjectType:'webhook_event',
                subjectId:  $eventId,
                after:      [
                    'event_type'      => $event->type,
                    'routed'          => count($specs),
                    'enqueued_ids'    => $result['ids'],
                    'enqueued_count'  => $result['inserted'],
                    'deduped_count'   => $result['skipped'],
                    'request_id'      => $requestId,
                ],
            );

            return new \WP_REST_Response([
                'ok'             => true,
                'event_id'       => $eventId,
                'event_type'     => $event->type,
                'enqueued_count' => $result['inserted'],
                'deduped_count'  => $result['skipped'],
            ], 200);
        } catch (\Throwable $e) {
            AuditLog::record(
                actor:      'stripe',
                action:     'WEBHOOK_ROUTE_FAILED',
                subjectType:'webhook_event',
                subjectId:  $eventId,
                before:     ['event_type' => $event->type ?? null],
                error:      substr($e::class . ': ' . $e->getMessage(), 0, 500),
            );
            error_log('[peoft] WebhookController routing failed: ' . $e->getMessage());
            return new \WP_REST_Response(['ok' => false, 'error' => 'internal_error'], 500);
        }
    }

    private function isDuplicate(string $eventId): bool
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_webhook_events');
        $found = $wpdb->get_var(
            $wpdb->prepare("SELECT 1 FROM `{$table}` WHERE event_id = %s LIMIT 1", $eventId)
        );
        return $found !== null;
    }

    private function recordEvent(string $eventId, string $rawBody): void
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_webhook_events');
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO `{$table}` (event_id, source, env, received_at, payload_hash) VALUES (%s, %s, %s, %s, %s)",
                $eventId,
                'stripe',
                $this->env->value,
                gmdate('Y-m-d H:i:s'),
                sha1($rawBody)
            )
        );
    }
}
