<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

/**
 * Module-wide error context keys used for ActiveErrors storage.
 *
 * Scope is intentionally broader than auto irrigation. Context keys defined
 * here may be used by maintenance/manual valve control and by automated runs.
 */
final class ModuleErrorContexts
{
    public const VALVE_CONTROL = 'ValveControl';
    public const AUTO_IRRIGATION_FORECAST = 'AutoIrrigationForecast';
    public const AUTO_IRRIGATION_WATER_BALANCE = 'AutoIrrigationWaterBalance';
    public const AUTO_IRRIGATION_SOIL = 'AutoIrrigationSoil';
    public const AUTO_IRRIGATION_CUTOFF = 'AutoIrrigationCutoff';
    public const AUTO_IRRIGATION_RUNTIME = 'AutoIrrigationRuntime';

    /**
     * Normalizes technical contexts to stable ActiveErrors keys.
     *
     * Example: CloseAllValves/OpenSingleValve are grouped into ValveControl so
     * the frontend and status handling can evaluate one consolidated context.
     *
     * Technical rule: map operation-specific error contexts to a canonical key
     * before they are written into persistent ActiveErrors storage.
     * Functional behavior: related errors collapse into one stable bucket instead
     * of creating duplicate status entries for the same fault family.
     * Domain rationale: a single valve subsystem fault should be tracked as one
     * operational issue so irrigation decisions and status lights remain readable.
     */
    public static function normalize(string $context): string
    {
        return match ($context) {
            'CloseAllValves', 'OpenSingleValve' => self::VALVE_CONTROL,
            self::AUTO_IRRIGATION_FORECAST,
            self::AUTO_IRRIGATION_WATER_BALANCE,
            self::VALVE_CONTROL,
            self::AUTO_IRRIGATION_SOIL,
            self::AUTO_IRRIGATION_CUTOFF,
            self::AUTO_IRRIGATION_RUNTIME => $context,
            default                       => $context,
        };
    }
}
