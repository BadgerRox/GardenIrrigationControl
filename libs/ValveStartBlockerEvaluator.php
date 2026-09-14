<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

final class ValveStartBlockerEvaluator
{
    /**
     * @param array<string, int> $activeErrors
     * @return array{blocked: bool, reason: string, debugMessage: string}
     */
    public function evaluate(array $activeErrors, string $lastValveOperationType, bool $retryLimitReached): array
    {
        if (!isset($activeErrors[ModuleErrorContexts::VALVE_CONTROL])) {
            return [
                'blocked'      => false,
                'reason'       => '',
                'debugMessage' => '',
            ];
        }

        if ($lastValveOperationType === 'open') {
            return [
                'blocked'      => false,
                'reason'       => 'ValveControl failure after opening is a zone-local soft blocker.',
                'debugMessage' => 'Hard start check: the valve failure came from an opening operation and does not block the daily start.',
            ];
        }

        if ($lastValveOperationType === 'close') {
            return [
                'blocked'      => true,
                'reason'       => 'Valve close failure blocks the daily start.',
                'debugMessage' => $retryLimitReached
                    ? 'Hard start check: the valve close failure reached the maximum retry count, so the daily run is blocked safely.'
                    : 'Hard start check: an active valve close failure prevents the daily run.',
            ];
        }

        return [
            'blocked'      => true,
            'reason'       => 'Valve failure blocks the daily start.',
            'debugMessage' => 'Hard start check: an active valve failure has no unambiguous operation marker, so the daily run is blocked safely.',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $valvesToValidate
     */
    public function determineLastValveOperationTypeFromValves(array $valvesToValidate): string
    {
        $hasOpenFailure = false;

        foreach ($valvesToValidate as $valve) {
            $expectedValue = (bool) ($valve['ExpectedValue'] ?? false);
            if ($expectedValue === false) {
                return 'close';
            }

            $hasOpenFailure = true;
        }

        return $hasOpenFailure ? 'open' : '';
    }
}
