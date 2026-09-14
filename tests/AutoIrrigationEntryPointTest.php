<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\AutoIrrigationContracts;
use PHPUnit\Framework\TestCase;

class AutoIrrigationEntryPointTest extends TestCase
{
    protected function setUp(): void
    {
        \IPS\Kernel::reset();
    }

    public function testHandleAutoIrrigationDailyCachesPreparedZonesAndTransitionsStates(): void
    {
        $module = new class(1001) extends GardenIrrigationControl {
            public array $states = [];
            public array $buffers = [];
            public array $debugMessages = [];
            public bool $timerRescheduled = false;
            public bool $configurationInvalid = false;
            public array $properties = [];
            public array $attributes = [];

            public function runDaily(): void
            {
                $this->HandleAutoIrrigationDaily();
            }

            public function setPropertyValue(string $name, mixed $value): void
            {
                $this->properties[$name] = $value;
            }

            protected function isModuleConfigurationInvalid(string $context): bool
            {
                return $this->configurationInvalid;
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

            protected function decodeJsonArray(string $json): array
            {
                $decoded = json_decode($json, true);
                return is_array($decoded) ? $decoded : [];
            }

            protected function ReadAttributeString(string $Name): string
            {
                return (string) ($this->attributes[$Name] ?? '[]');
            }

            protected function SetAutoIrrigationRunState(string $state, string $reason = ''): void
            {
                $this->states[] = [$state, $reason];
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

            protected function SendDebug(string $Message, string $Data, int $Format): bool
            {
                $this->debugMessages[] = [$Message, $Data];
                return true;
            }

            protected function scheduleAutoIrrigationDailyTimer(): void
            {
                $this->timerRescheduled = true;
            }

            protected function BuildWindPauseSnapshot(): array
            {
                return [
                    'windPauseTriggered'    => false,
                    'resumeStable'          => false,
                    'triggerMeanWind'       => 0.0,
                    'thresholdWindMaxSpeed' => 20.0,
                    'sampleCount5m'         => 0,
                    'sampleCount15m'        => 0,
                ];
            }

            protected function BuildRainPauseSnapshot(): array
            {
                return [
                    'rainPauseTriggered' => false,
                    'resumeDryStable'    => false,
                    'triggerRainSum5m'   => 0.0,
                    'thresholdRainSum5m' => 0.2,
                    'sampleCount5m'      => 0,
                    'sampleCount15m'     => 0,
                ];
            }

            public function RefreshWaterBalanceHistory(): bool
            {
                return true;
            }

            public function GetRainHistory(string $fromDateTime, string $toDateTime): ?float
            {
                return 0.0;
            }

            public function GetValveConsumption(int $zoneValveID, string $fromDateTime, string $toDateTime): ?float
            {
                return 0.0;
            }
        };

        $module->configurationInvalid = false;
        $module->setPropertyValue('SystemAutoEnable', true);
        $module->setPropertyValue('SystemMaintenanceEnable', false);
        $module->setPropertyValue('SoilMoistureMode', 0);
        $module->setPropertyValue('SoilMinMoisture', 40);
        $module->setPropertyValue('GlobalSoilMoistureVarID', 0);
        $module->setPropertyValue('ZonesTree', json_encode([
            ['Name' => 'Zone C', 'Active' => 1, 'Sequence' => 30, 'ValveVarID' => 300],
            ['Name' => 'Zone B', 'Active' => 1, 'Sequence' => 10, 'ValveVarID' => 200],
            ['Name' => 'Inactive', 'Active' => 0, 'Sequence' => 20, 'ValveVarID' => 100],
        ]));

        $module->runDaily();

        $this->assertSame(
            [
                [AutoIrrigationContracts::RUN_STATE_PRECHECK, 'Daily run started.'],
                [AutoIrrigationContracts::RUN_STATE_RUNNING, 'Daily runtime phase started.'],
                [AutoIrrigationContracts::RUN_STATE_FINISHED, 'Runtime phase completed without a stop signal.'],
            ],
            $module->states
        );
        $this->assertTrue($module->timerRescheduled);
        $this->assertSame('', $module->buffers[AutoIrrigationContracts::STOP_SIGNAL_BUFFER_KEY] ?? null);

        $this->assertSame('', $module->buffers['AutoIrrigationPreparedZones'] ?? null);
    }

    public function testHandleAutoIrrigationDailyStopsWhenStopSignalIsPendingBeforeZoneStart(): void
    {
        $module = new class(1003) extends GardenIrrigationControl {
            public array $states = [];
            public array $buffers = [];
            public bool $timerRescheduled = false;
            public array $properties = [];
            public bool $stopRequested = true;
            public array $attributes = [];
            public int $closeCalls = 0;

            public function runDaily(): void
            {
                $this->HandleAutoIrrigationDaily();
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

            protected function decodeJsonArray(string $json): array
            {
                $decoded = json_decode($json, true);
                return is_array($decoded) ? $decoded : [];
            }

            protected function ReadAttributeString(string $Name): string
            {
                return (string) ($this->attributes[$Name] ?? '[]');
            }

            protected function SetAutoIrrigationRunState(string $state, string $reason = ''): void
            {
                $this->states[] = [$state, $reason];
            }

            protected function SetBuffer(string $Name, string $Data): bool
            {
                $this->buffers[$Name] = $Data;
                return true;
            }

            protected function ClearAutoIrrigationStopRequested(): void
            {
            }

            protected function IsAutoIrrigationStopRequested(): bool
            {
                return $this->stopRequested;
            }

            protected function scheduleAutoIrrigationDailyTimer(): void
            {
                $this->timerRescheduled = true;
            }

            protected function CloseAllValves(): bool
            {
                $this->closeCalls++;
                return true;
            }

            protected function BuildWindPauseSnapshot(): array
            {
                return [
                    'windPauseTriggered'    => false,
                    'resumeStable'          => false,
                    'triggerMeanWind'       => 0.0,
                    'thresholdWindMaxSpeed' => 20.0,
                    'sampleCount5m'         => 0,
                    'sampleCount15m'        => 0,
                ];
            }

            protected function BuildRainPauseSnapshot(): array
            {
                return [
                    'rainPauseTriggered' => false,
                    'resumeDryStable'    => false,
                    'triggerRainSum5m'   => 0.0,
                    'thresholdRainSum5m' => 0.2,
                    'sampleCount5m'      => 0,
                    'sampleCount15m'     => 0,
                ];
            }
        };

        $module->setPropertyValue('SystemAutoEnable', true);
        $module->setPropertyValue('SystemMaintenanceEnable', false);
        $module->setPropertyValue('SoilMoistureMode', 0);
        $module->setPropertyValue('SoilMinMoisture', 40);
        $module->setPropertyValue('GlobalSoilMoistureVarID', 0);
        $module->setPropertyValue('ZonesTree', json_encode([
            ['Name' => 'Zone A', 'Active' => 1, 'Sequence' => 10, 'ValveVarID' => 200],
        ]));

        $module->runDaily();

        $this->assertSame(
            [
                [AutoIrrigationContracts::RUN_STATE_PRECHECK, 'Daily run started.'],
                [AutoIrrigationContracts::RUN_STATE_STOPPED, 'Stop signal detected before zone start.'],
            ],
            $module->states
        );
        $this->assertTrue($module->timerRescheduled);
        $this->assertSame('', $module->buffers['AutoIrrigationPreparedZones'] ?? null);
        $this->assertSame(1, $module->closeCalls);
    }

    public function testHandleAutoIrrigationDailyStopsOnHardValveControlStartBlocker(): void
    {
        $module = new class(1004) extends GardenIrrigationControl {
            public array $states = [];
            public array $buffers = [];
            public bool $timerRescheduled = false;
            public array $properties = [];
            public array $attributes = [];

            public function runDaily(): void
            {
                $this->HandleAutoIrrigationDaily();
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

            protected function decodeJsonArray(string $json): array
            {
                $decoded = json_decode($json, true);
                return is_array($decoded) ? $decoded : [];
            }

            protected function ReadAttributeString(string $Name): string
            {
                return (string) ($this->attributes[$Name] ?? '[]');
            }

            protected function SetAutoIrrigationRunState(string $state, string $reason = ''): void
            {
                $this->states[] = [$state, $reason];
            }

            protected function SetBuffer(string $Name, string $Data): bool
            {
                $this->buffers[$Name] = $Data;
                return true;
            }

            protected function GetBuffer(string $Name): string
            {
                return (string) ($this->buffers[$Name] ?? '');
            }

            protected function SendDebug(string $Message, string $Data, int $Format): bool
            {
                return true;
            }

            protected function scheduleAutoIrrigationDailyTimer(): void
            {
                $this->timerRescheduled = true;
            }

            protected function BuildWindPauseSnapshot(): array
            {
                return [
                    'windPauseTriggered'    => false,
                    'resumeStable'          => false,
                    'triggerMeanWind'       => 0.0,
                    'thresholdWindMaxSpeed' => 20.0,
                    'sampleCount5m'         => 0,
                    'sampleCount15m'        => 0,
                ];
            }

            protected function BuildRainPauseSnapshot(): array
            {
                return [
                    'rainPauseTriggered' => false,
                    'resumeDryStable'    => false,
                    'triggerRainSum5m'   => 0.0,
                    'thresholdRainSum5m' => 0.2,
                    'sampleCount5m'      => 0,
                    'sampleCount15m'     => 0,
                ];
            }
        };

        $module->setPropertyValue('SystemAutoEnable', true);
        $module->setPropertyValue('SystemMaintenanceEnable', false);
        $module->setPropertyValue('ZonesTree', json_encode([
            ['Name' => 'Zone A', 'Active' => 1, 'Sequence' => 10, 'ValveVarID' => 200],
        ]));
        $module->attributes['ActiveErrors'] = json_encode(['ValveControl' => 401]);
        $module->buffers['LastValveOperationType'] = 'close';
        $module->buffers['ValveValidationRetryLimitReached'] = '1';

        $module->runDaily();

        $this->assertSame(
            [
                [AutoIrrigationContracts::RUN_STATE_PRECHECK, 'Daily run started.'],
                [AutoIrrigationContracts::RUN_STATE_STOPPED, 'Valve close failure blocks the daily start.'],
            ],
            $module->states
        );
        $this->assertTrue($module->timerRescheduled);
    }

    public function testHandleAutoIrrigationDailySkipsZoneWhenSoilStartGateBlocksAtEntry(): void
    {
        $module = new class(1005) extends GardenIrrigationControl {
            public array $states = [];
            public array $buffers = [];
            public bool $timerRescheduled = false;
            public array $properties = [];
            public array $attributes = [];
            public array $debugMessages = [];
            public array $optionalFloatValues = [];

            public function runDaily(): void
            {
                $this->HandleAutoIrrigationDaily();
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

            protected function decodeJsonArray(string $json): array
            {
                $decoded = json_decode($json, true);
                return is_array($decoded) ? $decoded : [];
            }

            protected function ReadAttributeString(string $Name): string
            {
                return (string) ($this->attributes[$Name] ?? '[]');
            }

            protected function SetAutoIrrigationRunState(string $state, string $reason = ''): void
            {
                $this->states[] = [$state, $reason];
            }

            protected function SetBuffer(string $Name, string $Data): bool
            {
                $this->buffers[$Name] = $Data;
                return true;
            }

            protected function SendDebug(string $Message, string $Data, int $Format): bool
            {
                $this->debugMessages[] = [$Message, $Data];
                return true;
            }

            protected function scheduleAutoIrrigationDailyTimer(): void
            {
                $this->timerRescheduled = true;
            }

            protected function BuildWindPauseSnapshot(): array
            {
                return [
                    'windPauseTriggered'    => false,
                    'resumeStable'          => false,
                    'triggerMeanWind'       => 0.0,
                    'thresholdWindMaxSpeed' => 20.0,
                    'sampleCount5m'         => 0,
                    'sampleCount15m'        => 0,
                ];
            }

            protected function BuildRainPauseSnapshot(): array
            {
                return [
                    'rainPauseTriggered' => false,
                    'resumeDryStable'    => false,
                    'triggerRainSum5m'   => 0.0,
                    'thresholdRainSum5m' => 0.2,
                    'sampleCount5m'      => 0,
                    'sampleCount15m'     => 0,
                ];
            }

            protected function ReadOptionalFloatValue(int $varId): ?float
            {
                if (!array_key_exists($varId, $this->optionalFloatValues)) {
                    return null;
                }

                $value = $this->optionalFloatValues[$varId];
                if (!is_int($value) && !is_float($value)) {
                    return null;
                }

                return (float) $value;
            }

            public function RefreshWaterBalanceHistory(): bool
            {
                return true;
            }

            public function GetRainHistory(string $fromDateTime, string $toDateTime): ?float
            {
                return 0.0;
            }

            public function GetValveConsumption(int $zoneValveID, string $fromDateTime, string $toDateTime): ?float
            {
                return 0.0;
            }

            protected function OpenZoneValveOrSkip(int $valveId): array
            {
                if ($valveId === 201) {
                    return [
                        'opened'       => true,
                        'skipZone'     => false,
                        'reason'       => 'valve_open_success',
                        'debugMessage' => 'Valve start succeeded: valve ID 201 was opened exclusively.',
                    ];
                }

                return [
                    'opened'       => false,
                    'skipZone'     => true,
                    'reason'       => 'valve_open_failed',
                    'debugMessage' => 'Valve start failed.',
                ];
            }
        };

        $module->setPropertyValue('SystemAutoEnable', true);
        $module->setPropertyValue('SystemMaintenanceEnable', false);
        $module->setPropertyValue('SoilMinMoisture', 55);
        $module->setPropertyValue('GlobalSoilMoistureVarID', 0);
        $module->setPropertyValue('IrrigationMinRuntime', 1.0);
        $module->setPropertyValue('IrrigationZoneMaxRuntime', 60);
        $module->setPropertyValue('IrrigationInterval', 1);
        $module->attributes['WaterStorageAnchor'] = json_encode([
            '200' => ['storage' => -10.0, 'lastAutoIrrigationDate' => '2020-01-01'],
            '201' => ['storage' => -8.0, 'lastAutoIrrigationDate' => '2020-01-01'],
        ]);
        $module->setPropertyValue('ZonesTree', json_encode([
            ['Name' => 'Zone A', 'Active' => 1, 'Sequence' => 10, 'ValveVarID' => 200, 'SoilMoistureVarID' => 111, 'sprinklerPrecipitationRate' => 0.2],
            ['Name' => 'Zone B', 'Active' => 1, 'Sequence' => 20, 'ValveVarID' => 201, 'SoilMoistureVarID' => 0, 'sprinklerPrecipitationRate' => 0.2],
        ]));
        $module->optionalFloatValues = [
            111 => 60.0,
        ];

        $module->runDaily();

        $this->assertSame(AutoIrrigationContracts::RUN_STATE_RUNNING, $module->states[count($module->states) - 1][0]);
        $this->assertTrue($module->timerRescheduled);

        $debugPayload = array_map(static fn (array $entry): string => $entry[1], $module->debugMessages);
        $combined = implode("\n", $debugPayload);

        $this->assertStringContainsString('Zone Zone A (Seq 10) is skipped (soil_above_threshold_at_start).', $combined);
        $this->assertStringContainsString('Zone Zone B (Seq 20): starting runtime check skeleton', $combined);
        $this->assertStringNotContainsString('Zone Zone A (Seq 10): starting runtime check skeleton', $combined);
    }

    public function testOpenZoneValveOrSkipReportsOpenFailureAsZoneSkip(): void
    {
        $module = new class(1004) extends GardenIrrigationControl {
            public function runOpenZoneValveOrSkip(int $valveId): array
            {
                return $this->OpenZoneValveOrSkip($valveId);
            }

            protected function OpenSingleValve(int $valveId): bool
            {
                return false;
            }
        };

        $result = $module->runOpenZoneValveOrSkip(77);

        $this->assertFalse($result['opened']);
        $this->assertTrue($result['skipZone']);
        $this->assertSame('valve_open_failed', $result['reason']);
        $this->assertSame('Valve start skipped: the valve ID is invalid or does not exist.', $result['debugMessage']);
    }

    public function testHandleAutoIrrigationDailyStopsImmediatelyWhenAutoModeIsDisabled(): void
    {
        $module = new class(1002) extends GardenIrrigationControl {
            public array $states = [];
            public array $buffers = [];
            public bool $timerRescheduled = false;
            public array $properties = [];

            public function runDaily(): void
            {
                $this->HandleAutoIrrigationDaily();
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

            protected function decodeJsonArray(string $json): array
            {
                $decoded = json_decode($json, true);
                return is_array($decoded) ? $decoded : [];
            }

            protected function SetAutoIrrigationRunState(string $state, string $reason = ''): void
            {
                $this->states[] = [$state, $reason];
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

            protected function scheduleAutoIrrigationDailyTimer(): void
            {
                $this->timerRescheduled = true;
            }

            protected function BuildWindPauseSnapshot(): array
            {
                return [
                    'windPauseTriggered'    => false,
                    'resumeStable'          => false,
                    'triggerMeanWind'       => 0.0,
                    'thresholdWindMaxSpeed' => 20.0,
                    'sampleCount5m'         => 0,
                    'sampleCount15m'        => 0,
                ];
            }

            protected function BuildRainPauseSnapshot(): array
            {
                return [
                    'rainPauseTriggered' => false,
                    'resumeDryStable'    => false,
                    'triggerRainSum5m'   => 0.0,
                    'thresholdRainSum5m' => 0.2,
                    'sampleCount5m'      => 0,
                    'sampleCount15m'     => 0,
                ];
            }
        };

        $module->setPropertyValue('SystemAutoEnable', false);
        $module->setPropertyValue('SystemMaintenanceEnable', false);

        $module->runDaily();

        $this->assertSame([], $module->states);
        $this->assertTrue($module->timerRescheduled);
        $this->assertNull($module->buffers[AutoIrrigationContracts::STOP_SIGNAL_BUFFER_KEY] ?? null);
        $this->assertNull($module->buffers['AutoIrrigationPreparedZones'] ?? null);
    }

    public function testHandleAutoIrrigationDailyTransitionsThroughWindPauseAndKeepsRuntimeActiveAfterStableResume(): void
    {
        $module = new class(1006) extends GardenIrrigationControl {
            public array $states = [];
            public array $buffers = [];
            public bool $timerRescheduled = false;
            public bool $runtimeTickScheduled = false;
            public int $openCalls = 0;
            public array $properties = [];
            public array $attributes = [];

            public function runDaily(): void
            {
                $this->HandleAutoIrrigationDaily();
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

            protected function decodeJsonArray(string $json): array
            {
                $decoded = json_decode($json, true);
                return is_array($decoded) ? $decoded : [];
            }

            protected function ReadAttributeString(string $Name): string
            {
                return (string) ($this->attributes[$Name] ?? '[]');
            }

            protected function SetAutoIrrigationRunState(string $state, string $reason = ''): void
            {
                $this->states[] = [$state, $reason];
            }

            protected function SetBuffer(string $Name, string $Data): bool
            {
                $this->buffers[$Name] = $Data;
                return true;
            }

            protected function SetTimerInterval(string $Ident, int $Milliseconds): bool
            {
                if ($Ident === AutoIrrigationContracts::TIMER_IDENT_RUNTIME && $Milliseconds > 0) {
                    $this->runtimeTickScheduled = true;
                }

                return true;
            }

            protected function scheduleAutoIrrigationDailyTimer(): void
            {
                $this->timerRescheduled = true;
            }

            protected function BuildWindPauseSnapshot(): array
            {
                return [
                    'windPauseTriggered'    => true,
                    'resumeStable'          => true,
                    'triggerMeanWind'       => 26.0,
                    'thresholdWindMaxSpeed' => 20.0,
                    'sampleCount5m'         => 3,
                    'sampleCount15m'        => 5,
                ];
            }

            protected function BuildRainPauseSnapshot(): array
            {
                return [
                    'rainPauseTriggered' => false,
                    'resumeDryStable'    => false,
                    'triggerRainSum5m'   => 0.0,
                    'thresholdRainSum5m' => 0.2,
                    'sampleCount5m'      => 0,
                    'sampleCount15m'     => 0,
                ];
            }

            protected function BuildResumeReentryPipelineResult(array $zone, string $resumeReason): array
            {
                return [
                    'allowed'      => true,
                    'globalStop'   => false,
                    'reason'       => 'resume-ok',
                    'debugMessage' => 'resume-ok',
                ];
            }

            public function RefreshWaterBalanceHistory(): bool
            {
                return true;
            }

            public function GetRainHistory(string $fromDateTime, string $toDateTime): ?float
            {
                return 0.0;
            }

            public function GetValveConsumption(int $zoneValveID, string $fromDateTime, string $toDateTime): ?float
            {
                return 0.0;
            }

            protected function OpenZoneValveOrSkip(int $valveId): array
            {
                $this->openCalls++;

                return [
                    'opened'       => true,
                    'skipZone'     => false,
                    'reason'       => 'valve_open_success',
                    'debugMessage' => sprintf('Valve start succeeded: valve ID %d was opened exclusively.', $valveId),
                ];
            }
        };

        $module->setPropertyValue('SystemAutoEnable', true);
        $module->setPropertyValue('SystemMaintenanceEnable', false);
        $module->setPropertyValue('SoilMoistureMode', 0);
        $module->setPropertyValue('SoilMinMoisture', 40);
        $module->setPropertyValue('GlobalSoilMoistureVarID', 0);
        $module->setPropertyValue('IrrigationMinRuntime', 1.0);
        $module->setPropertyValue('IrrigationZoneMaxRuntime', 60);
        $module->setPropertyValue('IrrigationInterval', 1);
        $module->setPropertyValue('ZonesTree', json_encode([
            ['Name' => 'Zone A', 'Active' => 1, 'Sequence' => 10, 'ValveVarID' => 200, 'sprinklerPrecipitationRate' => 0.2],
        ]));
        $module->attributes['ActiveErrors'] = '[]';
        $module->attributes['WaterStorageAnchor'] = json_encode([
            '200' => ['storage' => -10.0, 'lastAutoIrrigationDate' => '2020-01-01'],
        ]);

        $module->runDaily();

        $this->assertSame(
            [
                [AutoIrrigationContracts::RUN_STATE_PRECHECK, 'Daily run started.'],
                [AutoIrrigationContracts::RUN_STATE_RUNNING, 'Daily runtime phase started.'],
                [AutoIrrigationContracts::RUN_STATE_PAUSED_WIND, 'Wind pause active: wind limit exceeded.'],
                [AutoIrrigationContracts::RUN_STATE_RUNNING, 'Wind pause ended: resume window is stable.'],
            ],
            $module->states
        );
        $this->assertTrue($module->timerRescheduled);
        $this->assertTrue($module->runtimeTickScheduled);
        $this->assertSame(2, $module->openCalls);
        $this->assertNotSame('', $module->buffers['AutoIrrigationPreparedZones'] ?? '');
    }

    public function testHandleAutoIrrigationDailyOpensValveForAnEligibleZoneStart(): void
    {
        $module = new class(10061) extends GardenIrrigationControl {
            public array $states = [];
            public array $buffers = [];
            public bool $timerRescheduled = false;
            public bool $runtimeTickScheduled = false;
            public int $openCalls = 0;
            public array $properties = [];
            public array $attributes = [];

            public function runDaily(): void
            {
                $this->HandleAutoIrrigationDaily();
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

            protected function decodeJsonArray(string $json): array
            {
                $decoded = json_decode($json, true);

                return is_array($decoded) ? $decoded : [];
            }

            protected function ReadAttributeString(string $Name): string
            {
                return (string) ($this->attributes[$Name] ?? '[]');
            }

            protected function SetAutoIrrigationRunState(string $state, string $reason = ''): void
            {
                $this->states[] = [$state, $reason];
            }

            protected function SetBuffer(string $Name, string $Data): bool
            {
                $this->buffers[$Name] = $Data;

                return true;
            }

            protected function SetTimerInterval(string $Ident, int $Milliseconds): bool
            {
                if ($Ident === AutoIrrigationContracts::TIMER_IDENT_RUNTIME && $Milliseconds > 0) {
                    $this->runtimeTickScheduled = true;
                }

                return true;
            }

            protected function scheduleAutoIrrigationDailyTimer(): void
            {
                $this->timerRescheduled = true;
            }

            protected function BuildWindPauseSnapshot(): array
            {
                return [
                    'windPauseTriggered'    => false,
                    'resumeStable'          => false,
                    'triggerMeanWind'       => 0.0,
                    'thresholdWindMaxSpeed' => 20.0,
                    'sampleCount5m'         => 0,
                    'sampleCount15m'        => 0,
                ];
            }

            protected function BuildRainPauseSnapshot(): array
            {
                return [
                    'rainPauseTriggered' => false,
                    'resumeDryStable'    => false,
                    'triggerRainSum5m'   => 0.0,
                    'thresholdRainSum5m' => 0.2,
                    'sampleCount5m'      => 0,
                    'sampleCount15m'     => 0,
                ];
            }

            protected function BuildResumeReentryPipelineResult(array $zone, string $resumeReason): array
            {
                return [
                    'allowed'      => true,
                    'globalStop'   => false,
                    'reason'       => 'resume-ok',
                    'debugMessage' => 'resume-ok',
                ];
            }

            public function RefreshWaterBalanceHistory(): bool
            {
                return true;
            }

            public function GetRainHistory(string $fromDateTime, string $toDateTime): ?float
            {
                return 0.0;
            }

            public function GetValveConsumption(int $zoneValveID, string $fromDateTime, string $toDateTime): ?float
            {
                return 0.0;
            }

            protected function OpenZoneValveOrSkip(int $valveId): array
            {
                $this->openCalls++;

                return [
                    'opened'       => true,
                    'skipZone'     => false,
                    'reason'       => 'valve_open_success',
                    'debugMessage' => sprintf('Valve start succeeded: valve ID %d was opened exclusively.', $valveId),
                ];
            }
        };

        $module->setPropertyValue('SystemAutoEnable', true);
        $module->setPropertyValue('SystemMaintenanceEnable', false);
        $module->setPropertyValue('SoilMoistureMode', 0);
        $module->setPropertyValue('SoilMinMoisture', 40);
        $module->setPropertyValue('GlobalSoilMoistureVarID', 0);
        $module->setPropertyValue('IrrigationMinRuntime', 1.0);
        $module->setPropertyValue('IrrigationZoneMaxRuntime', 60);
        $module->setPropertyValue('IrrigationInterval', 1);
        $module->setPropertyValue('ZonesTree', json_encode([
            ['Name' => 'Zone A', 'Active' => 1, 'Sequence' => 10, 'ValveVarID' => 200, 'sprinklerPrecipitationRate' => 0.2],
        ]));
        $module->attributes['ActiveErrors'] = '[]';
        $module->attributes['WaterStorageAnchor'] = json_encode([
            '200' => ['storage' => -10.0, 'lastAutoIrrigationDate' => '2020-01-01'],
        ]);

        $module->runDaily();

        $this->assertSame(1, $module->openCalls);
        $this->assertSame(
            [
                [AutoIrrigationContracts::RUN_STATE_PRECHECK, 'Daily run started.'],
                [AutoIrrigationContracts::RUN_STATE_RUNNING, 'Daily runtime phase started.'],
            ],
            array_slice($module->states, 0, 2)
        );
        $this->assertTrue($module->timerRescheduled);
        $this->assertTrue($module->runtimeTickScheduled);
        $this->assertNotSame('', $module->buffers['AutoIrrigationPreparedZones'] ?? '');
    }

    public function testHandleAutoIrrigationDailyTransitionsThroughRainPauseAndKeepsRuntimeActiveAfterStableResume(): void
    {
        $module = new class(1007) extends GardenIrrigationControl {
            public array $states = [];
            public array $buffers = [];
            public bool $timerRescheduled = false;
            public bool $runtimeTickScheduled = false;
            public array $properties = [];
            public array $attributes = [];

            public function runDaily(): void
            {

                $this->HandleAutoIrrigationDaily();
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

            protected function decodeJsonArray(string $json): array
            {
                $decoded = json_decode($json, true);
                return is_array($decoded) ? $decoded : [];
            }

            protected function ReadAttributeString(string $Name): string
            {
                return (string) ($this->attributes[$Name] ?? '[]');
            }

            protected function SetAutoIrrigationRunState(string $state, string $reason = ''): void
            {
                $this->states[] = [$state, $reason];
            }

            protected function SetBuffer(string $Name, string $Data): bool
            {
                $this->buffers[$Name] = $Data;
                return true;
            }

            protected function SetTimerInterval(string $Ident, int $Milliseconds): bool
            {
                if ($Ident === AutoIrrigationContracts::TIMER_IDENT_RUNTIME && $Milliseconds > 0) {
                    $this->runtimeTickScheduled = true;
                }

                return true;
            }

            protected function scheduleAutoIrrigationDailyTimer(): void
            {
                $this->timerRescheduled = true;
            }

            protected function BuildWindPauseSnapshot(): array
            {
                return [
                    'windPauseTriggered'    => false,
                    'resumeStable'          => false,
                    'triggerMeanWind'       => 0.0,
                    'thresholdWindMaxSpeed' => 20.0,
                    'sampleCount5m'         => 0,
                    'sampleCount15m'        => 0,
                ];
            }

            protected function BuildRainPauseSnapshot(): array
            {
                return [
                    'rainPauseTriggered' => true,
                    'resumeDryStable'    => true,
                    'triggerRainSum5m'   => 0.6,
                    'thresholdRainSum5m' => 0.2,
                    'sampleCount5m'      => 3,
                    'sampleCount15m'     => 5,
                ];
            }

            protected function BuildResumeReentryPipelineResult(array $zone, string $resumeReason): array
            {
                return [
                    'allowed'      => true,
                    'globalStop'   => false,
                    'reason'       => 'resume-ok',
                    'debugMessage' => 'resume-ok',
                ];
            }

            public function RefreshWaterBalanceHistory(): bool
            {
                return true;
            }

            public function GetRainHistory(string $fromDateTime, string $toDateTime): ?float
            {
                return 0.0;
            }

            public function GetValveConsumption(int $zoneValveID, string $fromDateTime, string $toDateTime): ?float
            {
                return 0.0;
            }

            protected function OpenZoneValveOrSkip(int $valveId): array
            {
                return [
                    'opened'       => true,
                    'skipZone'     => false,
                    'reason'       => 'valve_open_success',
                    'debugMessage' => sprintf('Valve start succeeded: valve ID %d was opened exclusively.', $valveId),
                ];
            }
        };

        $module->setPropertyValue('SystemAutoEnable', true);
        $module->setPropertyValue('SystemMaintenanceEnable', false);
        $module->setPropertyValue('SoilMoistureMode', 0);
        $module->setPropertyValue('SoilMinMoisture', 40);
        $module->setPropertyValue('GlobalSoilMoistureVarID', 0);
        $module->setPropertyValue('IrrigationMinRuntime', 1.0);
        $module->setPropertyValue('IrrigationZoneMaxRuntime', 60);
        $module->setPropertyValue('IrrigationInterval', 1);
        $module->setPropertyValue('ZonesTree', json_encode([
            ['Name' => 'Zone A', 'Active' => 1, 'Sequence' => 10, 'ValveVarID' => 200, 'sprinklerPrecipitationRate' => 0.2],
        ]));
        $module->attributes['ActiveErrors'] = '[]';
        $module->attributes['WaterStorageAnchor'] = json_encode([
            '200' => ['storage' => -10.0, 'lastAutoIrrigationDate' => '2020-01-01'],
        ]);

        $module->runDaily();

        $this->assertSame(
            [
                [AutoIrrigationContracts::RUN_STATE_PRECHECK, 'Daily run started.'],
                [AutoIrrigationContracts::RUN_STATE_RUNNING, 'Daily runtime phase started.'],
                [AutoIrrigationContracts::RUN_STATE_PAUSED_RAIN, 'Rain pause active: rain limit exceeded.'],
                [AutoIrrigationContracts::RUN_STATE_RUNNING, 'Rain pause ended: dry window is stable.'],
            ],
            $module->states
        );
        $this->assertTrue($module->timerRescheduled);
        $this->assertTrue($module->runtimeTickScheduled);
        $this->assertNotSame('', $module->buffers['AutoIrrigationPreparedZones'] ?? '');
    }

    public function testHandleZonePauseActionDoesNotWriteLastDateAndTerminalActionWritesOnlyWhenWatered(): void
    {
        $module = new class(1008) extends GardenIrrigationControl {
            public int $closeCalls = 0;
            public int $writeCalls = 0;
            public array $buffers = [];

            public function pauseZone(array $zone): void
            {
                $this->HandleZonePauseAction($zone, 'pause');
            }

            public function finishZone(array $zone): void
            {
                $this->HandleZoneTerminalAction($zone, 'finish');
            }

            protected function CloseAllValves(): bool
            {
                $this->closeCalls++;
                return true;
            }

            protected function WriteLastAutoIrrigationDateForZone(array $zone): void
            {
                $this->writeCalls++;
            }

            protected function GetBuffer(string $Name): string
            {
                return (string) ($this->buffers[$Name] ?? '');
            }

            protected function SetBuffer(string $Name, string $Data): bool
            {
                $this->buffers[$Name] = $Data;
                return true;
            }
        };

        $zone = ['Name' => 'Zone A', 'ValveVarID' => 123];

        $module->pauseZone($zone);
        $this->assertSame(1, $module->closeCalls);
        $this->assertSame(0, $module->writeCalls);

        $module->finishZone($zone);
        $this->assertSame(2, $module->closeCalls);
        $this->assertSame(0, $module->writeCalls);

        $module->buffers['AutoIrrigationCurrentZoneWatered'] = '1';
        $module->finishZone($zone);
        $this->assertSame(3, $module->closeCalls);
        $this->assertSame(1, $module->writeCalls);
    }

    public function testOpenZoneValveOrSkipReturnsSkipOnOpenFailure(): void
    {
        $module = new class(1004) extends GardenIrrigationControl {
            public bool $openResult = false;

            public function __construct(int $InstanceID)
            {
                parent::__construct($InstanceID);
            }

            public function runOpenZoneValve(int $valveId): array
            {
                return $this->OpenZoneValveOrSkip($valveId);
            }

            protected function OpenSingleValve(int $valveId): bool
            {
                return $this->openResult;
            }
        };

        $valveId = IPS_CreateVariable(0);
        $module->openResult = false;

        $result = $module->runOpenZoneValve($valveId);

        $this->assertFalse($result['opened']);
        $this->assertTrue($result['skipZone']);
        $this->assertSame('valve_open_failed', $result['reason']);
    }

    public function testOpenZoneValveOrSkipReturnsOpenedOnSuccess(): void
    {
        $module = new class(1005) extends GardenIrrigationControl {
            public bool $openResult = true;

            public function __construct(int $InstanceID)
            {
                parent::__construct($InstanceID);
            }

            public function runOpenZoneValve(int $valveId): array
            {
                return $this->OpenZoneValveOrSkip($valveId);
            }

            protected function OpenSingleValve(int $valveId): bool
            {
                return $this->openResult;
            }
        };

        $valveId = IPS_CreateVariable(0);
        $module->openResult = true;

        $result = $module->runOpenZoneValve($valveId);

        $this->assertTrue($result['opened']);
        $this->assertFalse($result['skipZone']);
        $this->assertSame('valve_open_success', $result['reason']);
    }

    public function testScheduleAutoIrrigationDailyTimerReconfigureRecomputesNextRun(): void
    {
        $module = new class(1009) extends GardenIrrigationControl {
            public array $properties = [];
            /** @var array<int, array{ident: string, milliseconds: int}> */
            public array $timerIntervals = [];

            public function setPropertyValue(string $name, mixed $value): void
            {
                $this->properties[$name] = $value;
            }

            public function runScheduleAutoIrrigationDailyTimer(): void
            {
                $this->scheduleAutoIrrigationDailyTimer();
            }

            protected function isModuleConfigurationInvalid(string $context): bool
            {
                return false;
            }

            protected function ReadPropertyString(string $Name): string
            {
                return (string) ($this->properties[$Name] ?? '');
            }

            protected function decodeJsonArray(string $json): array
            {
                $decoded = json_decode($json, true);

                return is_array($decoded) ? $decoded : [];
            }

            protected function SetTimerInterval(string $Ident, int $Milliseconds): bool
            {
                $this->timerIntervals[] = [
                    'ident'        => $Ident,
                    'milliseconds' => $Milliseconds,
                ];

                return true;
            }

            protected function SendDebug(string $Message, string $Data, int $Format): bool
            {
                return true;
            }
        };

        $module->setPropertyValue('IrrigationStartTime', json_encode(['hour' => 0, 'minute' => 0, 'second' => 0]));
        $module->runScheduleAutoIrrigationDailyTimer();

        $module->setPropertyValue('IrrigationStartTime', json_encode(['hour' => 12, 'minute' => 0, 'second' => 0]));
        $module->runScheduleAutoIrrigationDailyTimer();

        $this->assertCount(2, $module->timerIntervals);
        $this->assertSame(AutoIrrigationContracts::TIMER_IDENT_DAILY, $module->timerIntervals[0]['ident']);
        $this->assertSame(AutoIrrigationContracts::TIMER_IDENT_DAILY, $module->timerIntervals[1]['ident']);

        $firstInterval = $module->timerIntervals[0]['milliseconds'];
        $secondInterval = $module->timerIntervals[1]['milliseconds'];

        $this->assertGreaterThan(0, $firstInterval);
        $this->assertGreaterThan(0, $secondInterval);

        $diff = abs($firstInterval - $secondInterval);
        $this->assertGreaterThanOrEqual(43_190_000, $diff);
        $this->assertLessThanOrEqual(43_210_000, $diff);
    }

    public function testCloseFailureTriggersGlobalStop(): void
    {
        $module = new class(1010) extends GardenIrrigationControl {
            public array $states = [];
            public array $buffers = [];
            public int $closeCalls = 0;

            public function runGlobalStop(array $zone): void
            {
                $this->GlobalStopAndEnd($zone, 'test_close_failure', 'Global stop despite valve failure.');
            }

            protected function CloseAllValves(): bool
            {
                $this->closeCalls++;

                return false;
            }

            protected function SetBuffer(string $Name, string $Data): bool
            {
                $this->buffers[$Name] = $Data;

                return true;
            }

            protected function GetBuffer(string $Name): string
            {
                return (string) ($this->buffers[$Name] ?? '');
            }

            protected function SetAutoIrrigationRunState(string $state, string $reason = ''): void
            {
                $this->states[] = [$state, $reason];
            }

            protected function SendDebug(string $Message, string $Data, int $Format): bool
            {
                return true;
            }
        };

        $module->runGlobalStop([
            'Name'       => 'Zone A',
            'ValveVarID' => 200,
        ]);

        $this->assertSame(1, $module->closeCalls);
        $this->assertSame('', $module->buffers[AutoIrrigationContracts::STOP_SIGNAL_BUFFER_KEY] ?? '');
        $this->assertSame(
            [[AutoIrrigationContracts::RUN_STATE_STOPPED, 'Global stop despite valve failure.']],
            $module->states
        );
    }

    public function testValveRuntimeWatchdogPersistsElapsedTimeBeforeClearingTracker(): void
    {
        $module = new class(1011) extends GardenIrrigationControl {
            public array $buffers = [];
            public int $closeCalls = 0;
            public array $properties = [];

            public function runWatchdog(): void
            {
                $this->HandleValveRuntimeWatchdog();
            }

            /**
             * @param array<string, mixed> $zone
             * @return array{elapsedWateringMinutes: float, runtimeEffectiveMinutes: ?float}
             */
            public function runBuildTargetReachedSnapshot(array $zone): array
            {
                return $this->BuildTargetReachedSnapshot($zone);
            }

            public function setPropertyValue(string $name, mixed $value): void
            {
                $this->properties[$name] = $value;
            }

            protected function ReadPropertyInteger(string $Name): int
            {
                return (int) ($this->properties[$Name] ?? 0);
            }

            protected function ReadPropertyString(string $Name): string
            {
                return (string) ($this->properties[$Name] ?? '');
            }

            protected function SetBuffer(string $Name, string $Data): bool
            {
                $this->buffers[$Name] = $Data;

                return true;
            }

            protected function GetBuffer(string $Name): string
            {
                return (string) ($this->buffers[$Name] ?? '');
            }

            protected function SetTimerInterval(string $Ident, int $Milliseconds): bool
            {
                return true;
            }

            protected function CloseAllValves(): bool
            {
                $this->closeCalls++;
                $this->ClearValveRuntimeTracking();

                return true;
            }

            protected function GetIDForIdent(string $Ident): int
            {
                return 0;
            }

            protected function SendDebug(string $Message, string $Data, int $Format): bool
            {
                return true;
            }
        };

        $valveId = IPS_CreateVariable(0);
        SetValue($valveId, true);

        $module->setPropertyValue('IrrigationZoneMaxRuntime', 2);
        $module->buffers['AutoIrrigationCurrentZoneRuntimeEffectiveMinutes'] = '2';
        $module->buffers['AutoIrrigationCurrentZoneElapsedWateringSeconds'] = '0';
        $module->buffers['ValveRuntimeTracker'] = json_encode([
            'valveId'  => $valveId,
            'openedAt' => time() - 130,
            'version'  => 1,
        ]);

        $module->runWatchdog();

        $snapshot = $module->runBuildTargetReachedSnapshot([
            'Name'                       => 'Zone A',
            'ValveVarID'                 => $valveId,
            'sprinklerPrecipitationRate' => 0.2,
        ]);

        $this->assertSame(1, $module->closeCalls);
        $this->assertSame('', $module->buffers['ValveRuntimeTracker'] ?? '');
        $this->assertGreaterThanOrEqual(2.0, $snapshot['elapsedWateringMinutes']);
        $this->assertSame(2.0, $snapshot['runtimeEffectiveMinutes']);
    }
}
