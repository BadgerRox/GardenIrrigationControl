<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

interface DebugLoggerInterface
{
    public function debug(string $context, string $message): void;
}
