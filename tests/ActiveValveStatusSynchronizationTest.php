<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\AutoIrrigationContracts;
use GardenIrrigationControl\Libs\ValveControl;
use PHPUnit\Framework\TestCase;

class ActiveValveStatusSynchronizationTest extends TestCase
{
    protected function setUp(): void
    {
        \IPS\Kernel::reset();
    }

    public function testValidatedOpenValveIdIsWrittenToTheSharedActiveValveVariable(): void
    {
        $valveId = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);
        SetValue($valveId, true);
        $module = $this->createModule($valveId, true);

        $module->ValidateValvesAsync();

        $this->assertSame([['ActiveValve', $valveId]], $module->valueWrites);
    }

    public function testValidatedClosedStateResetsTheSharedActiveValveVariable(): void
    {
        $valveId = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);
        SetValue($valveId, false);
        $module = $this->createModule($valveId, false);

        $module->ValidateValvesAsync();

        $this->assertSame([['ActiveValve', 0]], $module->valueWrites);
    }

    private function createModule(int $valveId, bool $expectedOpen): object
    {
        $payload = json_encode([[
            'ValveID'       => $valveId,
            'Name'          => 'Test zone',
            'ExpectedValue' => $expectedOpen,
        ]], JSON_THROW_ON_ERROR);

        return new class($valveId, $payload) extends GardenIrrigationControl {
            /** @var array<string, string> */
            private array $buffers;

            /** @var array<int, array{0: string, 1: mixed}> */
            public array $valueWrites = [];

            public function __construct(
                private readonly int $valveId,
                string $pendingValidation
            ) {
                parent::__construct(24680);
                $this->buffers = [
                    AutoIrrigationContracts::BUFFER_KEY_PENDING_VALVE_VALIDATION  => $pendingValidation,
                    AutoIrrigationContracts::BUFFER_KEY_VALIDATE_RETRY_COUNT      => '0',
                ];
            }

            protected function GetBuffer(string $Name): string
            {
                return $this->buffers[$Name] ?? '';
            }

            protected function SetBuffer(string $Name, string $Data): bool
            {
                $this->buffers[$Name] = $Data;
                return true;
            }

            protected function SetTimerInterval(string $Ident, int $Milliseconds): bool
            {
                return true;
            }

            protected function getValveControl(): ValveControl
            {
                return new ValveControl();
            }

            protected function getZonesTree(): array
            {
                return [[
                    'ValveVarID' => $this->valveId,
                ]];
            }

            protected function GetExistingFrontendVariableID(string $ident): int
            {
                return $ident === 'ActiveValve' ? 12345 : 0;
            }

            protected function SetValue(string $Ident, mixed $Value): bool
            {
                $this->valueWrites[] = [$Ident, $Value];
                return true;
            }

            protected function ClearContextError(string $context): void
            {
            }
        };
    }
}
