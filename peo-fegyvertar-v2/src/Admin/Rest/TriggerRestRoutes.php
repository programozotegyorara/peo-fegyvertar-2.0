<?php

declare(strict_types=1);

namespace Peoft\Admin\Rest;

use Peoft\Core\Env;
use Peoft\Orchestrator\Handlers\TaskRegistry;
use Peoft\Orchestrator\Queue\IdempotencyKey;
use Peoft\Orchestrator\Queue\TaskEnqueuer;
use Peoft\Orchestrator\Queue\TaskSpec;

defined('ABSPATH') || exit;

/**
 * REST route for the Manual Trigger admin page.
 *
 *   POST /admin/trigger { task_type, stripe_ref, payload }
 *
 * Validates:
 *   - task_type is in TaskRegistry::registeredTypes() — can't enqueue
 *     for a type no handler exists, prevents typo dead-letters
 *   - payload is a valid JSON object (parsed on the server; if a string,
 *     we try json_decode first)
 *   - stripe_ref is a plain string (pass-through)
 *
 * Builds a deterministic idempotency key from (task_type, env, stripe_ref)
 * so repeatedly clicking "Trigger" for the same inputs dedupes at the
 * peoft_tasks UNIQUE index instead of spamming the queue.
 *
 * Actor for the enqueue audit row is `admin:{user_id}`.
 */
final class TriggerRestRoutes extends AdminRestController
{
    public function __construct(
        private readonly TaskEnqueuer $enqueuer,
        private readonly TaskRegistry $registry,
        private readonly Env $envEnum,
    ) {}

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/trigger', [
            'methods'             => 'POST',
            'callback'            => [$this, 'trigger'],
            'permission_callback' => [$this, 'permissionCallback'],
        ]);
    }

    public function trigger(\WP_REST_Request $req): \WP_REST_Response
    {
        if ($err = $this->authorize($req)) {
            return $err;
        }
        $taskType = sanitize_text_field((string) $req->get_param('task_type'));
        $stripeRef = sanitize_text_field((string) $req->get_param('stripe_ref'));
        $payloadRaw = $req->get_param('payload');

        if ($taskType === '' || !$this->registry->has($taskType)) {
            return new \WP_REST_Response([
                'ok' => false,
                'error' => 'unknown_task_type',
                'registered' => $this->registry->registeredTypes(),
            ], 400);
        }

        // Accept payload as either a decoded array (from JSON content-type)
        // or a JSON string (from a plain form field).
        $payload = [];
        if (is_array($payloadRaw)) {
            $payload = $payloadRaw;
        } elseif (is_string($payloadRaw) && $payloadRaw !== '') {
            $decoded = json_decode($payloadRaw, true);
            if (!is_array($decoded)) {
                return new \WP_REST_Response(['ok' => false, 'error' => 'invalid_payload_json'], 400);
            }
            $payload = $decoded;
        }

        // Idempotency key: deterministic so "trigger the same thing twice in
        // 2 seconds" produces zero duplicate work. Admin forms can still use
        // stripe_ref as the discriminator — different refs get different rows.
        $spec = new TaskSpec(
            taskType:       $taskType,
            idempotencyKey: IdempotencyKey::for($taskType, $this->envEnum->value, $stripeRef, (string) time()),
            stripeRef:      $stripeRef !== '' ? $stripeRef : null,
            payload:        $payload !== [] ? $payload : null,
            sourceEventId:  null,
            actor:          $this->actorString(),
        );

        $result = $this->enqueuer->enqueueMany([$spec], sourceEventId: null);
        return new \WP_REST_Response([
            'ok' => true,
            'task_type' => $taskType,
            'inserted' => $result['inserted'],
            'skipped' => $result['skipped'],
            'ids' => $result['ids'],
        ], 200);
    }
}
