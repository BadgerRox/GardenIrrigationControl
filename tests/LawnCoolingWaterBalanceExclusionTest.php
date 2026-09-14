<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\LawnCoolingContracts;
use PHPUnit\Framework\TestCase;

final class LawnCoolingWaterBalanceExclusionTest extends TestCase
{
    public function testRuntimeTickDoesNotWriteWaterBalanceOrAutoIrrigationDate(): void
    {
        $module = new class(1001) extends GardenIrrigationControl {
            /** @var array<string, mixed> */
            public array $attributes = [
                'WaterBalanceManager' => '{"123":{"2026-09-08":{"irrigationMm":1.5}}}',
                'WaterStorageAnchor'  => '{"123":{"lastAutoIrrigationDate":"2026-09-07","storage":-2.0}}',
            ];

            /** @var array<string, string> */
            public array $buffers = [
                LawnCoolingContracts::BUFFER_KEY_PREPARED_ZONES     => '[{"Name":"Cooling","Sequence":1,"ValveVarID":123}]',
                LawnCoolingContracts::BUFFER_KEY_CURRENT_ZONE_INDEX => '0',
                LawnCoolingContracts::BUFFER_KEY_ZONE_STARTED_AT    => '',
            ];

            /** @var array<string, string> */
            public array $attributeWrites = [];

            public function runRuntimeTick(): void
            {
                $this->HandleLawnCoolingRuntimeTick();
            }

            protected function IsLawnCoolingRunActive(): bool
            {
                return true;
            }

            protected function ReadPropertyInteger(string $name): int
            {
                return $name === 'CoolingTime' ? 1 : 0;
            }

            protected function ReadAttributeString(string $name): string
            {
                return (string) ($this->attributes[$name] ?? '');
            }

            protected function WriteAttributeString(string $name, string $value): bool
            {
                $this->attributeWrites[$name] = $value;

                return true;
            }

            protected function ReadBufferedValue(string $key): string
            {
                return $this->buffers[$key] ?? '';
            }

            protected function SetBuffer(string $name, string $data): bool
            {
                $this->buffers[$name] = $data;

                return true;
            }

            protected function SetLawnCoolingFirstValveOpened(bool $opened): void
            {
            }

            protected function SetLawnCoolingRunState(string $state, string $reason = ''): void
            {
            }

            protected function syncLawnCoolingSkipActionAvailability(): void
            {
            }

            protected function recheckLawnCoolingRuntimeGlobalPreconditions(string $context): array
            {
                return ['allowed' => true, 'reason' => '', 'debugMessage' => 'ok'];
            }

            protected function evaluateLawnCoolingSignalCheckpoint(string $context, bool $firstValveOpened = false): array
            {
                return ['allowed' => true, 'hardStop' => false, 'reason' => '', 'debugMessage' => 'ok'];
            }

            protected function evaluateLawnCoolingZoneSoilStart(array $zone, string $context): array
            {
                return ['allowed' => true, 'skipZone' => false, 'source' => 'none', 'measuredSoil' => null, 'reason' => '', 'debugMessage' => 'ok'];
            }

            protected function OpenSingleValve(int $valveId): bool
            {
                return true;
            }

            protected function CloseAllValves(): bool
            {
                return true;
            }

            protected function scheduleLawnCoolingRuntimeTick(): void
            {
            }

            protected function stopLawnCoolingRuntimeTick(): void
            {
            }

            protected function SendDebug(string $message, string $data, int $format): bool
            {
                return true;
            }
        };

        $waterBalanceBefore = $module->attributes['WaterBalanceManager'];
        $waterStorageBefore = $module->attributes['WaterStorageAnchor'];

        $module->runRuntimeTick();

        self::assertSame($waterBalanceBefore, $module->attributes['WaterBalanceManager']);
        self::assertSame($waterStorageBefore, $module->attributes['WaterStorageAnchor']);
        self::assertArrayNotHasKey('WaterBalanceManager', $module->attributeWrites);
        self::assertArrayNotHasKey('WaterStorageAnchor', $module->attributeWrites);
        self::assertNotSame('', $module->buffers[LawnCoolingContracts::BUFFER_KEY_ZONE_STARTED_AT]);
    }
}
