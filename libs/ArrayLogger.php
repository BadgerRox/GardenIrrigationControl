<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

/**
 * In-memory logger implementation for unit tests and internal inspection.
 *
 * This logger collects debug entries in an array instead of writing to Symcon.
 */
class ArrayLogger implements DebugLoggerInterface
{
    /** @var array<int, array{context: string, message: string}> */
    private array $logs = [];

    /**
     * Records a debug message in the internal log buffer.
     */
    public function debug(string $context, string $message): void
    {
        $this->logs[] = ['context' => $context, 'message' => $message];
    }

    /**
     * @return array<int, array{context: string, message: string}>
     */
    public function getLogs(): array
    {
        return $this->logs;
    }
}
