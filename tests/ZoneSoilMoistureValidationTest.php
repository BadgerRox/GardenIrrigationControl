<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\ConfigurationValidator;
use PHPUnit\Framework\TestCase;

class ZoneSoilMoistureValidationTest extends TestCase
{
    protected function setUp(): void
    {
        \IPS\Kernel::reset();
    }

    public function testValidateZonesAcceptsClearedOptionalZoneSoilSensorValue(): void
    {
        $valveId = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);

        $zones = [
            [
                'Name'                       => 'Zone A',
                'Orientation'                => 'N',
                'ValveVarID'                 => $valveId,
                'SoilMoistureVarID'          => '{}',
                'UseGlobalSoilMoisture'      => false,
                'Sequence'                   => 1,
                'sprinklerPrecipitationRate' => 0.2,
                'Slope'                      => 0,
            ],
        ];

        $module = $this->createValidationModule(9101, $zones);

        $result = $module->runValidateZones(new ConfigurationValidator());

        $this->assertIsArray($result);
        $this->assertCount(0, $module->loggedErrors);
    }

    public function testValidateZonesRejectsConfiguredNonExistingZoneSoilSensor(): void
    {
        $valveId = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);

        $zones = [
            [
                'Name'                       => 'Zone A',
                'Orientation'                => 'N',
                'ValveVarID'                 => $valveId,
                'SoilMoistureVarID'          => 999999,
                'UseGlobalSoilMoisture'      => false,
                'Sequence'                   => 1,
                'sprinklerPrecipitationRate' => 0.2,
                'Slope'                      => 0,
            ],
        ];

        $module = $this->createValidationModule(9102, $zones);

        $result = $module->runValidateZones(new ConfigurationValidator());

        $this->assertNull($result);
        $this->assertSame(254, $module->loggedErrors[0]['code'] ?? null);
    }

    public function testValidateZonesAcceptsSelectVariableClearPlaceholderIdOne(): void
    {
        $valveId = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);

        $zones = [
            [
                'Name'                       => 'Zone A',
                'Orientation'                => 'N',
                'ValveVarID'                 => $valveId,
                'SoilMoistureVarID'          => 1,
                'UseGlobalSoilMoisture'      => false,
                'Sequence'                   => 1,
                'sprinklerPrecipitationRate' => 0.2,
                'Slope'                      => 0,
            ],
        ];

        $module = $this->createValidationModule(9103, $zones);

        $result = $module->runValidateZones(new ConfigurationValidator());

        $this->assertIsArray($result);
        $this->assertCount(0, $module->loggedErrors);
    }

    public function testOptionalObjectIdNormalizationOnlyMapsPlaceholderOneToUnset(): void
    {
        $module = $this->createValidationModule(9104, []);

        $this->assertSame(0, $module->runNormalizeOptionalObjectId(1));
        $this->assertSame(0, $module->runNormalizeOptionalObjectId(0));
        $this->assertSame(42, $module->runNormalizeOptionalObjectId(42));
    }

    /**
     * @param array<int, array<string, mixed>> $zones
     */
    private function createValidationModule(int $instanceId, array $zones): object
    {
        return new class($instanceId, $zones) extends GardenIrrigationControl {
            /** @var array<int, array<string, mixed>> */
            private array $zones;

            /** @var array<int, array{context: string, message: string, code: int}> */
            public array $loggedErrors = [];

            /**
             * @param array<int, array<string, mixed>> $zones
             */
            public function __construct(int $InstanceID, array $zones)
            {
                $this->zones = $zones;
                parent::__construct($InstanceID);
            }

            /**
             * @return array{zones: array<int, array<string, mixed>>, frontendValves: array<int, array{Name: string, ID: int}>}|null
             */
            public function runValidateZones(ConfigurationValidator $validator): ?array
            {
                $reflection = new ReflectionMethod(GardenIrrigationControl::class, 'validateZones');
                $reflection->setAccessible(true);

                /** @var array{zones: array<int, array<string, mixed>>, frontendValves: array<int, array{Name: string, ID: int}>}|null $result */
                $result = $reflection->invoke($this, $validator, 1);

                return $result;
            }

            public function runNormalizeOptionalObjectId(int $objectId): int
            {
                return $this->normalizeOptionalObjectId($objectId);
            }

            /**
             * @return array<int, array<string, mixed>>
             */
            protected function getZonesTree(): array
            {
                return $this->zones;
            }

            protected function ReadPropertyInteger(string $Name): int
            {
                return 0;
            }

            protected function validateArchiveVariable(int $varID, int $archiveID, string $varName, bool $required = true): void
            {
            }

            protected function LogError(string $context, string $message, int $code): void
            {
                $this->loggedErrors[] = [
                    'context' => $context,
                    'message' => $message,
                    'code'    => $code,
                ];
            }
        };
    }
}
