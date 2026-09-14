<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

use Exception;

class LocalizedException extends Exception
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
