<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

final class ValidationResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly int $errorCode,
        public readonly string $translationKey,
        /** @var array<int, scalar> */
        public readonly array $parameters
    ) {
    }

    public static function success(): self
    {
        return new self(true, 0, '', []);
    }

    /**
     * @param array<int, scalar> $parameters
     */
    public static function failure(int $errorCode, string $translationKey, array $parameters = []): self
    {
        return new self(false, $errorCode, $translationKey, $parameters);
    }
}
