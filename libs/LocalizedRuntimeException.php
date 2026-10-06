<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

use RuntimeException;

final class LocalizedRuntimeException extends RuntimeException
{
    /**
     * @param array<int, scalar|null> $parameters
     */
    public function __construct(
        public readonly string $translationKey,
        public readonly array $parameters = []
    ) {
        parent::__construct($translationKey);
    }
}
