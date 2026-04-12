<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Handlers;

use Peoft\Integrations\Stripe\StripeClient;
use Peoft\Orchestrator\Queue\Task;
use Peoft\Orchestrator\Worker\PoisonException;
use Peoft\Orchestrator\Worker\RetryableException;

defined('ABSPATH') || exit;

/**
 * `trial.convert_to_subscription` — replaces 1.0's blocking `while+sleep`
 * polling loop with a retry-scheduled task.
 *
 * Background: in 1.0 the trial was implemented as a one-time payment
 * (not a Stripe subscription with trial_period_days). A `process-payment`
 * handler polled `PEOFT_PAYMENTS.status='finished'` every 10 seconds
 * inside the webhook request, eventually creating the real subscription.
 * This was the source of blocking sleeps, timeouts, and failure modes.
 *
 * Phase C5 ports the *mechanic* as a scheduled task, without actually
 * doing the conversion. The handler:
 *
 *   1. Fetches the Stripe checkout session referenced in the payload.
 *   2. Checks whether a paid Stripe subscription already exists on the
 *      customer for the trial's upgrade price (payload.target_price_id).
 *   3. If yes → task done.
 *   4. If no → throws RetryableException. Backoff re-schedules the task
 *      at increasing intervals (1m → 5m → 15m → 1h → 6h → 24h → dead).
 *
 * **Not implemented in C5**: the actual subscription creation path.
 * Per plan §17 item 8 (open decisions), the trial flow is queued for
 * a post-cutover product-side investigation: can the trial be a real
 * Stripe subscription with `trial_period_days` instead? If yes, this
 * handler and its task type disappear. If no, we port the real
 * conversion logic from 1.0 `stripe-webhook.php` in a follow-up.
 *
 * For now, this handler ships as a **structural placeholder** that exercises
 * the retry-scheduling mechanic and proves the Backoff infrastructure works
 * for polling-shaped tasks.
 */
final class TrialConvertToSubscriptionHandler implements TaskHandler
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public static function type(): string
    {
        return 'trial.convert_to_subscription';
    }

    public function loadContext(Task $task): TaskContext
    {
        $payload = $task->payload ?? [];
        $sessionId = (string) ($payload['checkout_session_id'] ?? '');
        if ($sessionId === '') {
            throw new PoisonException("Task #{$task->id} trial.convert_to_subscription: missing checkout_session_id");
        }

        $session = $this->stripe->fetchCheckoutSessionRaw($sessionId);
        if ($session === null) {
            // Session doesn't exist at Stripe (or is deleted). Structural
            // failure — retrying won't fix it.
            throw new PoisonException("Stripe checkout session not found: {$sessionId}");
        }

        return new TaskContext($task, [
            'checkout_session_id' => $sessionId,
            'customer_id'         => (string) ($session['customer'] ?? ''),
            'subscription_id'     => (string) ($session['subscription'] ?? ''),
            'payment_status'      => (string) ($session['payment_status'] ?? ''),
            'target_price_id'     => isset($payload['target_price_id']) ? (string) $payload['target_price_id'] : null,
        ]);
    }

    public function guard(TaskContext $context): bool
    {
        // If the session already has a subscription attached, the trial has
        // been converted. Short-circuit to done without any further action.
        return (string) $context->get('subscription_id') !== '';
    }

    public function execute(TaskContext $context): void
    {
        // Structural placeholder for Phase C5. See class docblock.
        //
        // Real C5-future implementation will:
        //   1. Check whether the customer already has an active subscription
        //      for target_price_id via StripeClient::fetchCustomerBundle
        //   2. If yes → done
        //   3. If no → create the subscription via
        //      StripeClient::createSubscriptionForPaidTrial (not yet on the
        //      interface) and record the subscription id in an audit row
        //
        // For now we throw RetryableException so the Backoff mechanic runs.
        // The task will retry 1m → 5m → 15m → 1h → 6h → 24h → dead.
        // When the mechanic is verified and the real logic ships, the throw
        // is replaced with the subscription-creation call.
        throw new RetryableException(
            'trial.convert_to_subscription: structural placeholder — retries until real conversion logic lands'
        );
    }
}
