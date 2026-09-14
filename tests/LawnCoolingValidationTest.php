<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\LawnCoolingContracts;
use PHPUnit\Framework\TestCase;

class LawnCoolingValidationTest extends TestCase
{
    public function testInvalidConfigurationBlocksDailyCoolingEntryPointBeforeSignalEvaluation(): void
    {
        $module = new class(1001) extends GardenIrrigationControl {
            public bool $checkpointEvaluated = false;
            public bool $timerRescheduled = false;
            public bool $globalStopCalled = false;
            public array $properties = ['SystemAutoEnable' => true, 'SystemMaintenanceEnable' => false];

            public function runDaily(): void
            {
                $this->HandleLawnCoolingDaily();
            }

            protected function isModuleConfigurationInvalid(string $context): bool
            {
                return true;
            }

            protected function ReadPropertyBoolean(string $name): bool
            {
                return (bool) ($this->properties[$name] ?? false);
            }

            protected function ReadAttributeString(string $name): string
            {
                return '[]';
            }

            protected function evaluateLawnCoolingSignalCheckpoint(string $context, bool $firstValveOpened = false): array
            {
                $this->checkpointEvaluated = true;
                return [
                    'allowed'      => true,
                    'hardStop'     => false,
                    'reason'       => 'none',
                    'debugMessage' => '',
                ];
            }

            protected function scheduleLawnCoolingDailyTimer(): void
            {
                $this->timerRescheduled = true;
            }

            protected function LawnCoolingGlobalStopAndEnd(string $reason, string $stateReason): void
            {
                $this->globalStopCalled = true;
            }
        };

        $module->runDaily();

        $this->assertFalse($module->checkpointEvaluated);
        $this->assertTrue($module->timerRescheduled);
        $this->assertTrue($module->globalStopCalled);
    }

    public function testInvalidConfigurationBlocksCoolingPrecheckBeforeSignalEvaluation(): void
    {
        $module = new class(1001) extends GardenIrrigationControl {
            public bool $checkpointEvaluated = false;
            public bool $timerRescheduled = false;
            public bool $globalStopCalled = false;
            public array $properties = ['SystemAutoEnable' => true, 'SystemMaintenanceEnable' => false];

            public function runPrecheck(): void
            {
                $this->HandleLawnCoolingPrecheckTimer();
            }

            protected function isModuleConfigurationInvalid(string $context): bool
            {
                return true;
            }

            protected function ReadPropertyBoolean(string $name): bool
            {
                return (bool) ($this->properties[$name] ?? false);
            }

            protected function ReadAttributeString(string $name): string
            {
                return '[]';
            }

            protected function evaluateLawnCoolingSignalCheckpoint(string $context, bool $firstValveOpened = false): array
            {
                $this->checkpointEvaluated = true;
                return [
                    'allowed'      => true,
                    'hardStop'     => false,
                    'reason'       => 'none',
                    'debugMessage' => '',
                ];
            }

            protected function scheduleCoolingPrecheckTimer(): void
            {
                $this->timerRescheduled = true;
            }

            protected function LawnCoolingGlobalStopAndEnd(string $reason, string $stateReason): void
            {
                $this->globalStopCalled = true;
            }
        };

        $module->runPrecheck();

        $this->assertFalse($module->checkpointEvaluated);
        $this->assertTrue($module->timerRescheduled);
        $this->assertTrue($module->globalStopCalled);
    }

    public function testGlobalRecheckBlocksDisabledAutomaticMode(): void
    {
        $module = new class(1001) extends GardenIrrigationControl {
            public array $properties = ['SystemAutoEnable' => false, 'SystemMaintenanceEnable' => false];

            protected function isModuleConfigurationInvalid(string $context): bool
            {
                return false;
            }

            protected function ReadPropertyBoolean(string $name): bool
            {
                return (bool) ($this->properties[$name] ?? false);
            }

            protected function ReadAttributeString(string $name): string
            {
                return '[]';
            }

            public function runRecheck(): array
            {
                return $this->recheck_global_preconditions('test');
            }
        };

        $result = $module->runRecheck();

        $this->assertFalse($result['allowed']);
        $this->assertSame('COOL_GLOBAL_PRECHECK_FAIL', $result['reason']);
    }

    public function testGlobalRecheckBlocksActiveMaintenanceMode(): void
    {
        $module = new class(1001) extends GardenIrrigationControl {
            public array $properties = ['SystemAutoEnable' => true, 'SystemMaintenanceEnable' => true];

            protected function isModuleConfigurationInvalid(string $context): bool
            {
                return false;
            }

            protected function ReadPropertyBoolean(string $name): bool
            {
                return (bool) ($this->properties[$name] ?? false);
            }

            protected function ReadAttributeString(string $name): string
            {
                return '[]';
            }

            public function runRecheck(): array
            {
                return $this->recheck_global_preconditions('test');
            }
        };

        $result = $module->runRecheck();

        $this->assertFalse($result['allowed']);
        $this->assertSame('COOL_GLOBAL_PRECHECK_FAIL', $result['reason']);
    }

    public function testValidateCoolingConfigurationRejectsOverlappingWindows(): void
    {
        $validator = new \GardenIrrigationControl\Libs\ConfigurationValidator();

        $irrigationStartTime = ['hour' => 12, 'minute' => 0, 'second' => 0];
        $irrigationMaxRuntime = ['hour' => 1, 'minute' => 30, 'second' => 0];
        $coolingStartTime = ['hour' => 12, 'minute' => 45, 'second' => 0];

        $result = $validator->validateCoolingConfiguration(
            'lawn',
            true,
            $coolingStartTime,
            15,
            30,
            $irrigationStartTime,
            $irrigationMaxRuntime
        );

        $this->assertFalse($result->valid);
        $this->assertSame(243, $result->errorCode);
    }

    public function testCoolingPrecheckStopsOnOverlappingWindowsBeforeSignalEvaluation(): void
    {
        $module = new class(1001) extends GardenIrrigationControl {
            public bool $checkpointEvaluated = false;
            public bool $timerRescheduled = false;
            public bool $globalStopCalled = false;
            public array $properties = [
                'SystemAutoEnable'        => true,
                'SystemMaintenanceEnable' => false,
                'SystemType'              => 'lawn',
                'CoolingEnable'           => true,
                'CoolingStartTime'        => '{"hour":12,"minute":45,"second":0}',
                'CoolingTime'             => 15,
                'IrrigationStartTime'     => '{"hour":12,"minute":0,"second":0}',
                'IrrigationMaxRuntime'    => '{"hour":1,"minute":30,"second":0}',
            ];

            public function runPrecheck(): void
            {
                $this->HandleLawnCoolingPrecheckTimer();
            }

            protected function isModuleConfigurationInvalid(string $context): bool
            {
                return false;
            }

            protected function ReadPropertyBoolean(string $name): bool
            {
                return (bool) ($this->properties[$name] ?? false);
            }

            protected function ReadPropertyString(string $name): string
            {
                return (string) ($this->properties[$name] ?? '');
            }

            protected function ReadPropertyInteger(string $name): int
            {
                return (int) ($this->properties[$name] ?? 0);
            }

            protected function ReadAttributeString(string $name): string
            {
                return '[]';
            }

            protected function evaluateLawnCoolingSignalCheckpoint(string $context, bool $firstValveOpened = false): array
            {
                $this->checkpointEvaluated = true;
                return [
                    'allowed'      => true,
                    'hardStop'     => false,
                    'reason'       => 'none',
                    'debugMessage' => '',
                ];
            }

            protected function scheduleCoolingPrecheckTimer(): void
            {
                $this->timerRescheduled = true;
            }

            protected function LawnCoolingGlobalStopAndEnd(string $reason, string $stateReason): void
            {
                $this->globalStopCalled = true;
            }
        };

        $module->runPrecheck();

        $this->assertFalse($module->checkpointEvaluated);
        $this->assertTrue($module->timerRescheduled);
        $this->assertTrue($module->globalStopCalled);
    }

    public function testCoolingWindowOverlapDetectsIrrigationCrossingMidnight(): void
    {
        $validator = new \GardenIrrigationControl\Libs\ConfigurationValidator();

        $this->assertTrue($validator->coolingWindowsOverlap(
            ['hour' => 0, 'minute' => 30, 'second' => 0],
            15,
            ['hour' => 23, 'minute' => 0, 'second' => 0],
            ['hour' => 2, 'minute' => 0, 'second' => 0]
        ));
    }

    public function testCoolingZoneCandidatesFilterCoolingZonesAndApplySoilStartGate(): void
    {
        $module = new class(1001) extends GardenIrrigationControl {
            public array $properties = [
                'GlobalSoilMoistureVarID' => 456,
                'SoilMinMoisture'         => 40,
                'ZonesTree'               => '',
            ];
            public array $floatValues = [
                123 => 35.0,
                456 => 45.0,
            ];

            public function evaluateCandidates(): array
            {
                return $this->EvaluateLawnCoolingZoneCandidates('test');
            }

            protected function ReadPropertyInteger(string $name): int
            {
                return (int) ($this->properties[$name] ?? 0);
            }

            protected function ReadPropertyString(string $name): string
            {
                return (string) ($this->properties[$name] ?? '');
            }

            protected function ReadOptionalFloatValue(int $varId): ?float
            {
                return isset($this->floatValues[$varId]) ? (float) $this->floatValues[$varId] : null;
            }

            protected function SendDebug(string $Message, string $Data, int $Format): bool
            {
                return true;
            }
        };

        $module->properties['ZonesTree'] = json_encode([
            ['Name' => 'Auto Only', 'Active' => 1, 'UseCooling' => false, 'Sequence' => 10, 'ValveVarID' => 100],
            ['Name' => 'Wet Cooling', 'Active' => 1, 'UseCooling' => true, 'Sequence' => 20, 'ValveVarID' => 200, 'UseGlobalSoilMoisture' => true],
            ['Name' => 'Dry Cooling', 'Active' => 1, 'UseCooling' => true, 'Sequence' => 30, 'ValveVarID' => 300, 'SoilMoistureVarID' => 123],
        ]);

        $result = $module->evaluateCandidates();

        $this->assertTrue($result['allowed']);
        $this->assertSame(['Dry Cooling'], array_column($result['zones'], 'Name'));
        $this->assertCount(1, $result['skippedZones']);
        $this->assertSame('soil_above_threshold_at_start', $result['skippedZones'][0]['reason']);
    }

    public function testCoolingPrecheckEndsCleanlyWhenNoActiveCoolingZonesExist(): void
    {
        $module = new class(1001) extends GardenIrrigationControl {
            public bool $timerRescheduled = false;
            public bool $globalStopCalled = false;
            public array $properties = [
                'SystemAutoEnable'        => true,
                'SystemMaintenanceEnable' => false,
                'SystemType'              => 'lawn',
                'CoolingEnable'           => true,
                'ZonesTree'               => '',
            ];

            public function runPrecheck(): void
            {
                $this->HandleLawnCoolingPrecheckTimer();
            }

            protected function isModuleConfigurationInvalid(string $context): bool
            {
                return false;
            }

            protected function isLawnCoolingTimeWindowBlocked(string $context): bool
            {
                return false;
            }

            protected function evaluateLawnCoolingTemperatureGate(string $context): array
            {
                return ['allowed' => true, 'reason' => 'temperature_threshold_reached', 'debugMessage' => ''];
            }

            protected function evaluateLawnCoolingRainGate(string $context): array
            {
                return ['allowed' => true, 'reason' => 'rain_6h_clear', 'debugMessage' => ''];
            }

            protected function evaluateLawnCoolingSignalCheckpoint(string $context, bool $firstValveOpened = false): array
            {
                return ['allowed' => true, 'hardStop' => false, 'reason' => 'none', 'debugMessage' => ''];
            }

            protected function ReadPropertyBoolean(string $name): bool
            {
                return (bool) ($this->properties[$name] ?? false);
            }

            protected function ReadPropertyString(string $name): string
            {
                return (string) ($this->properties[$name] ?? '');
            }

            protected function ReadAttributeString(string $name): string
            {
                return '[]';
            }

            protected function scheduleCoolingPrecheckTimer(): void
            {
                $this->timerRescheduled = true;
            }

            protected function LawnCoolingGlobalStopAndEnd(string $reason, string $stateReason): void
            {
                $this->globalStopCalled = true;
            }
        };

        $module->properties['ZonesTree'] = json_encode([
            ['Name' => 'Auto Only', 'Active' => 1, 'UseCooling' => false, 'Sequence' => 10, 'ValveVarID' => 100],
            ['Name' => 'Inactive Cooling', 'Active' => 0, 'UseCooling' => true, 'Sequence' => 20, 'ValveVarID' => 200],
        ]);

        $module->runPrecheck();

        $this->assertTrue($module->timerRescheduled);
        $this->assertFalse($module->globalStopCalled);
    }

    public function testCoolingPrecheckSendsPushOnlyForAllowedResult(): void
    {
        $module = new class(1001) extends GardenIrrigationControl {
            public int $pushCalls = 0;
            /** @var array<int, mixed> */
            public array $pushPayload = [];
            /** @var array<string, string> */
            public array $translations = [
                'lawn_cooling.precheck.allow.title' => 'Lawn cooling',
                'lawn_cooling.precheck.allow.text'  => 'Translated precheck text.',
            ];
            public array $properties = [
                'SystemAutoEnable'        => true,
                'SystemMaintenanceEnable' => false,
                'SystemType'              => 'lawn',
                'CoolingEnable'           => true,
                'CoolingPushEnable'       => true,
                'CoolingPushInstance'     => 123,
                'ZonesTree'               => '[]',
            ];

            public function runPrecheck(): void
            {
                $this->HandleLawnCoolingPrecheckTimer();
            }

            protected function isModuleConfigurationInvalid(string $context): bool
            {
                return false;
            }

            protected function isLawnCoolingTimeWindowBlocked(string $context): bool
            {
                return false;
            }

            protected function evaluateLawnCoolingTemperatureGate(string $context): array
            {
                return ['allowed' => true, 'reason' => 'temperature_threshold_reached', 'debugMessage' => ''];
            }

            protected function evaluateLawnCoolingRainGate(string $context): array
            {
                return ['allowed' => true, 'reason' => 'rain_6h_clear', 'debugMessage' => ''];
            }

            protected function evaluateLawnCoolingSignalCheckpoint(string $context, bool $firstValveOpened = false): array
            {
                return ['allowed' => true, 'hardStop' => false, 'reason' => 'none', 'debugMessage' => ''];
            }

            protected function EvaluateLawnCoolingZoneCandidates(string $context): array
            {
                return [
                    'allowed'      => true,
                    'zones'        => [['Name' => 'Cooling Zone']],
                    'skippedZones' => [],
                    'reason'       => 'cooling_zones_ready',
                    'debugMessage' => '',
                ];
            }

            protected function ReadPropertyBoolean(string $name): bool
            {
                return (bool) ($this->properties[$name] ?? false);
            }

            protected function ReadPropertyString(string $name): string
            {
                return (string) ($this->properties[$name] ?? '');
            }

            protected function ReadPropertyInteger(string $name): int
            {
                return (int) ($this->properties[$name] ?? 0);
            }

            protected function ReadAttributeString(string $name): string
            {
                return '[]';
            }

            protected function GetIDForIdent(string $ident): int
            {
                return $ident === LawnCoolingContracts::SKIP_VARIABLE_IDENT ? 987 : 0;
            }

            protected function SendDebug(string $Message, string $Data, int $Format): bool
            {
                return true;
            }

            public function Translate(string $text): string
            {
                return $this->translations[$text] ?? $text;
            }

            public function SendPushNotification(int $instanceID, string $title, string $text, string $type, int $targetID): bool
            {
                $this->pushCalls++;
                $this->pushPayload = [$instanceID, $title, $text, $type, $targetID];
                return true;
            }

            protected function scheduleCoolingPrecheckTimer(): void
            {
            }
        };

        $module->runPrecheck();

        $this->assertSame(1, $module->pushCalls);
        $this->assertSame(123, $module->pushPayload[0]);
        $this->assertSame('Lawn cooling', $module->pushPayload[1]);
        $this->assertSame('Translated precheck text.', $module->pushPayload[2]);
        $this->assertSame('Info', $module->pushPayload[3]);
        $this->assertSame(987, $module->pushPayload[4]);
    }

    public function testCoolingPrecheckSuppressesPushForDisabledOrInvalidResults(): void
    {
        $createModule = static function (bool $pushEnabled, bool $configurationInvalid, bool $temperatureAllowed, string $systemType = 'lawn', bool $coolingEnabled = true): GardenIrrigationControl
        {
            return new class($pushEnabled, $configurationInvalid, $temperatureAllowed, $systemType, $coolingEnabled) extends GardenIrrigationControl {
                public int $pushCalls = 0;
                public array $properties;

                public function __construct(
                    bool $pushEnabled,
                    bool $configurationInvalid,
                    bool $temperatureAllowed,
                    string $systemType,
                    bool $coolingEnabled
                ) {
                    parent::__construct(1001);
                    $this->properties = [
                        'SystemAutoEnable'        => true,
                        'SystemMaintenanceEnable' => false,
                        'SystemType'              => $systemType,
                        'CoolingEnable'           => $coolingEnabled,
                        'CoolingPushEnable'       => $pushEnabled,
                        'CoolingPushInstance'     => 123,
                    ];
                    $this->configurationInvalid = $configurationInvalid;
                    $this->temperatureAllowed = $temperatureAllowed;
                }

                private bool $configurationInvalid;
                private bool $temperatureAllowed;

                public function runPrecheck(): void
                {
                    $this->HandleLawnCoolingPrecheckTimer();
                }

                protected function isModuleConfigurationInvalid(string $context): bool
                {
                    return $this->configurationInvalid;
                }

                protected function isLawnCoolingTimeWindowBlocked(string $context): bool
                {
                    return false;
                }

                protected function evaluateLawnCoolingTemperatureGate(string $context): array
                {
                    return ['allowed' => $this->temperatureAllowed, 'reason' => 'test', 'debugMessage' => ''];
                }

                protected function evaluateLawnCoolingRainGate(string $context): array
                {
                    return ['allowed' => true, 'reason' => 'test', 'debugMessage' => ''];
                }

                protected function evaluateLawnCoolingSignalCheckpoint(string $context, bool $firstValveOpened = false): array
                {
                    return ['allowed' => true, 'hardStop' => false, 'reason' => 'none', 'debugMessage' => ''];
                }

                protected function EvaluateLawnCoolingZoneCandidates(string $context): array
                {
                    return [
                        'allowed'      => true,
                        'zones'        => [['Name' => 'Cooling Zone']],
                        'skippedZones' => [],
                        'reason'       => 'test',
                        'debugMessage' => '',
                    ];
                }

                protected function ReadPropertyBoolean(string $name): bool
                {
                    return (bool) ($this->properties[$name] ?? false);
                }

                protected function ReadPropertyString(string $name): string
                {
                    return (string) ($this->properties[$name] ?? '');
                }

                protected function ReadPropertyInteger(string $name): int
                {
                    return (int) ($this->properties[$name] ?? 0);
                }

                protected function ReadAttributeString(string $name): string
                {
                    return '[]';
                }

                protected function SendDebug(string $Message, string $Data, int $Format): bool
                {
                    return true;
                }

                public function SendPushNotification(int $instanceID, string $title, string $text, string $type, int $targetID): bool
                {
                    $this->pushCalls++;
                    return true;
                }

                protected function scheduleCoolingPrecheckTimer(): void
                {
                }

                protected function LawnCoolingGlobalStopAndEnd(string $reason, string $stateReason): void
                {
                }
            };
        };

        $disabled = $createModule(false, false, true);
        $disabled->runPrecheck();
        $this->assertSame(0, $disabled->pushCalls);

        $blocked = $createModule(true, false, false);
        $blocked->runPrecheck();
        $this->assertSame(0, $blocked->pushCalls);

        $invalid = $createModule(true, true, true);
        $invalid->runPrecheck();
        $this->assertSame(0, $invalid->pushCalls);

        $nonLawn = $createModule(true, false, true, 'garden', true);
        $nonLawn->runPrecheck();
        $this->assertSame(0, $nonLawn->pushCalls);

        $disabledCooling = $createModule(true, false, true, 'lawn', false);
        $disabledCooling->runPrecheck();
        $this->assertSame(0, $disabledCooling->pushCalls);
    }

    public function testCoolingSkipCanBeSetAndClearedBeforeFirstValveOpening(): void
    {
        $module = new class(1001) extends GardenIrrigationControl {
            public array $values = [];

            public function requestSkip(bool $requested): void
            {
                $this->HandleLawnCoolingSkipAction($requested);
            }

            public function isSkipRequested(): bool
            {
                return $this->IsLawnCoolingSkipCurrentRun();
            }

            public function clearSkip(): void
            {
                $this->ClearLawnCoolingSkipCurrentRun();
            }

            protected function syncLawnCoolingSkipActionAvailability(): void
            {
            }

            protected function GetIDForIdent(string $ident): int
            {
                return $ident === LawnCoolingContracts::SKIP_VARIABLE_IDENT ? 1 : 0;
            }

            protected function SetValue(string $Ident, mixed $Value): bool
            {
                $this->values[$Ident] = $Value;
                return true;
            }
        };

        $module->requestSkip(true);
        $this->assertTrue($module->isSkipRequested());
        $this->assertTrue($module->values[LawnCoolingContracts::SKIP_VARIABLE_IDENT]);

        $module->requestSkip(false);
        $this->assertFalse($module->isSkipRequested());
        $this->assertFalse($module->values[LawnCoolingContracts::SKIP_VARIABLE_IDENT]);

        $module->requestSkip(true);
        $module->clearSkip();
        $this->assertFalse($module->values[LawnCoolingContracts::SKIP_VARIABLE_IDENT]);
    }

    public function testCoolingSkipAfterFirstValveOpeningBecomesHardStop(): void
    {
        $module = new class(1001) extends GardenIrrigationControl {
            public array $values = [];

            public function markFirstValveOpened(): void
            {
                $this->SetLawnCoolingFirstValveOpened(true);
            }

            public function requestSkip(): void
            {
                $this->HandleLawnCoolingSkipAction(true);
            }

            public function isStopRequested(): bool
            {
                return $this->IsLawnCoolingStopRequested();
            }

            protected function syncLawnCoolingSkipActionAvailability(): void
            {
            }

            protected function SetValue(string $Ident, mixed $Value): bool
            {
                $this->values[$Ident] = $Value;
                return true;
            }
        };

        $module->markFirstValveOpened();
        $module->requestSkip();

        $this->assertTrue($module->isStopRequested());
        $this->assertTrue($module->values[LawnCoolingContracts::SKIP_VARIABLE_IDENT]);
    }

    public function testValidateCoolingConfigurationRequiresPushInstanceWhenEnabled(): void
    {
        $validator = new \GardenIrrigationControl\Libs\ConfigurationValidator();

        $irrigationStartTime = ['hour' => 12, 'minute' => 0, 'second' => 0];
        $irrigationMaxRuntime = ['hour' => 1, 'minute' => 30, 'second' => 0];
        $coolingStartTime = ['hour' => 14, 'minute' => 0, 'second' => 0];

        $result = $validator->validateCoolingConfiguration(
            'lawn',
            true,
            $coolingStartTime,
            15,
            30,
            $irrigationStartTime,
            $irrigationMaxRuntime,
            true,
            0
        );

        $this->assertFalse($result->valid);
        $this->assertSame(244, $result->errorCode);
    }
}
