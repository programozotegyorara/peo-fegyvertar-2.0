<?php

declare(strict_types=1);

namespace Peoft\Core;

defined('ABSPATH') || exit;

final class Container
{
    /** @var array<string, \Closure> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    public function bind(string $abstract, \Closure $factory): void
    {
        $this->factories[$abstract] = $factory;
        unset($this->instances[$abstract]);
    }

    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * @template T of object
     * @param class-string<T>|string $abstract
     * @return T|object
     */
    public function get(string $abstract): object
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }
        if (!isset($this->factories[$abstract])) {
            throw new \RuntimeException("Container: no binding for '{$abstract}'.");
        }
        $instance = ($this->factories[$abstract])($this);
        $this->instances[$abstract] = $instance;
        return $instance;
    }

    public function has(string $abstract): bool
    {
        return isset($this->factories[$abstract]) || isset($this->instances[$abstract]);
    }
}
