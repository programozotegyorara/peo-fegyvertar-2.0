<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Handlers;

use Peoft\Integrations\Mailer\Mailer;
use Peoft\Integrations\Mailer\TemplateRepository;
use Peoft\Integrations\Stripe\CustomerBundle;
use Peoft\Integrations\Stripe\CustomerContextLoader;
use Peoft\Orchestrator\Queue\Task;
use Peoft\Orchestrator\Worker\PoisonException;

defined('ABSPATH') || exit;

/**
 * Sends one transactional email.
 *
 * A single handler class powers every `email.*` task_type. The concrete
 * template and variable payload travel through the task row's `payload_json`:
 *
 *   payload = {
 *     "template_slug": "sikeres_rendeles",
 *     "to": "customer@example.com",
 *     "reply_to": null | "ops@fegyvertar.hu",
 *     "vars": { customer_email: ..., order_id: ..., ... }
 *   }
 *
 * StripeEventMap closures (and admin manual-trigger forms in Phase D) are
 * responsible for extracting vars from the Stripe event into `payload.vars`.
 *
 * **Variable shape contract**: this handler reads the template's declared
 * variable list (via TemplateRepository), then fills every declared name
 * with either (a) the value from `payload.vars` if present, or (b) an empty
 * string. This keeps the TemplateRenderer strict (no silent missing vars)
 * while allowing phased rollout — Phase C1 provides what Stripe gives us
 * directly, later sub-phases (C4 Számlázz, C5 Stripe customer bundle) fill
 * in more fields over time.
 *
 * Idempotency: NOT guarded. A worker crash between SMTP success and markDone
 * can cause a duplicate send on retry. Documented in plan §5 (task registry,
 * email.* rows have "Guard: none (accept rare double-send)").
 */
final class SendTransactionalEmailHandler implements TaskHandler
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly TemplateRepository $templates,
        private readonly CustomerContextLoader $customers,
    ) {}

    public static function type(): string
    {
        return 'email.send_success_purchase';
    }

    public function loadContext(Task $task): TaskContext
    {
        $payload = $task->payload ?? [];

        $slug = (string) ($payload['template_slug'] ?? '');
        $to   = (string) ($payload['to'] ?? '');
        $replyTo = isset($payload['reply_to']) ? (string) $payload['reply_to'] : null;
        $providedVars = is_array($payload['vars'] ?? null) ? $payload['vars'] : [];
        $stripeCustomerId = isset($payload['stripe_customer_id']) ? (string) $payload['stripe_customer_id'] : '';

        if ($slug === '') {
            throw new PoisonException(
                "Task #{$task->id} missing required payload key 'template_slug'"
            );
        }
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new PoisonException(
                "Task #{$task->id} missing or invalid 'to' email address"
            );
        }

        $template = $this->templates->find($slug);
        if ($template === null) {
            throw new PoisonException("Template '{$slug}' not found in peoft_email_templates");
        }

        // Phase C5 enrichment: if the payload has a Stripe customer id,
        // try to fetch the full customer bundle and merge its fields into
        // the vars map. Failures (customer deleted, network error, mock
        // returning null) are non-fatal — we fall back to whatever the
        // payload already provided.
        $enrichedVars = $providedVars;
        if ($stripeCustomerId !== '') {
            $bundle = $this->customers->loadOrNull($stripeCustomerId);
            if ($bundle !== null) {
                $enrichedVars = $this->mergeBundleIntoVars($enrichedVars, $bundle);
            }
        }

        // Fill every declared variable. Known values come from the payload
        // (possibly enriched via Stripe); unknowns default to empty string
        // so the renderer's strict validation passes.
        $fullVars = [];
        foreach ($template->declared as $name) {
            $fullVars[$name] = array_key_exists($name, $enrichedVars)
                ? $enrichedVars[$name]
                : '';
        }

        return new TaskContext(
            task: $task,
            data: [
                'template_slug'  => $slug,
                'to'             => $to,
                'reply_to'       => $replyTo,
                'vars'           => $fullVars,
                'declared_count' => count($template->declared),
                'provided_count' => count($providedVars),
                'enriched'       => $stripeCustomerId !== '' && $enrichedVars !== $providedVars,
            ],
        );
    }

    /**
     * Maps CustomerBundle fields onto the template-variable slots that
     * sikeres_rendeles (and other transactional templates) expect.
     * Non-exhaustive — fills in ~10 of the 21 declared vars. Handler callers
     * (via the router's payload.vars) can still override any field.
     *
     * @param array<string,mixed> $vars
     * @return array<string,mixed>
     */
    private function mergeBundleIntoVars(array $vars, CustomerBundle $bundle): array
    {
        // Address block
        $vars['address']     ??= trim($bundle->address->line1 . ' ' . $bundle->address->line2);
        $vars['city']        ??= $bundle->address->city;
        $vars['country']     ??= $bundle->address->country;
        $vars['postal_code'] ??= $bundle->address->postalCode;

        // Card details (sikeres_rendeles, uj_bankkartya_hozzaadva, card-expiring)
        if ($bundle->defaultCard !== null) {
            $vars['display_brand'] ??= $bundle->defaultCard->displayBrand();
            $vars['last4']         ??= $bundle->defaultCard->last4;
            $vars['exp_month']     ??= (string) $bundle->defaultCard->expMonth;
            $vars['exp_year']      ??= (string) $bundle->defaultCard->expYear;
        }

        // Customer-facing fields
        $vars['customer_email'] ??= $bundle->email;
        // customer_profile_link: prefer the already-provided value. Future
        // enhancement will generate a Stripe Billing Portal link via
        // StripeClient::createBillingPortalSession.
        $vars['customer_profile_link'] ??= $bundle->customerPortalUrl ?? '';

        return $vars;
    }

    public function guard(TaskContext $context): bool
    {
        return false;
    }

    public function execute(TaskContext $context): void
    {
        $this->mailer->send(
            to:           (string) $context->get('to'),
            templateSlug: (string) $context->get('template_slug'),
            vars:         (array) $context->get('vars'),
            replyTo:      $context->get('reply_to'),
        );
    }
}
