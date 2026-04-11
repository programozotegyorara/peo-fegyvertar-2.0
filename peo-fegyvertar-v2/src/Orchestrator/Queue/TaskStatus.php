<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Queue;

defined('ABSPATH') || exit;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done    = 'done';
    case Failed  = 'failed';
    case Dead    = 'dead';

    public function isTerminal(): bool
    {
        return $this === self::Done || $this === self::Dead;
    }

    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }
}
