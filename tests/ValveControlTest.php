<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\ArrayLogger;
use GardenIrrigationControl\Libs\ValveControl;
use PHPUnit\Framework\TestCase;

class ValveControlTest extends TestCase
{
    protected function setUp(): void
    {
        \IPS\Kernel::reset();
    }

    public function testCloseAllValvesReturnsEmptyPayloadForEmptyZones(): void
    {
        $controller = new ValveControl(new ArrayLogger());

        $result = $controller->closeAllValves([]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['valvesToValidate']);
        $this->assertSame([], $result['errors']);
    }

    public function testCloseAllValvesClosesValidValveVariablesAndSkipsInvalidOnes(): void
    {
        $controller = new ValveControl(new ArrayLogger());

        $valveA = IPS_CreateVariable(0);
        $valveB = IPS_CreateVariable(0);
        SetValue($valveA, true);
        SetValue($valveB, true);

        $zones = [
            ['Name' => 'Zone A', 'ValveVarID' => $valveA],
            ['Name' => 'Zone B', 'ValveVarID' => $valveB],
            ['Name' => 'Zone Invalid 0', 'ValveVarID' => 0],
            ['Name' => 'Zone Missing', 'ValveVarID' => 999999]
        ];

        $result = $controller->closeAllValves($zones);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['valvesToValidate']);
        $this->assertSame([], $result['errors']);
        $this->assertFalse(GetValueBoolean($valveA));
        $this->assertFalse(GetValueBoolean($valveB));
        $this->assertSame($valveA, $result['valvesToValidate'][0]['ValveID']);
        $this->assertSame('Zone A', $result['valvesToValidate'][0]['Name']);
        $this->assertFalse($result['valvesToValidate'][0]['ExpectedValue']);
    }

    public function testCloseAllValvesCollectsErrorWhenActionCommandFails(): void
    {
        $controller = new ValveControl(new ArrayLogger());

        $valveA = IPS_CreateVariable(0);
        \IPS\VariableManager::setVariableAction($valveA, 12345);

        $result = $controller->closeAllValves([
            ['Name' => 'Zone Action Fail', 'ValveVarID' => $valveA]
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame([], $result['valvesToValidate']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('CloseAllValves', $result['errors'][0]['context']);
        $this->assertSame(401, $result['errors'][0]['code']);
    }

    public function testOpenSingleValveAbortsWhenCloseAllValvesFails(): void
    {
        $controller = new ValveControl(new ArrayLogger());

        $blockingValve = IPS_CreateVariable(0);
        $targetValve = IPS_CreateVariable(0);
        SetValue($targetValve, false);

        \IPS\VariableManager::setVariableAction($blockingValve, 12345);

        $result = $controller->openSingleValve($targetValve, [
            ['Name' => 'Blocking Zone', 'ValveVarID' => $blockingValve],
            ['Name' => 'Target Zone', 'ValveVarID' => $targetValve]
        ]);

        $this->assertFalse($result['success']);
        $this->assertCount(1, $result['valvesToValidate']);
        $this->assertSame($targetValve, $result['valvesToValidate'][0]['ValveID']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('CloseAllValves', $result['errors'][0]['context']);
        $this->assertSame(401, $result['errors'][0]['code']);
        $this->assertFalse(GetValueBoolean($targetValve));
    }

    public function testOpenSingleValveReturnsFalseForInvalidValveId(): void
    {
        $controller = new ValveControl(new ArrayLogger());

        $result = $controller->openSingleValve(0, []);

        $this->assertFalse($result['success']);
        $this->assertSame([], $result['valvesToValidate']);
        $this->assertSame([], $result['errors']);
    }

    public function testOpenSingleValveClosesOthersAndOpensTargetValve(): void
    {
        $controller = new ValveControl(new ArrayLogger());

        $otherValve = IPS_CreateVariable(0);
        $targetValve = IPS_CreateVariable(0);
        SetValue($otherValve, true);
        SetValue($targetValve, false);

        $zones = [
            ['Name' => 'Other Zone', 'ValveVarID' => $otherValve],
            ['Name' => 'Target Zone', 'ValveVarID' => $targetValve]
        ];

        $result = $controller->openSingleValve($targetValve, $zones);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['valvesToValidate']);

        $validationByValveId = [];
        foreach ($result['valvesToValidate'] as $entry) {
            $validationByValveId[$entry['ValveID']] = $entry;
        }

        $this->assertArrayHasKey($otherValve, $validationByValveId);
        $this->assertFalse($validationByValveId[$otherValve]['ExpectedValue']);

        $this->assertArrayHasKey($targetValve, $validationByValveId);
        $this->assertSame('Target valve', $validationByValveId[$targetValve]['Name']);
        $this->assertTrue($validationByValveId[$targetValve]['ExpectedValue']);
        $this->assertSame([], $result['errors']);
        $this->assertFalse(GetValueBoolean($otherValve));
        $this->assertTrue(GetValueBoolean($targetValve));
    }

    public function testOpenSingleValveCollectsErrorWhenOpenCommandFails(): void
    {
        $controller = new ValveControl(new ArrayLogger());

        $targetValve = IPS_CreateVariable(0);
        \IPS\VariableManager::setVariableAction($targetValve, 12345);

        $result = $controller->openSingleValve($targetValve, []);

        $this->assertFalse($result['success']);
        $this->assertSame([], $result['valvesToValidate']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('OpenSingleValve', $result['errors'][0]['context']);
        $this->assertSame(400, $result['errors'][0]['code']);
    }

    public function testValidateValvesAsyncReturnsResetForEmptyPayload(): void
    {
        $controller = new ValveControl(new ArrayLogger());

        $result = $controller->validateValvesAsync([], 0);

        $this->assertSame([], $result['pendingValves']);
        $this->assertSame(0, $result['retryCount']);
        $this->assertSame(0, $result['timerInterval']);
        $this->assertSame([], $result['clearContexts']);
        $this->assertSame([], $result['errors']);
    }

    public function testValidateValvesAsyncClearsContextsWhenAllValvesMatch(): void
    {
        $controller = new ValveControl(new ArrayLogger());

        $valve = IPS_CreateVariable(0);
        SetValue($valve, false);

        $result = $controller->validateValvesAsync([
            ['ValveID' => $valve, 'Name' => 'Zone A', 'ExpectedValue' => false]
        ], 0);

        $this->assertSame([], $result['pendingValves']);
        $this->assertSame(0, $result['retryCount']);
        $this->assertSame(0, $result['timerInterval']);
        $this->assertSame(['CloseAllValves', 'OpenSingleValve'], $result['clearContexts']);
        $this->assertSame([], $result['errors']);
    }

    public function testValidateValvesAsyncSchedulesRetryWhenValveStateMismatches(): void
    {
        $controller = new ValveControl(new ArrayLogger());

        $valve = IPS_CreateVariable(0);
        SetValue($valve, false);

        $result = $controller->validateValvesAsync([
            ['ValveID' => $valve, 'Name' => 'Zone Retry', 'ExpectedValue' => true]
        ], 0);

        $this->assertCount(1, $result['pendingValves']);
        $this->assertSame(1, $result['retryCount']);
        $this->assertSame(1000, $result['timerInterval']);
        $this->assertSame([], $result['clearContexts']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('OpenSingleValve', $result['errors'][0]['context']);
        $this->assertSame(400, $result['errors'][0]['code']);
    }

    public function testValidateValvesAsyncStopsAfterMaxRetries(): void
    {
        $controller = new ValveControl(new ArrayLogger());

        $valve = IPS_CreateVariable(0);
        SetValue($valve, false);

        $result = $controller->validateValvesAsync([
            ['ValveID' => $valve, 'Name' => 'Zone Max Retry', 'ExpectedValue' => true]
        ], 2);

        $this->assertSame([], $result['pendingValves']);
        $this->assertSame(0, $result['retryCount']);
        $this->assertSame(0, $result['timerInterval']);
        $this->assertSame([], $result['clearContexts']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('OpenSingleValve', $result['errors'][0]['context']);
        $this->assertSame(400, $result['errors'][0]['code']);
    }

    public function testEvaluateRuntimeWatchdogReturnsInvalidTrackerForMissingValve(): void
    {
        $controller = new ValveControl(new ArrayLogger());

        $result = $controller->evaluateRuntimeWatchdog(
            trackedValveId: 0,
            openedAt: time() - 120,
            valveObjectExists: false,
            isValveCurrentlyOpen: null,
            maxRuntimeMinutesConfigured: 2,
            currentTimestamp: time()
        );

        $this->assertSame('invalid_tracker', $result['action']);
        $this->assertSame(0.0, $result['runtimeMinutes']);
    }

    public function testEvaluateRuntimeWatchdogReturnsAlreadyClosedWhenValveIsClosed(): void
    {
        $controller = new ValveControl(new ArrayLogger());

        $result = $controller->evaluateRuntimeWatchdog(
            trackedValveId: 123,
            openedAt: time() - 120,
            valveObjectExists: true,
            isValveCurrentlyOpen: false,
            maxRuntimeMinutesConfigured: 3,
            currentTimestamp: time()
        );

        $this->assertSame('already_closed', $result['action']);
        $this->assertSame(0.0, $result['runtimeMinutes']);
    }

    public function testEvaluateRuntimeWatchdogReturnsWithinLimitBeforeTimeout(): void
    {
        $controller = new ValveControl(new ArrayLogger());

        $now = time();
        $result = $controller->evaluateRuntimeWatchdog(
            trackedValveId: 123,
            openedAt: $now - 30,
            valveObjectExists: true,
            isValveCurrentlyOpen: true,
            maxRuntimeMinutesConfigured: 5,
            currentTimestamp: $now
        );

        $this->assertSame('within_limit', $result['action']);
        $this->assertSame(0.5, $result['runtimeMinutes']);
    }

    public function testEvaluateRuntimeWatchdogReturnsTimeoutExceededAfterLimit(): void
    {
        $controller = new ValveControl(new ArrayLogger());

        $now = time();
        $result = $controller->evaluateRuntimeWatchdog(
            trackedValveId: 123,
            openedAt: $now - 130,
            valveObjectExists: true,
            isValveCurrentlyOpen: true,
            maxRuntimeMinutesConfigured: 2,
            currentTimestamp: $now
        );

        $this->assertSame('timeout_exceeded', $result['action']);
        $this->assertGreaterThanOrEqual(2.1, $result['runtimeMinutes']);
        $this->assertStringContainsString('Runtime limit exceeded', $result['debugMessage']);
    }
}
