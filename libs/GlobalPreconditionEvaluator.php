<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

final class GlobalPreconditionEvaluator
{
    /**
     * Evaluates the global prerequisites shared by autonomous watering modes.
     *
     * @param array<string, int> $activeErrors
     * @return array{allowed: bool, reason: string, debugMessage: string}
     */
    public function evaluate(
        bool $moduleConfigurationInvalid,
        bool $autoEnabled,
        bool $maintenanceEnabled,
        array $activeErrors,
        string $lastValveOperationType,
        bool $retryLimitReached
    ): array {
        if ($moduleConfigurationInvalid) {
            return [
                'allowed'      => false,
                'reason'       => LawnCoolingContracts::STOP_REASON_GLOBAL_CONFIGURATION_INVALID,
                'debugMessage' => 'Global recheck failed: module configuration is invalid.',
            ];
        }

        if (!$autoEnabled) {
            return [
                'allowed'      => false,
                'reason'       => LawnCoolingContracts::STOP_REASON_GLOBAL_PRECHECK_FAIL,
                'debugMessage' => 'Global recheck failed: automatic mode is disabled.',
            ];
        }

        if ($maintenanceEnabled) {
            return [
                'allowed'      => false,
                'reason'       => LawnCoolingContracts::STOP_REASON_GLOBAL_PRECHECK_FAIL,
                'debugMessage' => 'Global recheck failed: maintenance mode is active.',
            ];
        }

        $hardStartBlocker = (new ValveStartBlockerEvaluator())->evaluate(
            $activeErrors,
            $lastValveOperationType,
            $retryLimitReached
        );

        if ($hardStartBlocker['blocked']) {
            return [
                'allowed'      => false,
                'reason'       => LawnCoolingContracts::STOP_REASON_GLOBAL_PRECHECK_FAIL,
                'debugMessage' => 'Global recheck failed: ' . $hardStartBlocker['debugMessage'],
            ];
        }

        return [
            'allowed'      => true,
            'reason'       => '',
            'debugMessage' => 'Global recheck succeeded.',
        ];
    }
}
