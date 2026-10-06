<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\ConfigurationValidator;
use PHPUnit\Framework\TestCase;

class ConfigurationValidatorTimeValidationTest extends TestCase
{
    public function testValidateRuntimeBudgetRejectsNonNumericIrrigationStartTime(): void
    {
        $validator = new ConfigurationValidator();

        $result = $validator->validateRuntimeBudget(
            3,
            ['hour' => 6, 'minute' => 'x', 'second' => 0],
            ['hour' => 2, 'minute' => 0, 'second' => 0],
            5.0,
            30
        );

        $this->assertFalse($result->valid);
        $this->assertSame(231, $result->errorCode);
    }

    public function testValidateCoolingConfigurationRejectsOutOfRangeCoolingStartTime(): void
    {
        $validator = new ConfigurationValidator();

        $result = $validator->validateCoolingConfiguration(
            'lawn',
            true,
            ['hour' => 25, 'minute' => 0, 'second' => 0],
            10,
            30,
            ['hour' => 6, 'minute' => 0, 'second' => 0],
            ['hour' => 1, 'minute' => 0, 'second' => 0]
        );

        $this->assertFalse($result->valid);
        $this->assertSame(240, $result->errorCode);
    }
}
