<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\AutoIrrigationContracts;
use GardenIrrigationControl\Libs\LawnCoolingContracts;
use PHPUnit\Framework\TestCase;

final class LawnCoolingRuntimeLockCleanupTest extends TestCase
{
    public function testHardStopReleasesLocksAndClearsStopSignals(): void
    {
        $module = new class(1001) extends GardenIrrigationControl {
            /** @var array<string, string> */
            public array $buffers = [
                LawnCoolingContracts::STOP_SIGNAL_BUFFER_KEY           => '1',
                LawnCoolingContracts::SKIP_SIGNAL_BUFFER_KEY           => '1',
                LawnCoolingContracts::FIRST_VALVE_OPENED_BUFFER_KEY    => '1',
                LawnCoolingContracts::BUFFER_KEY_PREPARED_ZONES        => '[{"Name":"Cooling","Sequence":1,"ValveVarID":123}]',
                LawnCoolingContracts::BUFFER_KEY_CURRENT_ZONE_INDEX    => '0',
                LawnCoolingContracts::BUFFER_KEY_ZONE_STARTED_AT       => '1234',
            ];

            public bool $stopStateSet = false;

            public function runRuntimeTick(): void
            {
                $this->HandleLawnCoolingRuntimeTick();
            }

            public function isStopRequested(): bool
            {
                return $this->IsLawnCoolingStopRequested();
            }

            public function isSkipRequested(): bool
            {
                return $this->IsLawnCoolingSkipCurrentRun();
            }

            protected function IsLawnCoolingRunActive(): bool
            {
                return true;
            }

            protected function ReadPropertyInteger(string $name): int
            {
                return $name === 'CoolingTime' ? 1 : 0;
            }

            protected function GetIDForIdent(string $ident): int
            {
                return 0;
            }

            protected function ReadAttributeString(string $name): string
            {
                return $name === 'ActiveErrors' ? '[]' : '';
            }

            protected function ClearContextError(string $context): void
            {
            }

            protected function ClearError(): void
            {
            }

            protected function LogError(string $context, string $message, int $code): void
            {
            }

            protected function ReadBufferedValue(string $key): string
            {
                return $this->buffers[$key] ?? '';
            }

            protected function GetBuffer(string $key): string
            {
                return $this->buffers[$key] ?? '';
            }

            protected function SetBuffer(string $name, string $data): bool
            {
                $this->buffers[$name] = $data;

                return true;
            }

            protected function recheckLawnCoolingRuntimeGlobalPreconditions(string $context): array
            {
                return ['allowed' => true, 'reason' => '', 'debugMessage' => 'ok'];
            }

            protected function SetLawnCoolingRunState(string $state, string $reason = ''): void
            {
                $this->stopStateSet = $state === LawnCoolingContracts::RUN_STATE_STOPPED;
            }

            protected function CloseAllValves(): bool
            {
                return true;
            }

            protected function stopLawnCoolingRuntimeTick(): void
            {
            }

            protected function syncLawnCoolingSkipActionAvailability(): void
            {
            }

            protected function SendDebug(string $message, string $data, int $format): bool
            {
                return true;
            }
        };

        IPS_SemaphoreLeave(LawnCoolingContracts::RUN_LOCK_KEY);
        IPS_SemaphoreLeave(AutoIrrigationContracts::RUN_LOCK_KEY);
        $module->runRuntimeTick();

        self::assertTrue($module->stopStateSet);
        self::assertFalse($module->isStopRequested());
        self::assertFalse($module->isSkipRequested());

        self::assertTrue(IPS_SemaphoreEnter(LawnCoolingContracts::RUN_LOCK_KEY, 0));
        IPS_SemaphoreLeave(LawnCoolingContracts::RUN_LOCK_KEY);
        self::assertTrue(IPS_SemaphoreEnter(AutoIrrigationContracts::RUN_LOCK_KEY, 0));
        IPS_SemaphoreLeave(AutoIrrigationContracts::RUN_LOCK_KEY);
    }
}
