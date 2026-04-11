<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Routing;

use Peoft\Core\Env;
use Peoft\Orchestrator\Queue\TaskSpec;
use Stripe\Event as StripeEvent;

defined('ABSPATH') || exit;

/**
 * Translates a Stripe Event into a list of TaskSpecs that the WebhookController
 * then enqueues.
 *
 * Events not present in StripeEventMap return an empty list. The webhook is
 * still deduped and acked; the absence of a mapping is an explicit "no
 * downstream action for this event".
 */
final class EventRouter
{
    /** @var array<string, callable(StripeEvent, Env): list<TaskSpec>> */
    private readonly array $map;

    public function __construct(
        private readonly Env $env,
    ) {
        $this->map = StripeEventMap::build();
    }

    /**
     * @return list<TaskSpec>
     */
    public function routeStripe(StripeEvent $event): array
    {
        $type = $event->type ?? '';
        if (!isset($this->map[$type])) {
            return [];
        }
        return ($this->map[$type])($event, $this->env);
    }

    /**
     * @return list<string>
     */
    public function supportedEventTypes(): array
    {
        return array_keys($this->map);
    }
}
