<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\LawnCoolingRuntimeOrchestrator;
use PHPUnit\Framework\TestCase;

final class LawnCoolingRuntimeOrchestratorTest extends TestCase
{
    public function testOpensZoneAndKeepsItActiveUntilConfiguredDurationEnds(): void
    {
        $orchestrator = new LawnCoolingRuntimeOrchestrator();
        $opened = [];
        $closed = 0;
        $callbacks = $this->callbacks($opened, $closed);

        $first = $orchestrator->runTick($this->zones(), 0, null, 300, $callbacks, 1000);
        $second = $orchestrator->runTick($this->zones(), 0, $first['startedAt'], 300, $callbacks, 1299);
        $third = $orchestrator->runTick($this->zones(), 0, $first['startedAt'], 300, $callbacks, 1300);

        self::assertSame('active', $first['outcome']);
        self::assertSame('active', $second['outcome']);
        self::assertSame('active', $third['outcome']);
        self::assertSame([10, 20], $opened);
        self::assertSame(1, $closed);
        self::assertSame(1, $third['nextIndex']);
    }

    public function testSkipsWetAndFailedZonesAndContinuesWithNextZone(): void
    {
        $orchestrator = new LawnCoolingRuntimeOrchestrator();
        $opened = [];
        $closed = 0;
        $soilChecks = 0;
        $callbacks = $this->callbacks($opened, $closed);
        $callbacks['evaluateSoilStart'] = static function (array $zone) use (&$soilChecks): array
        {
            $soilChecks++;

            return ['allowed' => (int) ($zone['ValveVarID'] ?? 0) !== 10];
        };
        $callbacks['openSingleValve'] = static function (int $valveId): bool
        {
            return $valveId !== 20;
        };

        $result = $orchestrator->runTick($this->zones(), 0, null, 300, $callbacks, 1000);

        self::assertSame('finished', $result['outcome']);
        self::assertSame(2, $result['nextIndex']);
        self::assertSame(2, $soilChecks);
        self::assertSame([], $opened);
        self::assertSame(1, $closed);
    }

    public function testGlobalRecheckStopsTheRunWithoutOpeningAnotherZone(): void
    {
        $orchestrator = new LawnCoolingRuntimeOrchestrator();
        $opened = [];
        $closed = 0;
        $callbacks = $this->callbacks($opened, $closed);
        $callbacks['recheckGlobal'] = static fn (): array => [
            'allowed'      => false,
            'debugMessage' => 'global blocker',
        ];

        $result = $orchestrator->runTick($this->zones(), 0, null, 300, $callbacks, 1000);

        self::assertSame('stopped', $result['outcome']);
        self::assertTrue($result['globalStop']);
        self::assertSame('', $result['stopReason']);
        self::assertSame([], $opened);
    }

    public function testHardStopDuringActiveZoneIsReturnedAsGlobalStop(): void
    {
        $orchestrator = new LawnCoolingRuntimeOrchestrator();
        $opened = [];
        $closed = 0;
        $callbacks = $this->callbacks($opened, $closed);
        $callbacks['checkSignals'] = static fn (bool $firstValveOpened): array => [
            'allowed'  => false,
            'hardStop' => $firstValveOpened,
        ];

        $result = $orchestrator->runTick($this->zones(), 0, 1000, 300, $callbacks, 1100);

        self::assertSame('stopped', $result['outcome']);
        self::assertTrue($result['globalStop']);
        self::assertSame(0, $closed);
    }

    public function testCloseFailureStopsTheRunGlobally(): void
    {
        $orchestrator = new LawnCoolingRuntimeOrchestrator();
        $opened = [];
        $closed = 0;
        $callbacks = $this->callbacks($opened, $closed);
        $callbacks['closeAllValves'] = static fn (): bool => false;

        $result = $orchestrator->runTick($this->zones(), 0, 1000, 300, $callbacks, 1300);

        self::assertSame('stopped', $result['outcome']);
        self::assertTrue($result['globalStop']);
        self::assertSame('COOL_GLOBAL_VALVE_CLOSE_FAILED', $result['stopReason']);
    }

    /**
     * @param array<int, int> $opened
     * @return array<string, callable>
     */
    private function callbacks(array &$opened, int &$closed): array
    {
        return [
            'recheckGlobal'     => static fn (): array => ['allowed' => true, 'debugMessage' => 'ok'],
            'checkSignals'      => static fn (bool $firstValveOpened): array => ['allowed' => true, 'hardStop' => false],
            'evaluateSoilStart' => static fn (array $zone): array => ['allowed' => true],
            'openSingleValve'   => static function (int $valveId) use (&$opened): bool
            {
                $opened[] = $valveId;

                return true;
            },
            'closeAllValves'    => static function () use (&$closed): bool
            {
                $closed++;

                return true;
            },
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function zones(): array
    {
        return [
            ['Name' => 'Erste Zone', 'Sequence' => 1, 'ValveVarID' => 10],
            ['Name' => 'Zweite Zone', 'Sequence' => 2, 'ValveVarID' => 20],
        ];
    }
}
