<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

/**
 * Lawn cooling specific contracts.
 *
 * Defines orchestrator runtime identifiers, allowed run states, buffer keys,
 * and signal contracts for the lawn cooling feature.
 */
final class LawnCoolingContracts
{
    // Timer identifiers
    public const TIMER_IDENT_DAILY = 'LawnCoolingDaily';
    public const TIMER_IDENT_PRECHECK = 'LawnCoolingPrecheckTimer';
    public const TIMER_IDENT_RUNTIME = 'LawnCoolingRuntimeTick';
    public const PRECHECK_OFFSET_MINUTES = 5;
    public const PRECHECK_PUSH_MESSAGE_KEY = 'lawn_cooling.precheck.allow';
    public const RUNTIME_TICK_INTERVAL_MS = 5000;

    // Buffer keys for runtime state
    public const STOP_SIGNAL_BUFFER_KEY = 'LawnCoolingStopRequested';
    public const SKIP_SIGNAL_BUFFER_KEY = 'LawnCoolingSkipCurrentRun';
    public const FIRST_VALVE_OPENED_BUFFER_KEY = 'LawnCoolingFirstValveOpened';
    public const PRECHECK_ACTIVE_BUFFER_KEY = 'LawnCoolingPrecheckActive';
    public const RUN_LOCK_KEY = 'LAWN_COOLING_RUN_LOCK';
    public const BUFFER_KEY_PREPARED_ZONES = 'LawnCoolingPreparedZones';
    public const BUFFER_KEY_CURRENT_ZONE_INDEX = 'LawnCoolingCurrentZoneIndex';
    public const BUFFER_KEY_ZONE_STARTED_AT = 'LawnCoolingZoneStartedAt';

    // Frontend variable identifiers
    public const RUN_STATE_IDENT = 'LawnCoolingRunState';
    public const SKIP_VARIABLE_IDENT = 'LawnCoolingSkipCurrentRun';

    // Run states
    public const RUN_STATE_PRECHECK = 'PRECHECK';
    public const RUN_STATE_RUNNING = 'RUNNING';
    public const RUN_STATE_STOPPED = 'STOPPED';
    public const RUN_STATE_FINISHED = 'FINISHED';

    // StopReason codes
    public const STOP_REASON_GLOBAL_PRECHECK_FAIL = 'COOL_GLOBAL_PRECHECK_FAIL';
    public const STOP_REASON_GLOBAL_CONFIGURATION_INVALID = 'COOL_GLOBAL_CONFIGURATION_INVALID';
    public const STOP_REASON_GLOBAL_TIMER_WINDOW_BLOCKED = 'COOL_GLOBAL_TIMER_WINDOW_BLOCKED';
    public const STOP_REASON_GLOBAL_STOP_REQUESTED = 'COOL_GLOBAL_STOP_REQUESTED';
    public const STOP_REASON_GLOBAL_VALVE_CLOSE_FAILED = 'COOL_GLOBAL_VALVE_CLOSE_FAILED';
    public const STOP_REASON_ZONE_NO_VALID_TEMPERATURE_ARCHIVE = 'COOL_ZONE_NO_VALID_TEMPERATURE_ARCHIVE';
    public const STOP_REASON_ZONE_TEMP_THRESHOLD_NOT_REACHED = 'COOL_ZONE_TEMP_THRESHOLD_NOT_REACHED';
    public const STOP_REASON_ZONE_RAIN_6H_EXCEEDED = 'COOL_ZONE_RAIN_6H_EXCEEDED';
    public const STOP_REASON_ZONE_SOIL_START_BLOCKED = 'COOL_ZONE_SOIL_START_BLOCKED';
    public const STOP_REASON_ZONE_NO_ACTIVE_COOLING_ZONES = 'COOL_ZONE_NO_ACTIVE_COOLING_ZONES';
    public const STOP_REASON_ZONE_VALVE_OPEN_FAILED = 'COOL_ZONE_VALVE_OPEN_FAILED';
    public const STOP_REASON_ZONE_SKIP_TODAY = 'COOL_ZONE_SKIP_TODAY';

    /**
     * Returns the complete set of global hard-stop codes used by lawn cooling.
     *
     * @return array<int, string>
     */
    public static function globalStopReasons(): array
    {
        return [
            self::STOP_REASON_GLOBAL_PRECHECK_FAIL,
            self::STOP_REASON_GLOBAL_CONFIGURATION_INVALID,
            self::STOP_REASON_GLOBAL_TIMER_WINDOW_BLOCKED,
            self::STOP_REASON_GLOBAL_STOP_REQUESTED,
            self::STOP_REASON_GLOBAL_VALVE_CLOSE_FAILED,
        ];
    }

    /**
     * Returns the complete set of zone-local soft-blocker codes used by lawn cooling.
     *
     * @return array<int, string>
     */
    public static function zoneStopReasons(): array
    {
        return [
            self::STOP_REASON_ZONE_NO_VALID_TEMPERATURE_ARCHIVE,
            self::STOP_REASON_ZONE_TEMP_THRESHOLD_NOT_REACHED,
            self::STOP_REASON_ZONE_RAIN_6H_EXCEEDED,
            self::STOP_REASON_ZONE_SOIL_START_BLOCKED,
            self::STOP_REASON_ZONE_NO_ACTIVE_COOLING_ZONES,
            self::STOP_REASON_ZONE_VALVE_OPEN_FAILED,
            self::STOP_REASON_ZONE_SKIP_TODAY,
        ];
    }

    /**
     * Returns the complete, ordered set of allowed lawn cooling run states.
     *
     * The frontend variable LawnCoolingRunState shows only this runtime
     * state machine and not the general module error state.
     *
     * @return array<int, string>
     */
    public static function allowedRunStates(): array
    {
        return [
            self::RUN_STATE_PRECHECK,
            self::RUN_STATE_RUNNING,
            self::RUN_STATE_STOPPED,
            self::RUN_STATE_FINISHED,
        ];
    }

    /**
     * Returns whether a skip decision is still valid before the first valve is opened.
     *
     * The lawn-cooling policy distinguishes a user-driven skip from a true run stop:
     * before the first valve opens, a skip is a soft cancellation for the current day;
     * after the first opening, only an enforced hard stop is allowed because the system
     * has already committed to active irrigation work.
     */
    public static function isSkipSignalAllowedBeforeFirstValve(bool $firstValveOpened): bool
    {
        return !$firstValveOpened;
    }

    /**
     * Returns whether a current skip request must be treated as a hard stop once the
     * lawn cooling loop has already opened at least one valve.
     */
    public static function requiresHardStopAfterFirstValve(bool $firstValveOpened): bool
    {
        return $firstValveOpened;
    }
}
