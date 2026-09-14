<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

/**
 * Adapter that routes DebugLoggerInterface calls to IP-Symcon SendDebug.
 */
class ModuleLogger implements DebugLoggerInterface
{
    /** @var \Closure(string, string): void */
    private \Closure $debugCallback;

    /**
     * @param callable $debugCallback Callable that sends debug output to Symcon.
     */
    public function __construct(callable $debugCallback)
    {
        $this->debugCallback = \Closure::fromCallable($debugCallback);
    }

    /**
     * Sends the debug message to the configured Symcon debug adapter.
     */
    public function debug(string $context, string $message): void
    {
        ($this->debugCallback)($context, $message);
    }
}
