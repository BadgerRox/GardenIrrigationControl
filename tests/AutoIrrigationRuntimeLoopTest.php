<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\AutoIrrigationRuntimeCallbacks;
use PHPUnit\Framework\TestCase;

class AutoIrrigationRuntimeLoopTest extends TestCase
{
    protected function setUp(): void
    {
        \IPS\Kernel::reset();
    }

    public function testRainDuringPauseIncludedInRequiredMm(): void
    {
        $module = new class(4001) extends GardenIrrigationControl {
            public array $properties = [];
            public array $attributes = [];
            public array $debugMessages = [];

            public function runResumeReentry(array $zone): array
            {
                return $this->BuildResumeReentryPipelineResult($zone, 'rain_pause');
            }

            public function setPropertyValue(string $name, mixed $value): void
            {
                $this->properties[$name] = $value;
            }

            protected function isModuleConfigurationInvalid(string $context): bool
            {
                return false;
            }

            protected function ReadPropertyBoolean(string $Name): bool
            {
                return (bool) ($this->properties[$Name] ?? false);
            }

            protected function ReadPropertyInteger(string $Name): int
            {
                return (int) ($this->properties[$Name] ?? 0);
            }

            protected function ReadPropertyFloat(string $Name): float
            {
                return (float) ($this->properties[$Name] ?? 0.0);
            }

            protected function ReadPropertyString(string $Name): string
            {
                return (string) ($this->properties[$Name] ?? '');
            }

            protected function ReadAttributeString(string $Name): string
            {
                return (string) ($this->attributes[$Name] ?? '[]');
            }

            protected function GetRainForecast(int $forecastHours = 12): array
            {
                return [];
            }

            protected function GetRainHistory(string $startDateTime, string $endDateTime): ?float
            {
                return 1.5;
            }

            public function GetValveConsumption(int $valveVarId, string $startDateTime, string $endDateTime): ?float
            {
                return 0.5;
            }

            protected function ReadOptionalFloatValue(int $varId): ?float
            {
                return null;
            }

            protected function SendDebug(string $Message, string $Data, int $Format): bool
            {
                $this->debugMessages[] = [$Message, $Data];

                return true;
            }
        };

        $module->setPropertyValue('RainForecast', false);
        $module->setPropertyValue('IrrigationMaxRuntime', json_encode(['hour' => 1, 'minute' => 0, 'second' => 0]));
        $module->setPropertyValue('IrrigationMinRuntime', 1.0);
        $module->setPropertyValue('IrrigationZoneMaxRuntime', 12);
        $module->setPropertyValue('IrrigationInterval', 3);
        $module->setPropertyValue('SoilMinMoisture', 40);
        $module->setPropertyValue('GlobalSoilMoistureVarID', 0);

        $module->attributes['WaterStorageAnchor'] = json_encode([
            '123' => [
                'storage' => -3.0,
            ],
        ]);

        $result = $module->runResumeReentry([
            'Name'                       => 'Zone A',
            'ValveVarID'                 => 123,
            'sprinklerPrecipitationRate' => 1.0,
            'UseGlobalSoilMoisture'      => false,
        ]);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['globalStop']);

        $storageDebug = '';
        foreach ($module->debugMessages as $entry) {
            if ($entry[0] === 'AutoIrrigationResumeReentry' && str_contains($entry[1], 'Water demand for resume re-entry')) {
                $storageDebug = $entry[1];
                break;
            }
        }

        $this->assertNotSame('', $storageDebug);
        $this->assertStringContainsString('rain=1.5000 mm', $storageDebug);
        $this->assertStringContainsString('irrigation=0.5000 mm', $storageDebug);
        $this->assertStringContainsString('required=1.0000 mm', $storageDebug);
    }

    public function testPauseResumeReentryBlockCausesLocalZoneStop(): void
    {
        $planner = new GardenIrrigationControl\Libs\AutoIrrigationZoneStartPlanner();
        $terminalActions = [];

        $callbacks = AutoIrrigationRuntimeCallbacks::withDefaults(
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason): void
            {
            },
            static fn (): array => [
                'windPauseTriggered'    => true,
                'resumeStable'          => true,
                'triggerMeanWind'       => 28.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => false,
                'globalStop'   => false,
                'reason'       => 'resume_reentry_zone_stop',
                'debugMessage' => 'blocked',
            ],
            static fn (array $zone): array => [
                'mode'                 => 0,
                'source'               => 'none',
                'measuredSoil'         => null,
                'soilMinMoisture'      => 0,
                'confirmWindowSeconds' => 60,
                'sampleCount60s'       => 0,
                'confirmWindowStable'  => false,
            ],
            static fn (array $zone): array => [
                'elapsedWateringMinutes'  => 0.0,
                'runtimeEffectiveMinutes' => null,
            ],
            static fn (): array => [
                'runStartTimestamp' => time(),
                'maxRuntimeSeconds' => 3600,
            ],
            static function (array $zone, string $reason) use (&$terminalActions): void
            {
                $terminalActions[] = [$zone['Name'] ?? 'unknown', $reason];
            }
        );

        $runner = new GardenIrrigationControl\Libs\AutoIrrigationRuntimeLoopRunner($planner, $callbacks);

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertTrue($result['continue']);
        $this->assertSame('continue', $result['outcome']);
        $this->assertSame([['Zone A', 'resume_reentry_zone_stop']], $terminalActions);
    }
}
