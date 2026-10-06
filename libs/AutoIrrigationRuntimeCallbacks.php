<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

use Closure;

final class AutoIrrigationRuntimeCallbacks
{
    /** @var Closure(string, string): void */
    public readonly Closure $debugLogger;

    /** @var Closure(): bool */
    public readonly Closure $isAutoEnabled;

    /** @var Closure(): bool */
    public readonly Closure $isMaintenanceEnabled;

    /** @var Closure(): bool */
    public readonly Closure $isStopRequested;

    /** @var Closure(string, string): void */
    public readonly Closure $setRunState;

    /** @var Closure(): array<string, mixed> */
    public readonly Closure $getWindPauseSnapshot;

    /** @var Closure(): array<string, mixed> */
    public readonly Closure $getRainPauseSnapshot;

    /** @var Closure(array<string, mixed>, string): array<string, mixed> */
    public readonly Closure $runResumeReentryPipeline;

    /** @var Closure(array<string, mixed>): array<string, mixed> */
    public readonly Closure $getSoilRuntimeSnapshot;

    /** @var Closure(array<string, mixed>): array<string, mixed> */
    public readonly Closure $getTargetReachedSnapshot;

    /** @var Closure(): array<string, mixed> */
    public readonly Closure $getDailyCutoffSnapshot;

    /** @var Closure(array<string, mixed>, string): void */
    public readonly Closure $handleZoneTerminalAction;

    /** @var Closure(array<string, mixed>, string): void */
    public readonly Closure $handleZonePauseAction;

    /** @var Closure(array<string, mixed>, string): array<string, mixed> */
    public readonly Closure $handleZoneResumeAction;

    public function __construct(
        callable $debugLogger,
        callable $isAutoEnabled,
        callable $isMaintenanceEnabled,
        callable $isStopRequested,
        callable $setRunState,
        callable $getWindPauseSnapshot,
        callable $getRainPauseSnapshot,
        callable $runResumeReentryPipeline,
        callable $getSoilRuntimeSnapshot,
        callable $getTargetReachedSnapshot,
        callable $getDailyCutoffSnapshot,
        callable $handleZoneTerminalAction,
        callable $handleZonePauseAction,
        callable $handleZoneResumeAction
    ) {
        $this->debugLogger = Closure::fromCallable($debugLogger);
        $this->isAutoEnabled = Closure::fromCallable($isAutoEnabled);
        $this->isMaintenanceEnabled = Closure::fromCallable($isMaintenanceEnabled);
        $this->isStopRequested = Closure::fromCallable($isStopRequested);
        $this->setRunState = Closure::fromCallable($setRunState);
        $this->getWindPauseSnapshot = Closure::fromCallable($getWindPauseSnapshot);
        $this->getRainPauseSnapshot = Closure::fromCallable($getRainPauseSnapshot);
        $this->runResumeReentryPipeline = Closure::fromCallable($runResumeReentryPipeline);
        $this->getSoilRuntimeSnapshot = Closure::fromCallable($getSoilRuntimeSnapshot);
        $this->getTargetReachedSnapshot = Closure::fromCallable($getTargetReachedSnapshot);
        $this->getDailyCutoffSnapshot = Closure::fromCallable($getDailyCutoffSnapshot);
        $this->handleZoneTerminalAction = Closure::fromCallable($handleZoneTerminalAction);
        $this->handleZonePauseAction = Closure::fromCallable($handleZonePauseAction);
        $this->handleZoneResumeAction = Closure::fromCallable($handleZoneResumeAction);
    }

    public static function withDefaults(
        callable $debugLogger,
        callable $isAutoEnabled,
        callable $isMaintenanceEnabled,
        callable $isStopRequested,
        callable $setRunState,
        callable $getWindPauseSnapshot,
        callable $getRainPauseSnapshot,
        callable $runResumeReentryPipeline,
        ?callable $getSoilRuntimeSnapshot = null,
        ?callable $getTargetReachedSnapshot = null,
        ?callable $getDailyCutoffSnapshot = null,
        ?callable $handleZoneTerminalAction = null,
        ?callable $handleZonePauseAction = null,
        ?callable $handleZoneResumeAction = null
    ): self {
        return new self(
            $debugLogger,
            $isAutoEnabled,
            $isMaintenanceEnabled,
            $isStopRequested,
            $setRunState,
            $getWindPauseSnapshot,
            $getRainPauseSnapshot,
            $runResumeReentryPipeline,
            $getSoilRuntimeSnapshot ?? static fn (array $zone): array => [
                'mode'                 => 0,
                'source'               => 'none',
                'measuredSoil'         => null,
                'soilMinMoisture'      => 0,
                'confirmWindowSeconds' => 60,
                'sampleCount60s'       => 0,
                'confirmWindowStable'  => false,
            ],
            $getTargetReachedSnapshot ?? static fn (array $zone): array => [
                'elapsedWateringMinutes'  => 0.0,
                'runtimeEffectiveMinutes' => null,
            ],
            $getDailyCutoffSnapshot ?? static fn (): array => [
                'runStartTimestamp' => null,
                'maxRuntimeSeconds' => 0,
            ],
            $handleZoneTerminalAction ?? static function (array $zone, string $reason): void
            {
            },
            $handleZonePauseAction ?? static function (array $zone, string $reason): void
            {
            },
            $handleZoneResumeAction ?? static fn (array $zone, string $reason): array => [
                'opened'       => true,
                'skipZone'     => false,
                'reason'       => 'resume_open_success',
                'debugMessage' => 'Resume reopen handled successfully by default.',
            ]
        );
    }
}
