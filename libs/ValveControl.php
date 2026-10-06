<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

use Exception;

class ValveControl
{
    private ?DebugLoggerInterface $logger;

    public function __construct(?DebugLoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Close all valves configured in the zones tree for safety purposes.
     * Sends close command to all valves and returns validation payload for async handling.
     *
     * @param array<int, array<string, mixed>> $zones
     * @return array{success: bool, valvesToValidate: array<int, array{ValveID:int, Name:string, ExpectedValue:bool}>, errors: array<int, array{context:string, translationKey:string, parameters:array<int, scalar>, code:int}>}
     */
    public function closeAllValves(array $zones): array
    {
        $errors = [];

        if (empty($zones)) {
            return [
                'success'          => true,
                'valvesToValidate' => [],
                'errors'           => []
            ];
        }

        $valvesToValidate = [];

        foreach ($zones as $zone) {
            if (!isset($zone['ValveVarID']) || (int) $zone['ValveVarID'] <= 0 || !IPS_ObjectExists((int) $zone['ValveVarID'])) {
                continue;
            }

            $valveID = (int) $zone['ValveVarID'];
            $zoneName = (string) ($zone['Name'] ?? 'unknown');

            $this->logger?->debug(
                'CloseAllValves',
                'Closing valve for zone: ' . $zoneName . ' (ID: ' . $valveID . ')'
            );

            try {
                $variableInfo = IPS_GetVariable($valveID);

                if ($variableInfo['VariableAction'] > 0) {
                    RequestAction($valveID, false);
                } else {
                    SetValue($valveID, false);
                }
            } catch (Exception $e) {
                $errors[] = [
                    'context'        => 'CloseAllValves',
                    'translationKey' => 'error.valve.close_command_failed',
                    'parameters'     => [$valveID, $e->getMessage()],
                    'code'           => 401
                ];
                continue;
            }

            $valvesToValidate[] = [
                'ValveID'       => $valveID,
                'Name'          => $zoneName,
                'ExpectedValue' => false
            ];
        }

        if (count($valvesToValidate) > 0) {
            $this->logger?->debug(
                'CloseAllValves',
                count($valvesToValidate) . ' valve(s) prepared for asynchronous validation. Timer starts in 1000 ms'
            );
        }

        return [
            'success'          => count($errors) === 0,
            'valvesToValidate' => $valvesToValidate,
            'errors'           => $errors
        ];
    }

    /**
     * Close all valves, then open a single specific valve exclusively.
     * Returns validation payload for async handling.
     *
     * Every configured valve is intentionally closed before the target valve is opened.
     * This provides a fail-safe exclusivity boundary even when a valve was changed externally,
     * runtime state was lost during a restart, or the previous close operation was incomplete.
     * The additional O(Z) actuator work per zone switch is accepted in favor of preventing
     * overlapping irrigation and unknown valve states.
     *
     * @param int $valveID The IP-Symcon variable ID of the valve to open
     * @param array<int, array<string, mixed>> $zones
     * @return array{success: bool, valvesToValidate: array<int, array{ValveID:int, Name:string, ExpectedValue:bool}>, errors: array<int, array{context:string, translationKey:string, parameters:array<int, scalar>, code:int}>}
     */
    public function openSingleValve(int $valveID, array $zones): array
    {
        if ($valveID <= 0 || !IPS_ObjectExists($valveID)) {
            $this->logger?->debug('OpenSingleValve', 'Invalid valve ID: ' . $valveID);
            return [
                'success'          => false,
                'valvesToValidate' => [],
                'errors'           => []
            ];
        }

        $closeResult = $this->closeAllValves($zones);

        if (!$closeResult['success']) {
            $this->logger?->debug(
                'OpenSingleValve',
                'Aborted: not all valves could be closed safely. The target valve will not be opened.'
            );

            return [
                'success'          => false,
                'valvesToValidate' => $closeResult['valvesToValidate'],
                'errors'           => $closeResult['errors']
            ];
        }

        $this->logger?->debug('OpenSingleValve', 'Opening selected valve with ID: ' . $valveID);

        try {
            $variableInfo = IPS_GetVariable($valveID);

            if ($variableInfo['VariableAction'] > 0) {
                RequestAction($valveID, true);
            } else {
                SetValue($valveID, true);
            }
        } catch (Exception $e) {
            $closeResult['errors'][] = [
                'context'        => 'OpenSingleValve',
                'translationKey' => 'error.valve.open_command_failed',
                'parameters'     => [$valveID, $e->getMessage()],
                'code'           => 400
            ];

            return [
                'success'          => false,
                'valvesToValidate' => $closeResult['valvesToValidate'],
                'errors'           => $closeResult['errors']
            ];
        }

        $this->logger?->debug(
            'OpenSingleValve',
            'Valve ' . $valveID . ' prepared for asynchronous validation. Timer starts in 1000 ms'
        );

        $valvesToValidate = [];
        foreach ($closeResult['valvesToValidate'] as $valveToValidate) {
            $candidateValveId = (int) $valveToValidate['ValveID'];
            if ($candidateValveId <= 0) {
                continue;
            }

            $valvesToValidate[$candidateValveId] = [
                'ValveID'       => $candidateValveId,
                'Name'          => (string) $valveToValidate['Name'],
                'ExpectedValue' => false
            ];
        }

        // The target valve must be exclusively open after all valves have been closed.
        $valvesToValidate[$valveID] = [
            'ValveID'       => $valveID,
            'Name'          => 'Target valve',
            'ExpectedValue' => true
        ];

        return [
            'success'          => true,
            'valvesToValidate' => array_values($valvesToValidate),
            'errors'           => $closeResult['errors']
        ];
    }

    /**
     * Validates deferred valve states and returns orchestrator instructions.
     *
     * @param array<int, array{ValveID?:int, Name?:string, ExpectedValue?:bool}> $valvesToValidate
     * @return array{pendingValves: array<int, array{ValveID:int, Name:string, ExpectedValue:bool}>, retryCount: int, timerInterval: int, clearContexts: array<int, string>, errors: array<int, array{context:string, translationKey:string, parameters:array<int, scalar>, code:int}>}
     */
    public function validateValvesAsync(array $valvesToValidate, int $retryCount): array
    {
        if (empty($valvesToValidate)) {
            $this->logger?->debug('ValidateValvesAsync', 'Invalid buffer data; resetting.');

            return [
                'pendingValves' => [],
                'retryCount'    => 0,
                'timerInterval' => 0,
                'clearContexts' => [],
                'errors'        => []
            ];
        }

        $this->logger?->debug(
            'ValidateValvesAsync',
            'Validating ' . count($valvesToValidate) . ' delayed valve(s) after 1000 ms...'
        );

        $stillFailing = [];
        $errors = [];

        foreach ($valvesToValidate as $valve) {
            $valveID = (int) ($valve['ValveID'] ?? 0);
            $expectedValue = (bool) ($valve['ExpectedValue'] ?? false);
            $name = (string) ($valve['Name'] ?? 'Unbekannt');

            if ($valveID <= 0) {
                continue;
            }

            try {
                $currentValue = GetValue($valveID);
                $statusText = $currentValue ? 'OPEN' : 'CLOSED';
                $expectedText = $expectedValue ? 'OPEN' : 'CLOSED';

                $this->logger?->debug(
                    'ValidateValvesAsync',
                    'Valve ' . $valveID . ' (' . $name . '): actual=' . $statusText . ' expected=' . $expectedText
                );

                if ($currentValue !== $expectedValue) {
                    $stillFailing[] = [
                        'ValveID'       => $valveID,
                        'Name'          => $name,
                        'ExpectedValue' => $expectedValue
                    ];

                    $this->logger?->debug(
                        'ValidateValvesAsync',
                        'Valve ' . $valveID . ' (' . $name . ') ERROR - expected state was not reached!'
                    );

                    $contextName = $expectedValue ? 'OpenSingleValve' : 'CloseAllValves';
                    $errors[] = [
                        'context'        => $contextName,
                        'translationKey' => 'error.valve.target_state_not_reached',
                        'parameters'     => [$valveID, $expectedText, $statusText],
                        'code'           => 400
                    ];
                } else {
                    $this->logger?->debug(
                        'ValidateValvesAsync',
                        'Valve ' . $valveID . ' (' . $name . ') OK - expected state reached.'
                    );
                }
            } catch (Exception $e) {
                $this->logger?->debug(
                    'ValidateValvesAsync',
                    'Exception while validating valve ' . $valveID . ': ' . $e->getMessage()
                );
                $stillFailing[] = [
                    'ValveID'       => $valveID,
                    'Name'          => $name,
                    'ExpectedValue' => $expectedValue
                ];
            }
        }

        if (count($stillFailing) === 0) {
            $this->logger?->debug('ValidateValvesAsync', 'All delayed valves validated successfully.');

            return [
                'pendingValves' => [],
                'retryCount'    => 0,
                'timerInterval' => 0,
                'clearContexts' => ['CloseAllValves', 'OpenSingleValve'],
                'errors'        => []
            ];
        }

        $maxRetries = 2;

        if ($retryCount < $maxRetries) {
            $nextRetry = $retryCount + 1;

            $this->logger?->debug(
                'ValidateValvesAsync',
                'Retry ' . $nextRetry . '/' . $maxRetries . ': ' . count($stillFailing) . ' valve(s) still failing. Scheduling another attempt...'
            );

            return [
                'pendingValves' => $stillFailing,
                'retryCount'    => $nextRetry,
                'timerInterval' => 1000,
                'clearContexts' => [],
                'errors'        => $errors
            ];
        }

        $this->logger?->debug(
            'ValidateValvesAsync',
            'Maximum retries reached. ' . count($stillFailing) . ' valve(s) remain permanently faulty.'
        );

        return [
            'pendingValves' => [],
            'retryCount'    => 0,
            'timerInterval' => 0,
            'clearContexts' => [],
            'errors'        => $errors
        ];
    }

    /**
     * Evaluates the runtime watchdog decision without executing side effects.
     *
     * @return array{action: string, runtimeMinutes: float, maxRuntimeMinutes: int, debugMessage: string}
     */
    public function evaluateRuntimeWatchdog(
        int $trackedValveId,
        int $openedAt,
        bool $valveObjectExists,
        ?bool $isValveCurrentlyOpen,
        int $maxRuntimeMinutesConfigured,
        int $currentTimestamp
    ): array {
        if ($trackedValveId <= 0 || $openedAt <= 0 || !$valveObjectExists) {
            return [
                'action'            => 'invalid_tracker',
                'runtimeMinutes'    => 0.0,
                'maxRuntimeMinutes' => $maxRuntimeMinutesConfigured,
                'debugMessage'      => 'Invalid tracker state detected. Runtime supervision is being reset.'
            ];
        }

        if ($isValveCurrentlyOpen === false) {
            return [
                'action'            => 'already_closed',
                'runtimeMinutes'    => 0.0,
                'maxRuntimeMinutes' => $maxRuntimeMinutesConfigured,
                'debugMessage'      => 'Valve is already closed. Runtime supervision is ending.'
            ];
        }

        // Technically: enforce a minimum hard cap of 60 seconds even when configured minutes are very small or invalid.
        // Functional behavior: the watchdog only signals timeout_exceeded after this bounded cap is reached.
        // Domain rationale: a minimum runtime guard prevents noisy stop/start oscillation while still limiting over-irrigation and hardware stress.
        $maxRuntimeSeconds = max(60, $maxRuntimeMinutesConfigured * 60);
        $runtimeSeconds = max(0, $currentTimestamp - $openedAt);

        if ($runtimeSeconds < $maxRuntimeSeconds) {
            return [
                'action'            => 'within_limit',
                'runtimeMinutes'    => round($runtimeSeconds / 60, 1),
                'maxRuntimeMinutes' => $maxRuntimeMinutesConfigured,
                'debugMessage'      => ''
            ];
        }

        $runtimeMinutes = round($runtimeSeconds / 60, 1);

        return [
            'action'            => 'timeout_exceeded',
            'runtimeMinutes'    => $runtimeMinutes,
            'maxRuntimeMinutes' => $maxRuntimeMinutesConfigured,
            'debugMessage'      => 'Runtime limit exceeded (' . $runtimeMinutes . ' min > ' . $maxRuntimeMinutesConfigured . ' min). Starting safety shutdown through CloseAllValves().'
        ];
    }
}
