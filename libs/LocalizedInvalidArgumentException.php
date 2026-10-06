<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

use InvalidArgumentException;

final class LocalizedInvalidArgumentException extends InvalidArgumentException
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
