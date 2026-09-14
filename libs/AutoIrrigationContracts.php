<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

/**
 * Auto irrigation specific contracts only.
 *
 * This class is intentionally limited to orchestrator runtime identifiers and
 * allowed run states used by AutoIrrigationRunState. It does not own module-
 * wide error context normalization.
 */
final class AutoIrrigationContracts
{
    public const TIMER_IDENT_DAILY = 'AutoIrrigationDaily';
    public const TIMER_IDENT_RUNTIME = 'AutoIrrigationRuntimeTick';
    public const RUNTIME_TICK_INTERVAL_MS = 5000;
    public const STOP_SIGNAL_BUFFER_KEY = 'AutoIrrigationStopRequested';
    public const RUN_LOCK_KEY = 'AUTO_IRRIGATION_RUN_LOCK';

    public const BUFFER_KEY_PREPARED_ZONES = 'AutoIrrigationPreparedZones';
    public const BUFFER_KEY_CURRENT_ZONE_WATERED = 'AutoIrrigationCurrentZoneWatered';
    public const BUFFER_KEY_CURRENT_ZONE_RUNTIME_EFFECTIVE_MINUTES = 'AutoIrrigationCurrentZoneRuntimeEffectiveMinutes';
    public const BUFFER_KEY_CURRENT_ZONE_ELAPSED_WATERING_SECONDS = 'AutoIrrigationCurrentZoneElapsedWateringSeconds';
    public const BUFFER_KEY_CURRENT_ZONE_INDEX = 'AutoIrrigationCurrentZoneIndex';
    public const BUFFER_KEY_CURRENT_ZONE_PAUSE_REASON = 'AutoIrrigationCurrentZonePauseReason';
    public const BUFFER_KEY_RUN_START_TIMESTAMP = 'AutoIrrigationRunStartTimestamp';
    public const BUFFER_KEY_VALVE_RUNTIME_TRACKER = 'ValveRuntimeTracker';
    public const BUFFER_KEY_PENDING_VALVE_VALIDATION = 'PendingValveValidation';
    public const BUFFER_KEY_VALIDATE_RETRY_COUNT = 'ValidateRetryCount';
    public const BUFFER_KEY_LAST_VALVE_OPERATION_TYPE = 'LastValveOperationType';
    public const BUFFER_KEY_VALVE_VALIDATION_RETRY_LIMIT_REACHED = 'ValveValidationRetryLimitReached';

    public const RUN_STATE_IDENT = 'AutoIrrigationRunState';
    public const RUN_STATE_PRECHECK = 'PRECHECK';
    public const RUN_STATE_RUNNING = 'RUNNING';
    public const RUN_STATE_PAUSED_WIND = 'PAUSED_WIND';
    public const RUN_STATE_PAUSED_RAIN = 'PAUSED_RAIN';
    public const RUN_STATE_STOPPED = 'STOPPED';
    public const RUN_STATE_FINISHED = 'FINISHED';

    public const RUNTIME_OUTCOME_CONTINUE = 'continue';
    public const RUNTIME_OUTCOME_ZONE_ACTIVE = 'zone_active';
    public const RUNTIME_OUTCOME_ZONE_STOP_AND_NEXT = 'zone_stop_and_next';
    public const RUNTIME_OUTCOME_PAUSED_WIND = 'paused_wind';
    public const RUNTIME_OUTCOME_PAUSED_RAIN = 'paused_rain';
    public const RUNTIME_OUTCOME_STOPPED = 'stopped';

    /**
     * Returns the complete, ordered set of allowed auto-run states.
     *
     * The frontend variable AutoIrrigationRunState shows only this runtime
     * state machine and not the general module error state.
     *
     * @return array<int, string>
     */
    public static function getAllowedRunStates(): array
    {
        return [
            self::RUN_STATE_PRECHECK,
            self::RUN_STATE_RUNNING,
            self::RUN_STATE_PAUSED_WIND,
            self::RUN_STATE_PAUSED_RAIN,
            self::RUN_STATE_STOPPED,
            self::RUN_STATE_FINISHED,
        ];
    }

    public static function isValidRunState(string $state): bool
    {
        return in_array($state, self::getAllowedRunStates(), true);
    }

}
