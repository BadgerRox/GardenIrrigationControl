<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Autoload.php';
gardenIrrigationControlRegisterAutoload();

use GardenIrrigationControl\Libs\AutoIrrigationContracts;
use GardenIrrigationControl\Libs\AutoIrrigationPauseSnapshotBuilder;
use GardenIrrigationControl\Libs\AutoIrrigationRuntimeCallbacks;
use GardenIrrigationControl\Libs\AutoIrrigationRuntimeLoopRunner;
use GardenIrrigationControl\Libs\AutoIrrigationZoneStartPlanner;
use GardenIrrigationControl\Libs\BaseLineEToCalculator;
use GardenIrrigationControl\Libs\ConfigurationValidator;
use GardenIrrigationControl\Libs\DebugLoggerInterface;
use GardenIrrigationControl\Libs\EToHistoryUpdater;
use GardenIrrigationControl\Libs\GlobalPreconditionEvaluator;
use GardenIrrigationControl\Libs\LawnCoolingContracts;
use GardenIrrigationControl\Libs\LawnCoolingRainGate;
use GardenIrrigationControl\Libs\LawnCoolingRuntimeOrchestrator;
use GardenIrrigationControl\Libs\LawnCoolingTemperatureGate;
use GardenIrrigationControl\Libs\LawnCoolingZoneCandidateService;
use GardenIrrigationControl\Libs\LawnCoolingZoneStartPlanner;
use GardenIrrigationControl\Libs\LocalizedException;
use GardenIrrigationControl\Libs\LocalizedInvalidArgumentException;
use GardenIrrigationControl\Libs\LocalizedRuntimeException;
use GardenIrrigationControl\Libs\ModuleErrorContexts;
use GardenIrrigationControl\Libs\ModuleLogger;
use GardenIrrigationControl\Libs\PushNotificationSender;
use GardenIrrigationControl\Libs\RainForecastCalculator;
use GardenIrrigationControl\Libs\RainHistoryCalculator;
use GardenIrrigationControl\Libs\SimpleHttpClient;
use GardenIrrigationControl\Libs\SoilRuntimeSnapshotBuilder;
use GardenIrrigationControl\Libs\ValidationResult;
use GardenIrrigationControl\Libs\ValveControl;
use GardenIrrigationControl\Libs\ValveStartBlockerEvaluator;
use GardenIrrigationControl\Libs\WaterBalanceManager;
use GardenIrrigationControl\Libs\ZoneStartPipelineService;

class GardenIrrigationControl extends IPSModuleStrict
{
    private const MODULE_STATUS_NOT_CONFIGURED = 104;
    private const MODULE_STATUS_ERROR_MIN = 200;
    private const MODULE_STATUS_ERROR_MAX = 299;

    private ?ValveControl $valveControl = null;
    private ?AutoIrrigationPauseSnapshotBuilder $pauseSnapshotBuilder = null;
    private ?SoilRuntimeSnapshotBuilder $soilRuntimeSnapshotBuilder = null;
    private ?ZoneStartPipelineService $zoneStartPipelineService = null;
    private ?LawnCoolingZoneStartPlanner $lawnCoolingZoneStartPlanner = null;
    private ?LawnCoolingZoneCandidateService $lawnCoolingZoneCandidateService = null;
    private ?LawnCoolingRuntimeOrchestrator $lawnCoolingRuntimeOrchestrator = null;

    /**
     * Returns a debug logger created once for this module instance.
     *
     * The logger is cached so that repeated calculation calls do not
     * have to create a new adapter instance each time.
     */
    private ?DebugLoggerInterface $debugLogger = null;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $zonesTreeCache = null;

    public function Create(): void
    {
        parent::Create();

        // 1. Location, weather & sensors (required)
        $this->RegisterPropertyString('SystemType', 'lawn');
        $this->RegisterPropertyString('Location', '');
        $this->RegisterPropertyInteger('TemperatureVarID', 0);
        $this->RegisterPropertyInteger('HumidityVarID', 0);
        $this->RegisterPropertyInteger('RainVarID', 0);
        $this->RegisterPropertyInteger('RainDayVarID', 0);
        $this->RegisterPropertyInteger('PressureVarID', 0);
        $this->RegisterPropertyInteger('WindVarID', 0);
        $this->RegisterPropertyFloat('WindHeight', 2.0);
        $this->RegisterPropertyInteger('WindMaxSpeed', 20);
        $this->RegisterPropertyBoolean('RainForecast', false);

        // 2. Average evaporation (required)
        $this->RegisterPropertyInteger('BaselineStartMonth', 5);
        $this->RegisterPropertyInteger('BaselineEndMonth', 9);
        $this->RegisterPropertyFloat('BaselineETO', 4.0);

        // 3. Calculation & runtimes (required)
        $this->RegisterPropertyInteger('IrrigationInterval', 3);
        $this->RegisterPropertyString('IrrigationStartTime', '{"hour":2,"minute":0,"second":0}');
        $this->RegisterPropertyString('IrrigationMaxRuntime', '{"hour":4,"minute":0,"second":0}');
        $this->RegisterPropertyFloat('IrrigationMinRuntime', 1.0);
        $this->RegisterPropertyInteger('IrrigationZoneMaxRuntime', 60);

        // 4. Soil moisture (optional)
        $this->RegisterPropertyInteger('GlobalSoilMoistureVarID', 0);
        $this->RegisterPropertyInteger('SoilMinMoisture', 40);
        $this->RegisterPropertyInteger('SoilMoistureMode', 0);

        // 5. Lawn cooling (optional)
        $this->RegisterPropertyBoolean('CoolingEnable', false);
        $this->RegisterPropertyString('CoolingStartTime', '{"hour":13,"minute":0,"second":0}');
        $this->RegisterPropertyInteger('CoolingTime', 5);
        $this->RegisterPropertyInteger('CoolingTemp', 28);
        $this->RegisterPropertyBoolean('CoolingPushEnable', false);
        $this->RegisterPropertyInteger('CoolingPushInstance', 0);

        // 6. Zone configuration (the tree is stored as a JSON string)
        $this->RegisterPropertyString('ZonesTree', '[]');

        // 7. Maintenance & miscellaneous
        $this->RegisterPropertyBoolean('SystemAutoEnable', true);
        $this->RegisterPropertyBoolean('SystemMaintenanceEnable', false);

        // Attributes and Other Objects
        $this->RegisterAttributeString('DailyEToHistory', '{}'); // ETo values from the last 14 days (from yesterday backward) are stored in this value
        $this->RegisterAttributeString('ActiveErrors', '[]'); // Active errors are stored in this value
        $this->RegisterAttributeString('WaterBalanceManager', '[]'); // Irrigation histories, ETo values, and rainfall amounts of the individual zones are stored in this value
        $this->RegisterAttributeString('WaterStorageAnchor', '{}'); // Persistent water storage per zone including booked daily deltas. These values come from the WaterBalanceManager.

        // Register asynchronous valve validation timer
        $this->RegisterTimer('ValidateValvesAsync', 0, 'IPS_RequestAction($_IPS["TARGET"], "ValidateValvesAsync", "");');
        $this->RegisterTimer('ValveRuntimeWatchdog', 0, 'IPS_RequestAction($_IPS["TARGET"], "ValveRuntimeWatchdog", "");');
        $this->RegisterTimer('UpdateWaterBalanceHistoryTimer', 0, 'IPS_RequestAction($_IPS["TARGET"], "UpdateWaterBalanceHistoryTimer", "");');
        $this->RegisterTimer(AutoIrrigationContracts::TIMER_IDENT_DAILY, 0, 'IPS_RequestAction($_IPS["TARGET"], "AutoIrrigationDaily", "");');
        $this->RegisterTimer(AutoIrrigationContracts::TIMER_IDENT_RUNTIME, 0, 'IPS_RequestAction($_IPS["TARGET"], "AutoIrrigationRuntimeTick", "");');

        // Register lawn cooling timers
        $this->RegisterTimer(LawnCoolingContracts::TIMER_IDENT_DAILY, 0, 'IPS_RequestAction($_IPS["TARGET"], "LawnCoolingDaily", "");');
        $this->RegisterTimer(LawnCoolingContracts::TIMER_IDENT_PRECHECK, 0, 'IPS_RequestAction($_IPS["TARGET"], "CoolingPrecheckTimer", "");');
        $this->RegisterTimer(LawnCoolingContracts::TIMER_IDENT_RUNTIME, 0, 'IPS_RequestAction($_IPS["TARGET"], "LawnCoolingRuntimeTick", "");');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->zonesTreeCache = null;

        $this->SendDebug('ApplyChanges', 'Checking configuration...', 0);
        $this->SetTimerInterval('UpdateWaterBalanceHistoryTimer', 0);
        $validator = new ConfigurationValidator();

        // ==========================================
        // 0. BASE + ARCHIVE + SYSTEM TYPE NORMALIZATION
        // ==========================================
        // Validate base fields and resolve the archive before checking dependent configuration.
        $baseAndArchive = $this->validateBaseAndArchive($validator);
        if ($baseAndArchive === null) {
            return;
        }

        // ==========================================
        // 1. LOCATION, WEATHER, BASELINE, RUNTIME, SOIL, COOLING
        // ==========================================
        // Validate weather, baseline, runtime, soil and cooling settings.
        if (!$this->validateDomainConfig($validator, $baseAndArchive['archiveHandlerID'], $baseAndArchive['systemType'])) {
            return;
        }

        // ==========================================
        // 2. ZONE TREE VALIDATION
        // ==========================================
        // Validate the zone tree, including archive/type and uniqueness constraints.
        $zoneValidation = $this->validateZones($validator, $baseAndArchive['archiveHandlerID']);
        if ($zoneValidation === null) {
            return;
        }

        // ==========================================
        // 3. MAINTENANCE, STATUS, FRONTEND VARIABLES, TIMERS
        // ==========================================
        // Finalize status, frontend variables, safe mode transitions and timers.
        $this->finalizeFrontendAndTimers($validator, $zoneValidation['zones'], $zoneValidation['frontendValves']);
    }

    /**
     * Processes commands and clicks from the front end (WebFront / Tile View).
     *
     * @param string $ident The identifier of the variable that was toggled
     * @param mixed $value The new value to be set
     * @return void
     */
    public function RequestAction(string $ident, mixed $value): void
    {
        if ($this->dispatchInternalTimerCallback($ident)) {
            return;
        }

        // Log user-triggered actions only (not internal timer callbacks)
        $this->SendDebug('RequestAction', sprintf('Command received - ident: %s, value: %s', $ident, is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : (string) $value), 0);

        $this->dispatchUserAction($ident, $value);
    }

    // ============================================================
    // PUBLIC CONFIGURATION, DIAGNOSTICS & HISTORY ACTIONS
    // ============================================================

    /**
     * Calculates the climatological baseline ETo value for the last 3 years using the Open-Meteo-Archive API.
     * Is accessed directly via the button in the configuration form.
     */
    public function CalculateBaselineETO(int $startMonth = 0, int $endMonth = 0): void
    {
        // Defensive check: ensure configuration passed ApplyChanges validation
        if ($this->isModuleConfigurationInvalid('CalculateBaselineETO')) {
            echo $this->Translate('action.calculate_baseline_eto.configuration_invalid');
            return;
        }

        // Quick sanity check for location existence (not full re-validation)
        $locationData = $this->decodeJsonArray($this->ReadPropertyString('Location'));
        if (empty($locationData)) {
            echo $this->Translate('action.calculate_baseline_eto.location_missing');
            return;
        }
        if (!isset($locationData['latitude'], $locationData['longitude'])) {
            echo $this->Translate('action.calculate_baseline_eto.coordinates_invalid');
            return;
        }

        // Determine Monthly Characteristics/Parameters
        $startMonth = ($startMonth === 0) ? $this->ReadPropertyInteger('BaselineStartMonth') : $startMonth;
        $endMonth = ($endMonth === 0) ? $this->ReadPropertyInteger('BaselineEndMonth') : $endMonth;

        try {
            $this->SendDebug('CalculateBaselineETO', sprintf('Starting 3-year calculation for months %d to %d', $startMonth, $endMonth), 0);

            // Initializing external calculator
            $calculator = new BaseLineEToCalculator($this->getDebugLogger());

            // Execute the calculation (we pass the module's SimpleHttpClient)
            $averageETo = $calculator->calculate3YearAverage(
                $locationData,
                $startMonth,
                $endMonth,
                new SimpleHttpClient()
            );

            // Write the calculated value back into the form field
            $this->UpdateFormField('BaselineETO', 'value', $averageETo);

            $this->SendDebug('CalculateBaselineETO', sprintf('Baseline ETo successfully set to %s mm/day.', $averageETo), 0);
            echo sprintf($this->Translate('action.calculate_baseline_eto.success'), $averageETo);

        } catch (LocalizedInvalidArgumentException $e) {
            $this->SendDebug('CalculateBaselineETO', 'Configuration error: ' . $e->getMessage(), 0);
            echo $this->translateException($e);
        } catch (LocalizedException|LocalizedRuntimeException $e) {
            $this->SendDebug('CalculateBaselineETO', 'Verarbeitungsfehler: ' . $e->getMessage(), 0);
            echo $this->translateException($e);
        } catch (InvalidArgumentException $e) {
            $this->SendDebug('CalculateBaselineETO', 'Configuration error: ' . $e->getMessage(), 0);
            echo $this->Translate('action.calculate_baseline_eto.invalid_settings');
        } catch (Exception $e) {
            $this->SendDebug('CalculateBaselineETO', 'Verarbeitungsfehler: ' . $e->getMessage(), 0);
            echo sprintf($this->Translate('action.calculate_baseline_eto.failed'), $e->getMessage());
        }
    }

    /**
     * Checks the ETo history for the last 14 days for gaps,
     * calculates the missing days and stores them in the attribute.
     *
     * @return bool True if data was successfully updated or already complete. False in case of serious errors.
     */
    public function UpdateEToHistory(): bool
    {
        // Defensive check: ensure configuration passed ApplyChanges validation
        if ($this->isModuleConfigurationInvalid('UpdateEToHistory')) {
            return false;
        }

        // Quick sanity check for location existence (full validation already done in ApplyChanges)
        $locationData = $this->decodeJsonArray($this->ReadPropertyString('Location'));
        if (empty($locationData)) {
            $this->LogError('UpdateEToHistory', 'Aborted: location data is unavailable.', 201);
            return false;
        }

        // Load parameters and variable IDs from properties
        $windSensorHeight = (float) $this->ReadPropertyFloat('WindHeight'); // Mounting height of the wind sensor
        $timezone = date_default_timezone_get();

        $tempId = $this->ReadPropertyInteger('TemperatureVarID');
        $humidityId = $this->ReadPropertyInteger('HumidityVarID');
        $baroId = $this->ReadPropertyInteger('PressureVarID');
        $windId = $this->ReadPropertyInteger('WindVarID');

        // Get archive handler
        try {
            $archiveId = $this->GetArchiveInstanceID();
        } catch (Exception $e) {
            $this->LogException('UpdateEToHistory', $e, 301);
            return false;
        }

        try {
            // Load existing history from the module attribute
            $historyJSON = $this->ReadAttributeString('DailyEToHistory');
            if ($historyJSON === '') {
                // If the attribute is empty, initialize it as an empty array
                $historyJSON = '{}';
            }
            $etoHistory = $this->decodeJsonArray($historyJSON);

            $updater = new EToHistoryUpdater($this->getDebugLogger());
            $result = $updater->updateHistory(
                $locationData,
                $etoHistory,
                $archiveId,
                $tempId,
                $humidityId,
                $baroId,
                $windId,
                $windSensorHeight,
                $timezone
            );

            foreach ($result['dayErrors'] as $errorMessage) {
                $this->LogError(
                    'UpdateEToHistory',
                    $this->translateMessage($errorMessage['translationKey'], $errorMessage['parameters']),
                    421
                );
            }

            if ($result['hasChanges']) {
                $this->WriteAttributeString('DailyEToHistory', $this->encodeJsonArray($result['history']));
            }

            $this->SendDebug('UpdateEToHistory', 'Complete history: ' . $this->ReadAttributeString('DailyEToHistory'), 0);

            if (!$result['hasErrors']) {
                $this->ClearContextError('UpdateEToHistory');
            }

            return !$result['hasErrors'];
        } catch (Exception $e) {
            $this->LogException('UpdateEToHistory', $e, 420); // Global History Error
            return false;
        }
    }

    /**
     * Clears the “DailyEToHistory” attribute string and forces a recalculation of the ETo history.
     * Is called directly via the button in the configuration form.
     */
    public function ResetEToHistory(): void
    {
        $this->WriteAttributeString('DailyEToHistory', '{}');
        $this->UpdateEToHistory();
    }

    /**
     * Clears the "WaterBalanceManager" attribute string and forces a recalculation of the water balance history per zone.
     * Is called directly via the button in the configuration form.
     */
    public function ResetWaterBalanceHistory(): void
    {
        $this->WriteAttributeString('WaterBalanceManager', '[]');
        $this->RefreshWaterBalanceHistory();
    }

    /**
     * Clears both water balance history and persistent storage anchor,
     * then rebuilds both datasets from the current 14-day window.
     * Is called directly via the button in the configuration form.
     */
    public function ResetWaterBalanceHistoryAndAnchor(): void
    {
        $this->WriteAttributeString('WaterBalanceManager', '[]');
        $this->WriteAttributeString('WaterStorageAnchor', '{}');
        $this->RefreshWaterBalanceHistory();
    }

    /**
     * Updates the per-zone water balance history for the last 14 days.
     *
     * The method is timer-safe and can also be called manually.
     * Retry logic is delegated to WaterBalanceManager:
     * - refresh missing day entries
     * - refresh entries with zoneEtoFallback=true
     * - refresh entries where rain or irrigation is null
     *
     * @return bool True when update completed without fatal runtime issues.
     */
    public function RefreshWaterBalanceHistory(): bool
    {
        if ($this->isModuleConfigurationInvalid('RefreshWaterBalanceHistory')) {
            return false;
        }

        $this->scheduleWaterBalanceHistoryTimer();

        if (!$this->UpdateEToHistory()) {
            $this->SendDebug('RefreshWaterBalanceHistory', 'Notice: ETo history could not be updated completely. Continuing with available data.', 0);
        }

        $zonesTree = $this->getZonesTree();
        if (empty($zonesTree)) {
            $this->LogError('RefreshWaterBalanceHistory', 'Aborted: no valid zones found in ZonesTree.', 460);
            return false;
        }

        $existingHistory = $this->decodeJsonArray($this->ReadAttributeString('WaterBalanceManager'));
        if (empty($existingHistory)) {
            $existingHistory = [];
        }

        $targetDates = $this->buildRecentWaterBalanceDates(14);
        $manager = new WaterBalanceManager($this->getDebugLogger());

        try {
            $result = $manager->buildWaterBalanceHistoryDataset(
                $zonesTree,
                $existingHistory,
                $targetDates,
                function (int $valveVarId, string $date): array
                {
                    return $this->GetZoneETo($valveVarId, $date);
                },
                function (string $startDateTime, string $endDateTime): ?float
                {
                    return $this->GetRainHistory($startDateTime, $endDateTime);
                },
                function (int $valveVarId, string $startDateTime, string $endDateTime): ?float
                {
                    return $this->GetValveConsumption($valveVarId, $startDateTime, $endDateTime);
                }
            );
        } catch (Throwable $e) {
            $this->LogError('RefreshWaterBalanceHistory', 'History could not be updated. ' . $e->getMessage(), 460);
            return false;
        }

        $encodedHistory = json_encode($result['history']);
        if ($encodedHistory === false) {
            $this->LogError('RefreshWaterBalanceHistory', 'History could not be serialized.', 460);
            return false;
        }

        $metrics = $this->getMetricsForType($this->ReadPropertyString('SystemType'), 'RefreshWaterBalanceHistory');
        if ($metrics === null) {
            return false;
        }

        $maxDeficit = (float) $metrics['maxDeficit'];
        if ($maxDeficit <= 0.0) {
            $this->LogError('RefreshWaterBalanceHistory', 'Configuration error: maxDeficit must be greater than 0.', 461);
            return false;
        }

        $existingAnchor = $this->decodeJsonArray($this->ReadAttributeString('WaterStorageAnchor'));
        if (empty($existingAnchor)) {
            $existingAnchor = [];
        }

        try {
            $anchorResult = $manager->buildWaterStorageAnchorDataset(
                $zonesTree,
                $result['history'],
                $existingAnchor,
                $targetDates,
                $maxDeficit
            );
        } catch (Throwable $e) {
            $this->LogError('RefreshWaterBalanceHistory', 'Water storage could not be updated. ' . $e->getMessage(), 461);
            return false;
        }

        $encodedAnchor = json_encode($anchorResult['anchor']);
        if ($encodedAnchor === false) {
            $this->LogError('RefreshWaterBalanceHistory', 'Water storage could not be serialized.', 461);
            return false;
        }

        $this->WriteAttributeString('WaterBalanceManager', $encodedHistory);
        $this->WriteAttributeString('WaterStorageAnchor', $encodedAnchor);
        $this->SendDebug('RefreshWaterBalanceHistory', sprintf('Water balance history updated. Refreshed entries: %d.', (int) $result['refreshedEntries']), 0);
        $this->SendDebug('RefreshWaterBalanceHistory', sprintf('Complete history: %s.', $encodedHistory), 0);
        $this->SendDebug('RefreshWaterBalanceHistory', sprintf('Water storage updated. Processed zones: %d.', (int) $anchorResult['updatedZones']), 0);
        $this->SendDebug('RefreshWaterBalanceHistory', sprintf('Complete water storage: %s.', $encodedAnchor), 0);
        $this->ClearContextError('RefreshWaterBalanceHistory');

        return true;
    }

    /**
     * Returns the water consumption of a valve for a given period.
     *
     * The method validates module readiness, archive availability and the
     * valve configuration, then delegates the actual runtime-to-consumption
     * calculation to the WaterBalanceManager.
     *
     * If no archive value exists before the start timestamp, the valve is
     * treated as closed until the first known archive entry in the range.
     *
     * @param int $valveVarId The IP-Symcon variable ID of the target valve.
     * @param string $startDateTime The period start timestamp in "Y-m-d H:i:s" format.
     * @param string $endDateTime The period end timestamp in "Y-m-d H:i:s" format.
     * @return float|null The calculated water consumption in mm, or null on failure.
     */
    public function GetValveConsumption(int $valveVarId, string $startDateTime, string $endDateTime): ?float
    {
        if ($this->isModuleConfigurationInvalid('GetValveConsumption')) {
            return null;
        }

        // Get archive handler
        try {
            $archiveId = $this->GetArchiveInstanceID();
        } catch (Exception $e) {
            $this->SendDebug('GetValveConsumption', 'Aborted: archive handler unavailable. Returning null. Details: ' . $e->getMessage(), 0);
            return null;
        }

        $manager = new WaterBalanceManager($this->getDebugLogger());

        $zonesTree = $this->getZonesTree();

        try {
            $this->validateArchiveVariable($valveVarId, $archiveId, $this->Translate('error.context.valve_variable') . ' ' . $valveVarId);
        } catch (Exception $e) {
            $this->SendDebug('GetValveConsumption', 'Aborted: valve variable ' . $valveVarId . ' is not readable or archived. Returning null. Details: ' . $e->getMessage(), 0);
            return null;
        }

        try {
            $valveVariable = IPS_GetVariable($valveVarId);
            if (($valveVariable['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN) {
                $this->LogError('GetValveConsumption', 'Valve variable ' . $valveVarId . ' is not Boolean.', 440);
                return null;
            }
        } catch (Throwable $e) {
            $this->LogError('GetValveConsumption', 'Valve type could not be checked. ' . $e->getMessage(), 440);
            return null;
        }

        try {
            $start = new DateTimeImmutable($startDateTime);
            $end = new DateTimeImmutable($endDateTime);
            if ($end <= $start) {
                $this->LogError('GetValveConsumption', 'The end time must be later than the start time.', 440);
                return null;
            }
        } catch (Throwable $e) {
            $this->LogError('GetValveConsumption', 'Time range could not be checked. ' . $e->getMessage(), 440);
            return null;
        }

        try {
            $consumption = $manager->calculateValveConsumption($archiveId, $valveVarId, $startDateTime, $endDateTime, $zonesTree);
            $this->ClearContextError('GetValveConsumption');

            return $consumption;
        } catch (Throwable $e) {
            $this->LogError('GetValveConsumption', 'Valve consumption could not be calculated. ' . $e->getMessage(), 440);
            return null;
        }
    }

    /**
     * Asynchronous valve validation handler (called by non-blocking timer).
     * Checks deferred valve states after 1000ms and logs real failures.
     */
    public function ValidateValvesAsync(): void
    {
        $buffer = $this->GetBuffer(AutoIrrigationContracts::BUFFER_KEY_PENDING_VALVE_VALIDATION);

        if (empty($buffer)) {
            $this->SetTimerInterval('ValidateValvesAsync', 0);
            return;
        }

        $valvesToValidate = $this->decodeJsonArray($buffer);
        if (empty($valvesToValidate)) {
            $this->SendDebug('ValidateValvesAsync', 'Invalid buffer data; resetting.', 0);
            $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_PENDING_VALVE_VALIDATION, '');
            $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_VALIDATE_RETRY_COUNT, '');
            $this->SetTimerInterval('ValidateValvesAsync', 0);
            return;
        }

        $retryCount = (int) $this->GetBuffer(AutoIrrigationContracts::BUFFER_KEY_VALIDATE_RETRY_COUNT);
        $result = $this->getValveControl()->validateValvesAsync($valvesToValidate, $retryCount);

        if (!empty($result['errors'])) {
            $validationSource = !empty($result['pendingValves']) ? $result['pendingValves'] : $valvesToValidate;
            $lastValveOperationType = (new ValveStartBlockerEvaluator())->determineLastValveOperationTypeFromValves($validationSource);
            if ($lastValveOperationType !== '') {
                $this->SetLastValveOperationType($lastValveOperationType);
            }

            $this->SetValveValidationRetryLimitReached(!empty($result['pendingValves']) && (int) $result['timerInterval'] === 0);
        } else {
            $this->SetLastValveOperationType('');
            $this->SetValveValidationRetryLimitReached(false);
        }

        foreach ($result['errors'] as $error) {
            $this->LogError(
                $error['context'],
                $this->translateMessage($error['translationKey'], $error['parameters']),
                $error['code']
            );
        }

        foreach ($result['clearContexts'] as $context) {
            $this->ClearContextError($context);
        }

        if (count($result['pendingValves']) > 0) {
            $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_PENDING_VALVE_VALIDATION, $this->encodeJsonArray($result['pendingValves']));
            $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_VALIDATE_RETRY_COUNT, (string) $result['retryCount']);
        } else {
            $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_PENDING_VALVE_VALIDATION, '');
            $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_VALIDATE_RETRY_COUNT, '');
        }

        $this->SetTimerInterval('ValidateValvesAsync', $result['timerInterval']);
    }

    /**
     * Sends a test push notification.
     * Is called directly via the button in the configuration form.
     */
    public function SendTestPushNotification(): void
    {
        $this->SendPushNotification($this->normalizeOptionalObjectId($this->ReadPropertyInteger('CoolingPushInstance')), 'test', 'This is a test push notification from Garden Irrigation Control.', 'Info', 0);
    }

    /**
     * Dumps all key module attributes to the Symcon debug log.
     * The method logs the persisted attribute payloads exactly as stored, which
     * keeps diagnostics transparent and guarantees string payloads for SendDebug.
     */
    public function DebugAttributesOutput(): void
    {
        $this->SendDebug('DailyEToHistory', $this->ReadAttributeString('DailyEToHistory'), 0);
        $this->SendDebug('ActiveErrors', $this->ReadAttributeString('ActiveErrors'), 0);
        $this->SendDebug('WaterBalanceManager', $this->ReadAttributeString('WaterBalanceManager'), 0);
        $this->SendDebug('WaterStorageAnchor', $this->ReadAttributeString('WaterStorageAnchor'), 0);
    }

    /**
     * Triggers forecast calculation and writes the result into the Symcon debug output.
     */
    public function DebugRainForecastOutput(): void
    {
        $forecast = $this->GetRainForecast();

        if ($forecast === []) {
            $this->SendDebug('DebugRainForecastOutput', 'No rain forecast available.', 0);
            return;
        }

        $encodedForecast = json_encode($forecast);
        if ($encodedForecast === false) {
            $this->SendDebug('DebugRainForecastOutput', 'Rain forecast could not be serialized.', 0);
            return;
        }

        $this->SendDebug('DebugRainForecastOutput', $encodedForecast, 0);
    }

    /**
     * Disables maintenance mode in the UI when automatic mode is enabled.
     * Called directly from form.json via onChange to prevent
     * automatic mode and maintenance mode from being enabled simultaneously in the form.
     *
     * @param bool $value The new, unsaved state of the automatic checkbox
     * @return void
     */
    public function ToggleSystemAutoEnable(bool $value): void
    {
        // If the automatic mode has been ENABLED ($value === true)
        if ($value) {
            // ...maintenance mode must be DISABLED
            $this->UpdateFormField('SystemMaintenanceEnable', 'value', false);
        }
    }

    /**
     * Disables automatic mode in the UI when maintenance mode is enabled.
     * Called directly from form.json via onChange to prevent
     * automatic mode and maintenance mode from being enabled simultaneously in the form.
     *
     * @param bool $value The new, unsaved state of the maintenance mode checkbox
     * @return void
     */
    public function ToggleSystemMaintenanceEnable(bool $value): void
    {
        // If the maintenance mode has been ENABLED ($value === true)
        if ($value) {
            // ...automatic mode must be DISABLED
            $this->UpdateFormField('SystemAutoEnable', 'value', false);
        }
    }

    /**
     * Public interface for the logger adapter, allowing the protected
     * SendDebug call to be legally used from outside the module scope.
     */
    public function debugLogger(string $context, string $message): void
    {
        $this->SendDebug($context, $message, 0);
    }

    /**
     * Sends a notification through a valid Tile Visualization instance.
     *
     * Technical behavior: creates the library service with adapters for module logging and delegates the public API call.
     * Functional behavior: callers retain the module method while validation and delivery are handled by one reusable service.
     * Domain rationale: keeping orchestration thin reduces the risk that notification rules diverge from the module's shared error and debug handling.
     */
    public function SendPushNotification(int $instanceID, string $title, string $text, string $type, int $targetID): bool
    {
        $sender = new PushNotificationSender(
            $this->getDebugLogger(),
            function (string $context, string $message, int $code): void
            {
                $this->LogError($context, $message, $code);
            },
            function (string $context): void
            {
                $this->ClearContextError($context);
            }
        );

        return $sender->send($instanceID, $title, $text, $type, $targetID);
    }

    /**
     * Reads the rainfall amount for a given time period from the archive.
     * Return matrix:
     * - Measured rainfall values available: total amount in mm (may be 0.0)
     * - No measured value for the time period in question: 0.0
     * - Value could not be determined for technical reasons: null
     *
     * @param string $startDateTime The start time in the format "Y-m-d H:i:s".
     * @param string $endDateTime The end time in the format "Y-m-d H:i:s".
     * @return float|null The rainfall amount for the period in mm, or null in case of retrieval errors.
     */
    protected function GetRainHistory(string $startDateTime, string $endDateTime): ?float
    {
        if ($this->isModuleConfigurationInvalid('GetRainHistory')) {
            return null;
        }

        $rainID = $this->ReadPropertyInteger('RainVarID');
        $rainDayID = $this->normalizeOptionalObjectId($this->ReadPropertyInteger('RainDayVarID'));

        // Get archive handler
        try {
            $archiveId = $this->GetArchiveInstanceID();
        } catch (Exception $e) {
            $this->SendDebug('GetRainHistory', 'Aborted: archive handler unavailable. Returning null. Details: ' . $e->getMessage(), 0);
            return null;
        }

        $manager = new WaterBalanceManager($this->getDebugLogger());

        if (IPS_ObjectExists($rainDayID)) {
            try {
                $this->validateArchiveVariable($rainDayID, $archiveId, $this->Translate('error.context.daily_rain_variable'), false);
            } catch (Exception $e) {
                $this->SendDebug('GetRainHistory', 'Aborted: daily rain variable is not readable or archived. Returning null. Details: ' . $e->getMessage(), 0);
                return null;
            }

            try {
                $rainQuantity = $manager->getRainfallFromDayCounter($archiveId, $rainDayID, $endDateTime);
                $this->SendDebug('GetRainHistory', '[Source: Counter] Rainfall for period ' . $startDateTime . ' to ' . $endDateTime . ': ' . $rainQuantity . ' mm', 0);
                $this->ClearContextError('GetRainHistory');
                return $rainQuantity;
            } catch (Exception $e) {
                $this->LogError('GetRainHistory', '[Source: Counter] Error: daily counter rainfall could not be determined. Returning null. Details: ' . $e->getMessage(), 450);
                return null;
            }
        }

        try {
            $this->validateArchiveVariable($rainID, $archiveId, $this->Translate('error.context.rain_variable'));
        } catch (Exception $e) {
            $this->SendDebug('GetRainHistory', 'Aborted: rain variable is not readable or archived. Returning null. Details: ' . $e->getMessage(), 0);
            return null;
        }

        try {
            $rainQuantity = $manager->getRainfallForDate($archiveId, $rainID, $startDateTime, $endDateTime);
            $this->SendDebug('GetRainHistory', '[Source: Intensity] Rainfall for period ' . $startDateTime . ' to ' . $endDateTime . ': ' . $rainQuantity . ' mm', 0);
            $this->ClearContextError('GetRainHistory');
            return $rainQuantity;
        } catch (Exception $e) {
            $this->LogError('GetRainHistory', '[Source: Intensity] Error: rainfall could not be determined. Returning null. Details: ' . $e->getMessage(), 450);
            return null;
        }
    }

    /**
     * Returns the expected rainfall and the maximum precipitation probability for the next forecast window.
     *
     * The method is intentionally independent from the irrigation workflow for now so it can be
     * wired into the automation layer later without duplicating the Open-Meteo parsing rules.
     *
     * @param int $forecastHours Forecast horizon in hours, default 12.
     * @return array{rainSum: float, maxProb: float}|array{} The aggregated forecast values or an empty array on failure.
     */
    protected function GetRainForecast(int $forecastHours = 12): array
    {
        if (!$this->ReadPropertyBoolean('RainForecast')) {
            $this->SendDebug('GetRainForecast', 'Rain forecast is disabled. Aborting without a request.', 0);
            return [];
        }

        if ($this->isModuleConfigurationInvalid('GetRainForecast')) {
            return [];
        }

        $locationData = $this->decodeJsonArray($this->ReadPropertyString('Location'));
        if (empty($locationData)) {
            $this->LogError('GetRainForecast', 'Aborted: location data is unavailable.', 470);
            return [];
        }

        try {
            $calculator = new RainForecastCalculator($this->getDebugLogger());
            $result = $calculator->getRainForecast($locationData, date_default_timezone_get(), $forecastHours);

            $this->ClearContextError('GetRainForecast');
            return $result;
        } catch (Throwable $e) {
            $this->LogError('GetRainForecast', 'Rain forecast could not be determined. ' . $e->getMessage(), 470);
            return [];
        }
    }

    /**
     * Returns the zone-specific daily ETo for a configured irrigation valve.
     *
     * The method validates module runtime readiness, loads the persisted zone
     * configuration and ETo source data, delegates the zone scaling to the
     * WaterBalanceManager, and synchronizes the local error context.
     *
     * @param int $valveVarId The IP-Symcon variable ID of the target valve.
     * @param string $date The target day formatted as Y-m-d.
     * @return array{calculatedETo: float, usedFallback: bool}|array{} The calculated result or an empty array on failure.
     */
    protected function GetZoneETo(int $valveVarId, string $date): array
    {
        if ($this->isModuleConfigurationInvalid('GetZoneETo')) {
            return [];
        }

        $manager = new WaterBalanceManager($this->getDebugLogger());

        $locationData = $this->decodeJsonArray($this->ReadPropertyString('Location'));
        if (empty($locationData)) {
            $this->LogError('GetZoneETo', 'Aborted: location data is unavailable.', 430);
            return [];
        }

        $zonesTree = $this->getZonesTree();
        $etoHistory = $this->decodeJsonArray($this->ReadAttributeString('DailyEToHistory'));
        $baselineETo = (float) $this->ReadPropertyFloat('BaselineETO');
        $systemType = $this->ReadPropertyString('SystemType');
        $metrics = $this->getMetricsForType($systemType, 'GetZoneETo');
        $isSouthernHemisphere = $this->isSouthernHemisphereLocation($locationData);

        $this->SendDebug('GetZoneETo', 'Hemisphere: ' . ($isSouthernHemisphere ? 'southern' : 'northern'), 0);

        if ($metrics === null) {
            return [];
        }

        $kc = (float) $metrics['kc'];
        if ($kc <= 0.0) {
            $this->LogError('GetZoneETo', 'Configuration error: crop coefficient (Kc) must be greater than 0.', 430);
            return [];
        }

        $this->SendDebug('GetZoneETo', 'Using crop coefficient (Kc): ' . $kc, 0);

        try {
            $result = $manager->calculateScaledEToForZone($valveVarId, $date, $zonesTree, $etoHistory, $baselineETo, $kc, $isSouthernHemisphere);
            $this->ClearContextError('GetZoneETo');

            return $result;
        } catch (Throwable $e) {
            $this->LogError('GetZoneETo', 'Zone ETo could not be calculated. ' . $e->getMessage(), 430);
            return [];
        }
    }

    /**
     * Determines whether the configured location lies on the southern hemisphere.
     *
     * Latitude zero is treated as the northern reference for this project, because
     * equatorial garden installations are not a realistic operating case here.
     *
     * @param array<string, mixed> $locationData Decoded SelectLocation payload.
     * @return bool True if latitude is below zero.
     */
    protected function isSouthernHemisphereLocation(array $locationData): bool
    {
        if (!isset($locationData['latitude']) || !is_numeric($locationData['latitude'])) {
            return false;
        }

        return (float) $locationData['latitude'] < 0.0;
    }

    /**
     * Handles the daily auto irrigation timer entrypoint.
     */
    protected function HandleAutoIrrigationDaily(): void
    {
        // Recheck readiness because configuration may have changed since the timer was scheduled.
        if ($this->isModuleConfigurationInvalid('AutoIrrigationDaily')) {
            $this->SendDebug('AutoIrrigationDaily', 'AutoIrrigationDaily aborted: required configuration is incomplete, so no daily run will start.', 0);
            $this->scheduleAutoIrrigationDailyTimer();
            return;
        }

        // Manual and maintenance modes take precedence over scheduled irrigation.
        if (!$this->ReadPropertyBoolean('SystemAutoEnable')) {
            $this->SendDebug('AutoIrrigationDaily', 'AutoIrrigationDaily skipped: automatic mode is disabled.', 0);
            $this->scheduleAutoIrrigationDailyTimer();
            return;
        }

        if ($this->ReadPropertyBoolean('SystemMaintenanceEnable')) {
            $this->SendDebug('AutoIrrigationDaily', 'AutoIrrigationDaily skipped: maintenance mode is active.', 0);
            $this->scheduleAutoIrrigationDailyTimer();
            return;
        }

        // Yield while lawn cooling owns the shared valve runtime.
        if ($this->IsLawnCoolingRunActive()) {
            $this->SendDebug('AutoIrrigationDaily', 'AutoIrrigationDaily skipped: lawn cooling is already active.', 0);
            $this->scheduleAutoIrrigationDailyTimer();
            return;
        }

        // A failed close blocks a new run; an open failure remains zone-local.
        $hardStartBlocker = (new ValveStartBlockerEvaluator())->evaluate(
            $this->decodeJsonArray($this->ReadAttributeString('ActiveErrors')),
            $this->GetLastValveOperationType(),
            $this->IsValveValidationRetryLimitReached()
        );

        // Technically: serialize the daily orchestrator with a Symcon semaphore while allowing each zone gate to fetch a fresh forecast.
        // Functional behavior: only one irrigation run may execute at a time, and every zone decision can use current weather data instead of a stale run-wide cache.
        // Domain rationale: a multi-zone run may last for many minutes or hours, so forecast changes during the run matter more than minimizing a short lock extension; the accepted risk is that a failed HTTP request can briefly delay competing timer callbacks.
        if (!IPS_SemaphoreEnter(AutoIrrigationContracts::RUN_LOCK_KEY, 1000)) {
            $this->SendDebug('AutoIrrigationDaily', 'AutoIrrigationDaily skipped: another irrigation run is still active.', 0);
            $this->scheduleAutoIrrigationDailyTimer();
            return;
        }

        try {
            $this->executeDailyRunBody($hardStartBlocker);
        } finally {
            IPS_SemaphoreLeave(AutoIrrigationContracts::RUN_LOCK_KEY);
            $this->scheduleAutoIrrigationDailyTimer();
        }
    }

    /**
     * Continues the active runtime slice via the dedicated 5-second runtime timer.
     */
    protected function HandleAutoIrrigationRuntimeTick(): void
    {
        // Technically: reject an AutoIrrigation runtime slice while the cooling state machine is active.
        // Functional behavior: a pending normal-irrigation zone cannot reopen valves during cooling.
        // Domain rationale: interleaved valve commands would break sequential zone timing and could over-apply water.
        if ($this->IsLawnCoolingRunActive()) {
            $this->SendDebug('AutoIrrigationRuntimeTick', 'Runtime tick skipped: lawn cooling is already active.', 0);
            return;
        }

        if (!IPS_SemaphoreEnter(AutoIrrigationContracts::RUN_LOCK_KEY, 1000)) {
            $this->SendDebug('AutoIrrigationRuntimeTick', 'Runtime tick skipped: another irrigation run still holds the semaphore.', 0);
            $this->scheduleAutoIrrigationRuntimeTick();
            return;
        }

        try {
            if ($this->decodeJsonArray($this->GetBuffer(AutoIrrigationContracts::BUFFER_KEY_PREPARED_ZONES)) === []) {
                $this->stopAutoIrrigationRuntimeTick();
                return;
            }

            $this->ContinueAutoIrrigationRuntime();
        } finally {
            IPS_SemaphoreLeave(AutoIrrigationContracts::RUN_LOCK_KEY);
        }
    }

    /**
     * Stores the stop request flag in the module buffer.
     */
    protected function SetAutoIrrigationStopRequested(bool $requested): void
    {
        $this->SetBuffer(AutoIrrigationContracts::STOP_SIGNAL_BUFFER_KEY, $requested ? '1' : '');
    }

    /**
     * Clears the stop request flag in the module buffer.
     */
    protected function ClearAutoIrrigationStopRequested(): void
    {
        $this->SetAutoIrrigationStopRequested(false);
    }

    /**
     * Returns true when a stop request is pending.
     */
    protected function IsAutoIrrigationStopRequested(): bool
    {
        return $this->GetBuffer(AutoIrrigationContracts::STOP_SIGNAL_BUFFER_KEY) === '1';
    }

    protected function ContinueAutoIrrigationRuntime(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $preparedZones = $this->decodeJsonArray($this->ReadBufferedValue(AutoIrrigationContracts::BUFFER_KEY_PREPARED_ZONES));

        if ($preparedZones === []) {
            $this->FinishAutoIrrigationRun('No prepared zones available.');
            return;
        }

        $runtimeRunner = new AutoIrrigationRuntimeLoopRunner($planner, $this->buildRuntimeCallbacks());

        $currentZoneIndex = $this->GetAutoIrrigationCurrentZoneIndex();
        $runtimeEligibleZoneCount = 0;

        while ($currentZoneIndex < count($preparedZones)) {
            $zone = $preparedZones[$currentZoneIndex];
            $this->SetAutoIrrigationCurrentZoneIndex($currentZoneIndex);

            if (!$this->IsAutoIrrigationCurrentZoneStarted()) {
                $zoneStopCheckpoint = $planner->evaluateStopSignalCheckpoint($this->IsAutoIrrigationStopRequested(), 'pre_zone_start');
                if (!$zoneStopCheckpoint['allowed']) {
                    $this->SendDebug('AutoIrrigationDaily', $zoneStopCheckpoint['debugMessage'], 0);
                    $this->GlobalStopAndEnd($zone, 'stop_signal_pre_zone_start', 'Stop signal detected before zone start.');
                    return;
                }

                $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_WATERED, '');
                $this->SetAutoIrrigationCurrentZoneElapsedWateringSeconds(0);

                $zoneStartResult = $this->EvaluateZoneStartPipeline($planner, $zone);

                if ($zoneStartResult['globalStop']) {
                    $this->GlobalStopAndEnd($zone, (string) $zoneStartResult['reason'], 'Global stop triggered before the runtime phase.');
                    return;
                }

                if (!$zoneStartResult['allowed']) {
                    $this->ClearAutoIrrigationCurrentZoneRuntimeContext();
                    $currentZoneIndex++;
                    continue;
                }

                $runtimeEligibleZoneCount++;
                $runtimeEffectiveMinutes = $zoneStartResult['runtimeEffectiveMinutes'];
                $this->SetBuffer(
                    AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_RUNTIME_EFFECTIVE_MINUTES,
                    is_float($runtimeEffectiveMinutes)
                        ? (string) round($runtimeEffectiveMinutes, 4, PHP_ROUND_HALF_UP)
                        : ''
                );
            }

            $runtimeResult = $runtimeRunner->runPreparedZonesRuntimeLoop([$zone]);

            if ($runtimeResult['outcome'] === AutoIrrigationContracts::RUNTIME_OUTCOME_ZONE_ACTIVE) {
                $this->SendDebug('AutoIrrigationDaily', 'Current zone remains active. Scheduling the next runtime tick in 5 seconds.', 0);
                $this->scheduleAutoIrrigationRuntimeTick();
                return;
            }

            if (!$runtimeResult['continue']) {
                $this->SendDebug(
                    'AutoIrrigationDaily',
                    sprintf('Runtime loop ended with outcome=%s, reason=%s.', (string) $runtimeResult['outcome'], (string) $runtimeResult['reason']),
                    0
                );

                if ($runtimeResult['outcome'] === AutoIrrigationContracts::RUNTIME_OUTCOME_PAUSED_WIND) {
                    $this->SendDebug('AutoIrrigationDaily', 'Run ends in wind-pause state and waits for the next runtime entry.', 0);
                    $this->scheduleAutoIrrigationRuntimeTick();
                    return;
                }

                if ($runtimeResult['outcome'] === AutoIrrigationContracts::RUNTIME_OUTCOME_PAUSED_RAIN) {
                    $this->SendDebug('AutoIrrigationDaily', 'Run ends in rain-pause state and waits for the next runtime entry.', 0);
                    $this->scheduleAutoIrrigationRuntimeTick();
                    return;
                }

                $this->SendDebug('AutoIrrigationDaily', 'The run was already terminated by the runtime loop; no duplicate stop call is required.', 0);
                return;
            }

            $this->ClearAutoIrrigationCurrentZoneRuntimeContext();
            $currentZoneIndex++;
        }

        if ($runtimeEligibleZoneCount === 0 && $this->GetAutoIrrigationCurrentZoneIndex() === 0) {
            $this->SendDebug('AutoIrrigationDaily', 'No zone passed the start chain up to valve opening. The run ends cleanly with FINISHED.', 0);
        }

        $this->SendDebug('AutoIrrigationDaily', 'Runtime loop completed successfully for all prepared zones.', 0);
        $this->FinishAutoIrrigationRun('Runtime phase completed without a stop signal.');
    }

    /**
     * Executes the full start pipeline for a single zone before runtime checks.
     *
     * @param array<string, mixed> $zone
     * @return array{allowed: bool, globalStop: bool, reason: string, runtimeEffectiveMinutes: ?float}
     */
    protected function EvaluateZoneStartPipeline(AutoIrrigationZoneStartPlanner $planner, array $zone): array
    {
        $zoneLabel = $this->BuildRuntimeZoneLabel($zone);
        $valveVarId = $this->extractZoneValveId($zone);
        $zoneSoilVarId = $this->extractZoneSoilMoistureVarId($zone);
        $globalSoilVarId = $this->normalizeOptionalObjectId($this->ReadPropertyInteger('GlobalSoilMoistureVarID'));

        // Resolve anchor freshness; re-read after a successful refresh so gate inputs are current.
        $anchorData = $this->decodeJsonArray($this->ReadAttributeString('WaterStorageAnchor'));
        $zoneAnchor = $this->GetZoneStorageAnchor($zone, $anchorData);
        $anchorWasStale = $this->IsResumeReentryStorageStale($zoneAnchor);
        $refreshSucceeded = !$anchorWasStale || $this->RefreshWaterBalanceHistory();
        if ($anchorWasStale && $refreshSucceeded) {
            $anchorData = $this->decodeJsonArray($this->ReadAttributeString('WaterStorageAnchor'));
            $zoneAnchor = $this->GetZoneStorageAnchor($zone, $anchorData);
        }

        $todayStart = (new DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $nowDateTime = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        // Technical behavior: provide the fixed run-start anchor and configured daily budget to the side-effect-free zone gate service.
        // Functional behavior: every zone start is evaluated against the same absolute daily cutoff before any valve command is sent.
        // Domain rationale: a shared daily budget prevents later zones from starting cycles that cannot meet the configured minimum runtime.
        $gateResult = $this->getZoneStartPipelineService()->evaluateGates(
            $planner,
            $this->BuildZoneStartPipelineInputs(
                $zone,
                $zoneLabel,
                $valveVarId,
                $zoneSoilVarId,
                $globalSoilVarId,
                $zoneAnchor,
                $anchorWasStale,
                $refreshSucceeded,
                $todayStart,
                $nowDateTime
            )
        );

        if (!$gateResult['allowed'] || $gateResult['globalStop']) {
            return $gateResult;
        }

        // Valve open is a Symcon side effect executed only after all gates pass.
        $openResult = $this->OpenZoneValveOrSkip($valveVarId);
        $this->SendDebug('AutoIrrigationDaily', sprintf('Zone %s: valve opening checked for zone start. %s', $zoneLabel, (string) $openResult['debugMessage']), 0);

        if (!$openResult['opened']) {
            $this->SendDebug('AutoIrrigationDaily', sprintf('Zone %s is skipped (%s).', $zoneLabel, (string) $openResult['reason']), 0);

            return ['allowed' => false, 'globalStop' => false, 'reason' => (string) $openResult['reason'], 'runtimeEffectiveMinutes' => null];
        }

        return [
            'allowed'                 => true,
            'globalStop'              => false,
            'reason'                  => 'zone_start_ready',
            'runtimeEffectiveMinutes' => $gateResult['runtimeEffectiveMinutes'],
        ];
    }

    /**
     * Reads the Symcon-backed inputs required by the side-effect-free zone start pipeline.
     *
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $zoneAnchor
     * @return array{
     *     zoneLabel: string,
     *     rainForecastEnabled: bool,
     *     forecast: array<string, mixed>,
     *     anchorWasStale: bool,
     *     refreshSucceeded: bool,
     *     storageMm: float|null,
     *     rainTodayMm: float|null,
     *     irrigationTodayMm: float|null,
     *     precipRate: float,
     *     irrigationMinRuntime: float,
     *     irrigationZoneMaxRuntime: int,
     *     runStartTimestamp: int|null,
     *     maxRuntimeSeconds: int,
     *     lastAutoIrrigationDate: string|null,
     *     irrigationInterval: int,
     *     today: string,
     *     zoneSoilValue: float|null,
     *     globalSoilValue: float|null,
     *     useGlobalSoil: bool,
     *     soilMinMoisture: int,
     *     zoneSoilVarId: int,
     *     globalSoilVarId: int
     * }
     */
    protected function BuildZoneStartPipelineInputs(
        array $zone,
        string $zoneLabel,
        int $valveVarId,
        int $zoneSoilVarId,
        int $globalSoilVarId,
        array $zoneAnchor,
        bool $anchorWasStale,
        bool $refreshSucceeded,
        string $todayStart,
        string $nowDateTime
    ): array {
        return [
            'zoneLabel'                => $zoneLabel,
            'rainForecastEnabled'      => $this->ReadPropertyBoolean('RainForecast'),
            'forecast'                 => $this->GetRainForecast(),
            'anchorWasStale'           => $anchorWasStale,
            'refreshSucceeded'         => $refreshSucceeded,
            'storageMm'                => isset($zoneAnchor['storage']) && is_numeric($zoneAnchor['storage']) ? (float) $zoneAnchor['storage'] : null,
            'rainTodayMm'              => $this->GetRainHistory($todayStart, $nowDateTime),
            'irrigationTodayMm'        => $valveVarId > 0 ? $this->GetValveConsumption($valveVarId, $todayStart, $nowDateTime) : null,
            'precipRate'               => $this->extractZoneSprinklerPrecipitationRate($zone),
            'irrigationMinRuntime'     => $this->ReadPropertyFloat('IrrigationMinRuntime'),
            'irrigationZoneMaxRuntime' => $this->ReadPropertyInteger('IrrigationZoneMaxRuntime'),
            'runStartTimestamp'        => $this->GetAutoIrrigationRunStartTimestamp(),
            'maxRuntimeSeconds'        => $this->BuildIrrigationMaxRuntimeSeconds(),
            'lastAutoIrrigationDate'   => isset($zoneAnchor['lastAutoIrrigationDate']) && is_string($zoneAnchor['lastAutoIrrigationDate']) ? $zoneAnchor['lastAutoIrrigationDate'] : null,
            'irrigationInterval'       => $this->ReadPropertyInteger('IrrigationInterval'),
            'today'                    => (new DateTimeImmutable('today'))->format('Y-m-d'),
            'zoneSoilValue'            => $this->ReadOptionalFloatValue($zoneSoilVarId),
            'globalSoilValue'          => $this->ReadOptionalFloatValue($globalSoilVarId),
            'useGlobalSoil'            => $this->extractZoneUseGlobalSoilMoisture($zone),
            'soilMinMoisture'          => $this->ReadPropertyInteger('SoilMinMoisture'),
            'zoneSoilVarId'            => $zoneSoilVarId,
            'globalSoilVarId'          => $globalSoilVarId,
        ];
    }

    /**
     * Runs the unified resume reentry pipeline (forecast -> consistency -> storage -> compute -> derive -> interval -> soil-start).
     *
     * @param array<string, mixed> $zone
     * @return array{allowed: bool, globalStop: bool, reason: string, debugMessage: string}
     */
    protected function BuildResumeReentryPipelineResult(array $zone, string $resumeReason): array
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $zoneLabel = $this->BuildRuntimeZoneLabel($zone);
        $valveVarId = $this->extractZoneValveId($zone);
        $zoneSoilVarId = $this->extractZoneSoilMoistureVarId($zone);
        $globalSoilVarId = $this->normalizeOptionalObjectId($this->ReadPropertyInteger('GlobalSoilMoistureVarID'));

        // Technically: mark the resume-reentry start with zone identity and pause reason.
        // Functional behavior: every resumed zone run has a traceable reentry segment in the debug timeline.
        // Domain rationale: pause exits must be auditable so later watering decisions can be reconstructed from logs.
        $this->SendDebug('AutoIrrigationResumeReentry', sprintf('Resume re-entry started for %s after %s.', $zoneLabel, $resumeReason), 0);

        // Resolve anchor freshness; re-read after a successful refresh so gate inputs are current.
        $anchorData = $this->decodeJsonArray($this->ReadAttributeString('WaterStorageAnchor'));
        $zoneAnchor = $this->GetZoneStorageAnchor($zone, $anchorData);
        $anchorWasStale = $this->IsResumeReentryStorageStale($zoneAnchor);
        $refreshSucceeded = !$anchorWasStale || $this->RefreshWaterBalanceHistory();
        if ($anchorWasStale && $refreshSucceeded) {
            $anchorData = $this->decodeJsonArray($this->ReadAttributeString('WaterStorageAnchor'));
            $zoneAnchor = $this->GetZoneStorageAnchor($zone, $anchorData);
        }

        $todayStart = (new DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $nowDateTime = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        return $this->getZoneStartPipelineService()->evaluateResumeGates($planner, [
            'zoneLabel'                => $zoneLabel,
            'rainForecastEnabled'      => $this->ReadPropertyBoolean('RainForecast'),
            'forecast'                 => $this->GetRainForecast(),
            'anchorWasStale'           => $anchorWasStale,
            'refreshSucceeded'         => $refreshSucceeded,
            'storageMm'                => isset($zoneAnchor['storage']) && is_numeric($zoneAnchor['storage']) ? (float) $zoneAnchor['storage'] : null,
            'rainTodayMm'              => $this->GetRainHistory($todayStart, $nowDateTime),
            'irrigationTodayMm'        => $valveVarId > 0 ? $this->GetValveConsumption($valveVarId, $todayStart, $nowDateTime) : null,
            'precipRate'               => $this->extractZoneSprinklerPrecipitationRate($zone),
            'irrigationMinRuntime'     => $this->ReadPropertyFloat('IrrigationMinRuntime'),
            'irrigationZoneMaxRuntime' => $this->ReadPropertyInteger('IrrigationZoneMaxRuntime'),
            'runStartTimestamp'        => $this->GetAutoIrrigationRunStartTimestamp(),
            'maxRuntimeSeconds'        => $this->BuildIrrigationMaxRuntimeSeconds(),
            'lastAutoIrrigationDate'   => isset($zoneAnchor['lastAutoIrrigationDate']) && is_string($zoneAnchor['lastAutoIrrigationDate']) ? $zoneAnchor['lastAutoIrrigationDate'] : null,
            'irrigationInterval'       => $this->ReadPropertyInteger('IrrigationInterval'),
            'today'                    => (new DateTimeImmutable('today'))->format('Y-m-d'),
            'zoneSoilValue'            => $this->ReadOptionalFloatValue($zoneSoilVarId),
            'globalSoilValue'          => $this->ReadOptionalFloatValue($globalSoilVarId),
            'useGlobalSoil'            => $this->extractZoneUseGlobalSoilMoisture($zone),
            'soilMinMoisture'          => $this->ReadPropertyInteger('SoilMinMoisture'),
            'zoneSoilVarId'            => $zoneSoilVarId,
            'globalSoilVarId'          => $globalSoilVarId,
        ]);
    }

    /**
     * @param array<string, mixed> $zone
     * @param array<array-key, mixed> $anchorData
     * @return array<string, mixed>
     */
    protected function GetZoneStorageAnchor(array $zone, array $anchorData): array
    {
        $valveVarId = $this->extractZoneValveId($zone);

        if ($valveVarId <= 0) {
            return [];
        }

        $anchorKey = (string) $valveVarId;
        if (!isset($anchorData[$anchorKey]) || !is_array($anchorData[$anchorKey])) {
            return [];
        }

        return $anchorData[$anchorKey];
    }

    /**
     * Evaluates wind-pause trigger and resume stability windows.
     * /**
     * @return array{windPauseTriggered: bool, resumeStable: bool, pauseActive: bool, triggerMeanWind: float, thresholdWindMaxSpeed: float, sampleCount5m: int, sampleCount15m: int}
     */
    protected function BuildWindPauseSnapshot(): array
    {
        $thresholdWindMaxSpeed = (float) $this->ReadPropertyInteger('WindMaxSpeed');
        $windVarId = $this->ReadPropertyInteger('WindVarID');
        $pauseReason = $this->GetAutoIrrigationCurrentZonePauseReason();
        $pauseActive = $pauseReason === 'wind_pause';

        if ($windVarId <= 0 || !IPS_ObjectExists($windVarId)) {
            $this->SendDebug('BuildWindPauseSnapshot', 'Wind-pause check skipped: wind variable is invalid.', 0);

            return [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'pauseActive'           => $pauseActive,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => $thresholdWindMaxSpeed,
                'sampleCount5m'         => 0,
                'sampleCount15m'        => 0,
            ];
        }

        try {
            $archiveId = $this->GetArchiveInstanceID();
            $this->validateArchiveVariable($windVarId, $archiveId, $this->Translate('error.context.wind_variable'));
        } catch (Exception $e) {
            $this->SendDebug('BuildWindPauseSnapshot', 'Wind-pause check is fail-open: wind data is unavailable. ' . $e->getMessage(), 0);

            return [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'pauseActive'           => $pauseActive,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => $thresholdWindMaxSpeed,
                'sampleCount5m'         => 0,
                'sampleCount15m'        => 0,
            ];
        }

        $now = time();
        $wind15mRaw = AC_GetLoggedValues($archiveId, $windVarId, $now - 900, $now, 0);
        $wind15m = is_array($wind15mRaw) ? $wind15mRaw : [];
        // Technically: derive the 5-min trigger window by filtering the already-loaded 15-min dataset in memory.
        // Functional behavior: avoids a second archive query per tick; only samples with TimeStamp >= now-300 feed the trigger mean.
        // Domain rationale: the 15-min dataset is a strict superset of the 5-min window; a second API call is redundant I/O on every runtime tick.
        $wind5m = array_values(array_filter(
            $wind15m,
            static fn (array $sample): bool => isset($sample['TimeStamp']) && is_numeric($sample['TimeStamp']) && (int) $sample['TimeStamp'] >= $now - 300
        ));

        $snapshot = $this->getPauseSnapshotBuilder()->buildWindPauseSnapshot(
            $wind5m,
            $wind15m,
            $thresholdWindMaxSpeed,
            $pauseActive
        );

        $this->SendDebug(
            'BuildWindPauseSnapshot',
            sprintf(
                'Windpause-Snapshot: mean5m=%.2f, threshold=%.2f, trigger=%s, resumeStable=%s, pauseActive=%s, samples5m=%d, samples15m=%d.',
                $snapshot['triggerMeanWind'],
                $snapshot['thresholdWindMaxSpeed'],
                $snapshot['windPauseTriggered'] ? 'yes' : 'no',
                $snapshot['resumeStable'] ? 'yes' : 'no',
                $snapshot['pauseActive'] ? 'yes' : 'no',
                $snapshot['sampleCount5m'],
                $snapshot['sampleCount15m']
            ),
            0
        );

        return $snapshot;
    }

    /**
     * Evaluates rain-pause trigger and dry resume stability windows.
     *
     * @return array{rainPauseTriggered: bool, resumeDryStable: bool, pauseActive: bool, triggerRainSum5m: float, thresholdRainSum5m: float, sampleCount5m: int, sampleCount15m: int}
     */
    protected function BuildRainPauseSnapshot(): array
    {
        $thresholdRainSum5m = 0.2;
        $rainVarId = $this->ReadPropertyInteger('RainVarID');
        $pauseReason = $this->GetAutoIrrigationCurrentZonePauseReason();
        $pauseActive = $pauseReason === 'rain_pause';

        if ($rainVarId <= 0 || !IPS_ObjectExists($rainVarId)) {
            $this->SendDebug('BuildRainPauseSnapshot', 'Rain-pause check skipped: rain variable is invalid.', 0);

            return [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'pauseActive'        => $pauseActive,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => $thresholdRainSum5m,
                'sampleCount5m'      => 0,
                'sampleCount15m'     => 0,
            ];
        }

        try {
            $archiveId = $this->GetArchiveInstanceID();
            $this->validateArchiveVariable($rainVarId, $archiveId, $this->Translate('error.context.rain_variable'));
        } catch (Exception $e) {
            $this->SendDebug('BuildRainPauseSnapshot', 'Rain-pause check is fail-open: rain data is unavailable. ' . $e->getMessage(), 0);

            return [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'pauseActive'        => $pauseActive,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => $thresholdRainSum5m,
                'sampleCount5m'      => 0,
                'sampleCount15m'     => 0,
            ];
        }

        $now = time();
        $rain15mRaw = AC_GetLoggedValues($archiveId, $rainVarId, $now - 900, $now, 0);
        $rain15m = is_array($rain15mRaw) ? $rain15mRaw : [];
        // Technically: derive the 5-min trigger window by filtering the already-loaded 15-min dataset in memory.
        // Functional behavior: avoids a second archive query per tick; only samples with TimeStamp >= now-300 feed the trigger rain sum.
        // Domain rationale: the 15-min dataset is a strict superset of the 5-min window; a second API call is redundant I/O on every runtime tick.
        $rain5m = array_values(array_filter(
            $rain15m,
            static fn (array $sample): bool => isset($sample['TimeStamp']) && is_numeric($sample['TimeStamp']) && (int) $sample['TimeStamp'] >= $now - 300
        ));

        $snapshot = $this->getPauseSnapshotBuilder()->buildRainPauseSnapshot(
            $rain5m,
            $rain15m,
            $thresholdRainSum5m,
            $pauseActive
        );

        $this->SendDebug(
            'BuildRainPauseSnapshot',
            sprintf(
                'Rain-pause snapshot: sum5m=%.3f, threshold=%.3f, trigger=%s, resumeDryStable=%s, pauseActive=%s, samples5m=%d, samples15m=%d.',
                $snapshot['triggerRainSum5m'],
                $snapshot['thresholdRainSum5m'],
                $snapshot['rainPauseTriggered'] ? 'yes' : 'no',
                $snapshot['resumeDryStable'] ? 'yes' : 'no',
                $snapshot['pauseActive'] ? 'yes' : 'no',
                $snapshot['sampleCount5m'],
                $snapshot['sampleCount15m']
            ),
            0
        );

        return $snapshot;
    }

    /**
     * Builds the soil runtime snapshot for mode-1 stop checks.
     *
     * Technical rule: this method only prepares deterministic input data for the
     * planner-side soil runtime gate and does not decide stop/continue itself.
     * Functional behavior: every runtime tick receives the same normalized
     * payload shape, even when data is incomplete.
     * Domain rationale: stable snapshot contracts prevent accidental watering
     * behavior changes when sensor quality fluctuates.
     *
     * @param array<string, mixed> $zone
     * @return array{mode: int, source: string, measuredSoil: ?float, soilMinMoisture: int, confirmWindowSeconds: int, sampleCount60s: int, confirmWindowStable: bool}
     */
    protected function BuildSoilRuntimeSnapshot(array $zone): array
    {
        // Technically: load mode/threshold/window from validated properties and keep window fixed at 60s.
        // Functional behavior: runtime gate semantics remain consistent across all zones in this run.
        // Domain rationale: a fixed confirmation horizon avoids reacting to short-lived moisture spikes.
        $mode = $this->ReadPropertyInteger('SoilMoistureMode');
        $soilMinMoisture = $this->ReadPropertyInteger('SoilMinMoisture');
        $confirmWindowSeconds = 60;

        $zoneSoilVarId = $this->extractZoneSoilMoistureVarId($zone);
        $globalSoilVarId = $this->normalizeOptionalObjectId($this->ReadPropertyInteger('GlobalSoilMoistureVarID'));
        $useGlobalSoil = $this->extractZoneUseGlobalSoilMoisture($zone);

        $zoneSoil = $this->ReadOptionalFloatValue($zoneSoilVarId);
        $globalSoil = $this->ReadOptionalFloatValue($globalSoilVarId);

        $source = 'none';
        $sensorVarId = 0;
        $measuredSoil = null;

        // Technically: select the active source with strict priority zone -> global(opt-in) -> none.
        // Functional behavior: if a valid zone sensor exists it always overrides global fallback.
        // Domain rationale: zone-local moisture is agronomically more representative than a shared global reading.
        if ($zoneSoilVarId > 0 && $zoneSoil !== null) {
            $source = 'zone';
            $sensorVarId = $zoneSoilVarId;
            $measuredSoil = $zoneSoil;
        } elseif ($useGlobalSoil && $globalSoilVarId > 0 && $globalSoil !== null) {
            $source = 'global';
            $sensorVarId = $globalSoilVarId;
            $measuredSoil = $globalSoil;
        }

        $sensorExists = $sensorVarId > 0 && IPS_ObjectExists($sensorVarId);
        $windowSamples = [];

        // Technically: fetch archive samples only when all runtime prerequisites allow a window evaluation.
        // Functional behavior: archive I/O is skipped entirely for non-active or missing sensor configurations.
        // Domain rationale: unnecessary archive queries waste I/O on every runtime tick even when no soil gate is active.
        if ($mode === 1 && $source !== 'none' && $sensorExists) {
            try {
                // Technically: read archive samples in the last 60s and validate historical availability defensively.
                // Functional behavior: confirmation is only derived from persisted numeric data, not from a single live value.
                // Domain rationale: persistence-backed checks are less vulnerable to transient bus/device jitter.
                $archiveId = $this->GetArchiveInstanceID();
                $this->validateArchiveVariable($sensorVarId, $archiveId, $this->Translate('error.context.soil_moisture_variable'), false);
                $now = time();
                $windowSamplesRaw = AC_GetLoggedValues($archiveId, $sensorVarId, $now - $confirmWindowSeconds, $now, 0);
                $windowSamples = is_array($windowSamplesRaw) ? $windowSamplesRaw : [];
            } catch (Exception $e) {
                // Technically: archive errors are downgraded to debug-only fail-open behavior for this snapshot.
                // Functional behavior: caller receives non-confirmed window and runtime may continue.
                // Domain rationale: telemetry outages must degrade safely without creating uncontrolled hard stops.
                $this->SendDebug('BuildSoilRuntimeSnapshot', 'Soil runtime window is fail-open: archive data is unavailable. ' . $e->getMessage(), 0);
            }
        }

        return $this->getSoilRuntimeSnapshotBuilder()->buildSnapshot(
            $mode,
            $soilMinMoisture,
            $confirmWindowSeconds,
            $source,
            $sensorExists,
            $measuredSoil,
            $windowSamples,
            $this->getDebugLogger()
        );
    }

    /**
     * Builds the target-reached snapshot from the live zone water balance and the zone precipitation rate.
     *
     * @param array<string, mixed> $zone
     * @return array{elapsedWateringMinutes: float, runtimeEffectiveMinutes: ?float}
     */
    protected function BuildTargetReachedSnapshot(array $zone): array
    {
        $elapsedWateringMinutes = round($this->GetAutoIrrigationCurrentZoneElapsedWateringSeconds() / 60.0, 4, PHP_ROUND_HALF_UP);

        $trackerBuffer = trim($this->GetBuffer(AutoIrrigationContracts::BUFFER_KEY_VALVE_RUNTIME_TRACKER));
        if ($trackerBuffer !== '') {
            $tracker = $this->decodeJsonArray($trackerBuffer);
            $openedAt = isset($tracker['openedAt']) && is_numeric($tracker['openedAt'])
                ? (int) $tracker['openedAt']
                : 0;

            if ($openedAt > 0 && isset($tracker['valveId']) && is_numeric($tracker['valveId']) && (int) $tracker['valveId'] > 0) {
                $elapsedWateringMinutes = max(
                    0.0,
                    round(($this->GetAutoIrrigationCurrentZoneElapsedWateringSeconds() + max(0, time() - $openedAt)) / 60.0, 4, PHP_ROUND_HALF_UP)
                );
            }
        }

        $runtimeEffectiveMinutes = null;
        $runtimeEffectiveFromContext = trim($this->GetBuffer(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_RUNTIME_EFFECTIVE_MINUTES));
        if ($runtimeEffectiveFromContext !== '' && is_numeric($runtimeEffectiveFromContext)) {
            $runtimeEffectiveMinutes = (float) $runtimeEffectiveFromContext;
        }

        $valveVarId = $this->extractZoneValveId($zone);
        $precipRate = $this->extractZoneSprinklerPrecipitationRate($zone);

        if ($runtimeEffectiveMinutes === null && $valveVarId > 0 && $precipRate > 0.0) {
            $anchorData = $this->decodeJsonArray($this->ReadAttributeString('WaterStorageAnchor'));
            $zoneAnchor = $this->GetZoneStorageAnchor($zone, $anchorData);
            $planner = new AutoIrrigationZoneStartPlanner();

            $todayStart = (new DateTimeImmutable('today'))->format('Y-m-d H:i:s');
            $nowDateTime = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $storageMm = isset($zoneAnchor['storage']) && is_numeric($zoneAnchor['storage'])
                ? (float) $zoneAnchor['storage']
                : null;
            $rainTodayMm = $this->GetRainHistory($todayStart, $nowDateTime);
            $irrigationTodayMm = $this->GetValveConsumption($valveVarId, $todayStart, $nowDateTime);

            $requiredResult = $planner->computeRequiredMm($storageMm, $rainTodayMm, $irrigationTodayMm);
            if ($requiredResult['allowed']) {
                $requiredMm = $requiredResult['requiredMm'];
                if ($requiredMm === null) {
                    return [
                        'elapsedWateringMinutes'  => $elapsedWateringMinutes,
                        'runtimeEffectiveMinutes' => null,
                    ];
                }

                $runtimeResult = $planner->deriveRuntime(
                    $requiredMm,
                    $precipRate,
                    $this->ReadPropertyFloat('IrrigationMinRuntime'),
                    $this->ReadPropertyInteger('IrrigationZoneMaxRuntime')
                );

                if ($runtimeResult['allowed']) {
                    $runtimeEffectiveMinutes = $runtimeResult['runtimeEffectiveMinutes'];
                }
            }
        }

        return [
            'elapsedWateringMinutes'  => $elapsedWateringMinutes,
            'runtimeEffectiveMinutes' => $runtimeEffectiveMinutes,
        ];
    }

    /**
     * @param array<string, mixed> $zoneAnchor
     */
    protected function IsResumeReentryStorageStale(array $zoneAnchor): bool
    {
        if ($zoneAnchor === []) {
            return true;
        }

        if (!isset($zoneAnchor['storage']) || !is_numeric($zoneAnchor['storage'])) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $zone
     */
    protected function HandleZonePauseAction(array $zone, string $reason): void
    {
        $this->SendDebug('AutoIrrigationDaily', sprintf('Zone pause (%s): closing valves without recording the daily date.', $reason), 0);
        $this->SetAutoIrrigationCurrentZonePauseReason($reason);
        $this->AddCurrentTrackedWateringSecondsToElapsedBuffer();
        $this->CloseAllValves();
    }

    /**
     * @param array<string, mixed> $zone
     * @return array{opened: bool, skipZone: bool, reason: string, debugMessage: string}
     */
    protected function ResumeZoneAfterPause(array $zone, string $reason): array
    {
        $valveVarId = $this->extractZoneValveId($zone);

        $resumeResult = $this->OpenZoneValveOrSkip($valveVarId);
        $this->SendDebug('AutoIrrigationDaily', sprintf('Zone %s: valve reopening after pause (%s) checked. %s', $this->BuildRuntimeZoneLabel($zone), $reason, (string) $resumeResult['debugMessage']), 0);

        if (!$resumeResult['opened']) {
            return [
                'opened'       => false,
                'skipZone'     => true,
                'reason'       => 'resume_reopen_failed',
                'debugMessage' => 'Resume failed: the zone could not be reopened after the pause.',
            ];
        }

        $this->ClearAutoIrrigationCurrentZonePauseReason();

        return [
            'opened'       => true,
            'skipZone'     => false,
            'reason'       => 'resume_open_success',
            'debugMessage' => 'Resume succeeded: the zone was reopened after the pause.',
        ];
    }

    /**
     * @param array<string, mixed> $zone
     */
    protected function HandleZoneTerminalAction(array $zone, string $reason): void
    {
        $this->SendDebug('AutoIrrigationDaily', sprintf('Zone end (%s): executing terminal zone action.', $reason), 0);
        $this->ZoneStopAndNext($zone, $reason);
    }

    /**
     * @param array<string, mixed> $zone
     */
    protected function ZoneStopAndNext(array $zone, string $reason): void
    {
        // Technically: close valves for the current zone and conditionally persist lastAutoIrrigationDate for interval handling.
        // Functional behavior: this command finalizes only the active zone and allows the runtime loop to continue with next_zone.
        // Domain rationale: local stop reasons must not terminate the full daily run because other zones can still require irrigation.
        $this->SendDebug('AutoIrrigationDaily', sprintf('zone_stop_and_next (%s): closing valves and recording the last irrigation date.', $reason), 0);
        $shouldWriteLastDate = $this->ShouldWriteLastAutoIrrigationDateForZone($zone);
        $this->CloseAllValves();

        if ($shouldWriteLastDate) {
            $this->WriteLastAutoIrrigationDateForZone($zone);
        }
    }

    /**
     * @param array<string, mixed>|null $zone
     */
    protected function GlobalStopAndEnd(?array $zone, string $reason, string $stateReason): void
    {
        // Technically: this command performs the phase-4 global stop sequence with optional current-zone date write.
        // Functional behavior: all valves are closed, STOPPED is set, and the active zone date is only persisted after real watering.
        // Domain rationale: fail-safe global shutdown must be repeatable without creating duplicate side effects or false interval locks.
        $this->SendDebug('AutoIrrigationDaily', sprintf('global_stop_and_end (%s): closing all valves and ending the run in STOPPED state.', $reason), 0);

        $shouldWriteLastDate = false;
        if ($zone !== null) {
            $shouldWriteLastDate = $this->ShouldWriteLastAutoIrrigationDateForZone($zone);
        }

        $this->SetAutoIrrigationStopRequested(true);
        $this->CloseAllValves();

        if ($zone !== null && $shouldWriteLastDate) {
            $this->WriteLastAutoIrrigationDateForZone($zone);
        }

        $this->SetAutoIrrigationRunState(AutoIrrigationContracts::RUN_STATE_STOPPED, $stateReason);
        $this->FinalizeAutoIrrigationRuntimeContext();
    }

    /**
     * @param array<string, mixed> $zone
     */
    protected function ShouldWriteLastAutoIrrigationDateForZone(array $zone): bool
    {
        $valveVarId = $this->extractZoneValveId($zone);

        if ($valveVarId <= 0) {
            return false;
        }

        return $this->GetBuffer(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_WATERED) === '1';
    }

    /**
     * @param array<string, mixed> $zone
     */
    protected function WriteLastAutoIrrigationDateForZone(array $zone): void
    {
        $valveVarId = $this->extractZoneValveId($zone);

        if ($valveVarId <= 0) {
            $this->SendDebug('AutoIrrigationDaily', sprintf('lastAutoIrrigationDate not written: zone %s has no valid valve ID.', $this->BuildRuntimeZoneLabel($zone)), 0);
            return;
        }

        $anchorData = $this->decodeJsonArray($this->ReadAttributeString('WaterStorageAnchor'));
        $anchorKey = (string) $valveVarId;
        if (!isset($anchorData[$anchorKey]) || !is_array($anchorData[$anchorKey])) {
            $anchorData[$anchorKey] = [];
        }

        $previousDate = isset($anchorData[$anchorKey]['lastAutoIrrigationDate']) && is_string($anchorData[$anchorKey]['lastAutoIrrigationDate'])
            ? $anchorData[$anchorKey]['lastAutoIrrigationDate']
            : '';
        $writtenDate = (new DateTimeImmutable('today'))->format('Y-m-d');
        $anchorData[$anchorKey]['lastAutoIrrigationDate'] = $writtenDate;
        $this->WriteAttributeString('WaterStorageAnchor', $this->encodeJsonArray($anchorData));
        $this->SendDebug(
            'AutoIrrigationDaily',
            sprintf(
                'lastAutoIrrigationDate written: zone %s, valve ID %d, value=%s%s.',
                $this->BuildRuntimeZoneLabel($zone),
                $valveVarId,
                $writtenDate,
                $previousDate !== '' ? sprintf(' (previous=%s)', $previousDate) : ''
            ),
            0
        );
    }

    protected function SetAutoIrrigationRunStartTimestamp(int $timestamp): void
    {
        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_RUN_START_TIMESTAMP, (string) $timestamp);
    }

    protected function GetAutoIrrigationRunStartTimestamp(): ?int
    {
        $value = trim($this->ReadBufferedValue(AutoIrrigationContracts::BUFFER_KEY_RUN_START_TIMESTAMP));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    protected function ClearAutoIrrigationRunStartTimestamp(): void
    {
        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_RUN_START_TIMESTAMP, '');
    }

    protected function SetAutoIrrigationCurrentZoneIndex(int $index): void
    {
        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_INDEX, (string) max(0, $index));
    }

    protected function GetAutoIrrigationCurrentZoneIndex(): int
    {
        $value = trim($this->ReadBufferedValue(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_INDEX));
        if ($value === '' || !is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }

    protected function ClearAutoIrrigationCurrentZoneIndex(): void
    {
        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_INDEX, '');
    }

    protected function SetAutoIrrigationCurrentZoneElapsedWateringSeconds(int $seconds): void
    {
        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_ELAPSED_WATERING_SECONDS, (string) max(0, $seconds));
    }

    protected function GetAutoIrrigationCurrentZoneElapsedWateringSeconds(): int
    {
        $value = trim($this->ReadBufferedValue(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_ELAPSED_WATERING_SECONDS));
        if ($value === '' || !is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }

    protected function AddCurrentTrackedWateringSecondsToElapsedBuffer(): void
    {
        $trackerBuffer = trim($this->ReadBufferedValue(AutoIrrigationContracts::BUFFER_KEY_VALVE_RUNTIME_TRACKER));
        if ($trackerBuffer === '') {
            return;
        }

        $tracker = $this->decodeJsonArray($trackerBuffer);
        $openedAt = isset($tracker['openedAt']) && is_numeric($tracker['openedAt'])
            ? (int) $tracker['openedAt']
            : 0;

        if ($openedAt <= 0) {
            return;
        }

        $additionalSeconds = max(0, time() - $openedAt);
        $this->SetAutoIrrigationCurrentZoneElapsedWateringSeconds(
            $this->GetAutoIrrigationCurrentZoneElapsedWateringSeconds() + $additionalSeconds
        );
    }

    protected function IsAutoIrrigationCurrentZoneStarted(): bool
    {
        return trim($this->ReadBufferedValue(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_RUNTIME_EFFECTIVE_MINUTES)) !== '';
    }

    protected function ClearAutoIrrigationCurrentZoneRuntimeContext(): void
    {
        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_RUNTIME_EFFECTIVE_MINUTES, '');
        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_WATERED, '');
        $this->ClearAutoIrrigationCurrentZonePauseReason();
        $this->SetAutoIrrigationCurrentZoneElapsedWateringSeconds(0);
    }

    protected function SetAutoIrrigationCurrentZonePauseReason(string $reason): void
    {
        if (!in_array($reason, ['wind_pause', 'rain_pause'], true)) {
            $reason = '';
        }

        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_PAUSE_REASON, $reason);
    }

    protected function GetAutoIrrigationCurrentZonePauseReason(): string
    {
        return trim($this->ReadBufferedValue(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_PAUSE_REASON));
    }

    protected function ClearAutoIrrigationCurrentZonePauseReason(): void
    {
        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_PAUSE_REASON, '');
    }

    // ============================================================
    // AUTO IRRIGATION RUNTIME
    // ============================================================

    /**
     * Schedules the daily irrigation timer for the next configured start time.
     */
    protected function scheduleAutoIrrigationDailyTimer(): void
    {
        if ($this->isModuleConfigurationInvalid('scheduleAutoIrrigationDailyTimer')) {
            $this->SetTimerInterval(AutoIrrigationContracts::TIMER_IDENT_DAILY, 0);
            return;
        }

        $startTime = $this->decodeJsonArray($this->ReadPropertyString('IrrigationStartTime'));
        if (!isset($startTime['hour'], $startTime['minute'], $startTime['second'])
            || !is_numeric($startTime['hour'])
            || !is_numeric($startTime['minute'])
            || !is_numeric($startTime['second'])) {
            $this->SetTimerInterval(AutoIrrigationContracts::TIMER_IDENT_DAILY, 0);
            $this->SendDebug('scheduleAutoIrrigationDailyTimer', 'Invalid start time detected. Daily timer was disabled.', 0);
            return;
        }

        $now = new DateTimeImmutable('now');
        $nextRun = $now->setTime((int) $startTime['hour'], (int) $startTime['minute'], (int) $startTime['second']);

        if ($nextRun <= $now) {
            $nextRun = $nextRun->modify('+1 day');
        }

        $secondsUntilRun = $nextRun->getTimestamp() - $now->getTimestamp();
        $intervalMs = max(1000, $secondsUntilRun * 1000);
        $this->SetTimerInterval(AutoIrrigationContracts::TIMER_IDENT_DAILY, $intervalMs);

        $this->SendDebug(
            'scheduleAutoIrrigationDailyTimer',
            'Next irrigation run at ' . $nextRun->format('Y-m-d H:i:s') . ' (in ' . $secondsUntilRun . ' seconds).',
            0
        );
    }

    protected function scheduleAutoIrrigationRuntimeTick(): void
    {
        try {
            $this->SetTimerInterval(AutoIrrigationContracts::TIMER_IDENT_RUNTIME, AutoIrrigationContracts::RUNTIME_TICK_INTERVAL_MS);
        } catch (Throwable) {
        }
    }

    protected function stopAutoIrrigationRuntimeTick(): void
    {
        try {
            $this->SetTimerInterval(AutoIrrigationContracts::TIMER_IDENT_RUNTIME, 0);
        } catch (Throwable) {
        }
    }

    protected function ReadBufferedValue(string $key): string
    {
        if (property_exists($this, 'buffers') && is_array($this->buffers) && array_key_exists($key, $this->buffers)) {
            $value = $this->buffers[$key];

            return is_string($value) ? $value : (string) $value;
        }

        return (string) $this->GetBuffer($key);
    }

    protected function FinishAutoIrrigationRun(string $reason): void
    {
        $this->CloseAllValves();
        $this->SetAutoIrrigationRunState(AutoIrrigationContracts::RUN_STATE_FINISHED, $reason);
        $this->FinalizeAutoIrrigationRuntimeContext();
    }

    protected function FinalizeAutoIrrigationRuntimeContext(): void
    {
        $this->stopAutoIrrigationRuntimeTick();
        $this->ClearAutoIrrigationStopRequested();
        $this->ClearAutoIrrigationRunStartTimestamp();
        $this->ClearAutoIrrigationCurrentZoneIndex();
        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_PREPARED_ZONES, '');
        $this->ClearAutoIrrigationCurrentZoneRuntimeContext();
    }

    protected function BuildIrrigationMaxRuntimeSeconds(): int
    {
        $runtime = $this->decodeJsonArray($this->ReadPropertyString('IrrigationMaxRuntime'));

        $hours = isset($runtime['hour']) && is_numeric($runtime['hour']) ? (int) $runtime['hour'] : 0;
        $minutes = isset($runtime['minute']) && is_numeric($runtime['minute']) ? (int) $runtime['minute'] : 0;
        $seconds = isset($runtime['second']) && is_numeric($runtime['second']) ? (int) $runtime['second'] : 0;

        return max(0, ($hours * 3600) + ($minutes * 60) + $seconds);
    }

    // ============================================================
    // VALVE CONTROL & RUNTIME WATCHDOG
    // ============================================================

    /**
     * Creates and updates the dynamic variable profile
     * for valve selection in the front end based on the ZonesTree.
     *
     * @param array<int, array{Name: string, ID: int}> $frontendValves The list of zone names from the validation
     * @return void
     */
    protected function BuildActiveValveProfile(array $frontendValves): void
    {
        // Technically: create the dynamic valve-selection profile name for this instance.
        // Functional behavior: the front-end receives a fresh selection profile that matches the current zone list.
        // Domain rationale: the active valve selector must always reflect the live irrigation topology after configuration changes.
        $valveProfileName = 'GIC.Ventiles.' . $this->InstanceID;
        $varName = 'ActiveValve';
        $varDesc = $this->Translate('variable.active_valve');
        $varOrder = 100;

        $this->SendDebug('BuildActiveValveProfile', sprintf('Generating profile "%s" with %d valves.', $valveProfileName, count($frontendValves)), 0);

        // Disconnect the variable before deleting to bypass the delete lock
        $activeValveID = $this->GetExistingFrontendVariableID('ActiveValve');
        if ($activeValveID > 0) {
            $this->RegisterVariableInteger($varName, $varDesc, '', $varOrder);
        }

        // Delete old profile if it exists
        if (IPS_VariableProfileExists($valveProfileName)) {
            IPS_DeleteVariableProfile($valveProfileName);
        }

        // Create new profile
        IPS_CreateVariableProfile($valveProfileName, 1); // 1 = Integer
        IPS_SetVariableProfileIcon($valveProfileName, 'Valve');

        // First standard entry (value 0 for all closed)
        IPS_SetVariableProfileAssociation($valveProfileName, 0, $this->Translate('variable.active_valve.all_closed'), '', -1);

        // one entry per valve
        ksort($frontendValves);
        foreach ($frontendValves as $sequence => ['Name' => $valveName, 'ID' => $valveID]) {
            $this->SendDebug('BuildActiveValveProfile', sprintf('Association added: value (ID) %d => name: %s', $valveID, $valveName), 0);
            IPS_SetVariableProfileAssociation($valveProfileName, $valveID, $valveName, '', -1);
        }

        // Register variable with the target profile and enforce the profile assignment explicitly.
        // Some Symcon runtimes keep the previous custom profile when re-registering an existing ident.
        $this->RegisterVariableInteger($varName, $varDesc, $valveProfileName, $varOrder);

        $activeValveID = (int) $this->GetIDForIdent($varName);
        if ($activeValveID > 0) {
            IPS_SetVariableCustomProfile($activeValveID, $valveProfileName);

            $currentValue = GetValue($activeValveID);
            $validValveIds = array_map(static fn (array $valve): int => (int) $valve['ID'], $frontendValves);
            if ($currentValue !== 0 && !in_array((int) $currentValue, $validValveIds, true)) {
                SetValue($activeValveID, 0);
            }

            $this->SendDebug('BuildActiveValveProfile', sprintf('Profile "%s" explicitly assigned to variable %d.', $valveProfileName, $activeValveID), 0);
        }
    }

    /**
     * Close all valves configured in the zones tree for safety purposes.
     * Sends close command to all valves and defers validation to async handler.
     * Always use async validation to detect real hardware state after 1000ms.
     *
     * @return bool True (optimistic return - real validation happens async)
     */
    protected function CloseAllValves(): bool
    {
        $this->SetLastValveOperationType('close');
        $this->SetValveValidationRetryLimitReached(false);

        $zones = $this->getZonesTree();
        $result = $this->getValveControl()->closeAllValves($zones);

        foreach ($result['errors'] as $error) {
            $this->LogError(
                $error['context'],
                $this->translateMessage($error['translationKey'], $error['parameters']),
                $error['code']
            );
        }

        if (count($result['valvesToValidate']) > 0) {
            $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_PENDING_VALVE_VALIDATION, $this->encodeJsonArray($result['valvesToValidate']));
            $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_VALIDATE_RETRY_COUNT, '');
            $this->SetTimerInterval('ValidateValvesAsync', 1000);
        } elseif ($result['success'] && count($result['errors']) === 0) {
            // Technically: clear ValveControl context immediately when no async validation remains.
            // Functional behavior: successful synchronous close command leaves no stale valve error in ActiveErrors.
            // Domain rationale: old valve errors would incorrectly block the next daily start although current close succeeded.
            $this->ClearContextError('CloseAllValves');
        }

        // Every module-internal close operation resets runtime tracking.
        // Functional rule: timeout supervision only applies while a module-opened valve is expected to run.
        // Domain rationale: once shutdown is commanded, stale watchdog state would risk false timeout stops in later cycles.
        $this->ClearValveRuntimeTracking();

        return $result['success'];
    }

    /**
     * Close all valves, then open a single specific valve exclusively.
     * Sends control commands and defers all validation to async handler.
     * Always use async validation to detect real hardware state after 1000ms.
     *
     * @param int $valveId The IP-Symcon variable ID of the valve to open
     * @return bool True (optimistic return - real validation happens async)
     */
    protected function OpenSingleValve(int $valveId): bool
    {
        // Defensive cleanup before opening another valve.
        // Functional rule: the module tracks exactly one active runtime context at a time.
        // Domain rationale: overlapping tracker remnants would create false runtime accumulation and premature safety shutdowns.
        $this->ClearValveRuntimeTracking();

        $zones = $this->getZonesTree();
        $result = $this->getValveControl()->openSingleValve($valveId, $zones);

        foreach ($result['errors'] as $error) {
            $this->LogError(
                $error['context'],
                $this->translateMessage($error['translationKey'], $error['parameters']),
                $error['code']
            );
        }

        if (count($result['valvesToValidate']) > 0) {
            $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_PENDING_VALVE_VALIDATION, $this->encodeJsonArray($result['valvesToValidate']));
            $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_VALIDATE_RETRY_COUNT, '');
            $this->SetTimerInterval('ValidateValvesAsync', 1000);
        } elseif ($result['success'] && count($result['errors']) === 0) {
            // Technically: clear ValveControl context immediately when open/close command chain finished without async follow-up.
            // Functional behavior: successful exclusive open does not leave stale valve errors from previous runs.
            // Domain rationale: persistent false valve faults can suppress required irrigation starts and mislead operators.
            $this->ClearContextError('OpenSingleValve');
            $this->ClearContextError('CloseAllValves');
        }

        if ($result['success']) {
            $this->SetLastValveOperationType('open');
            $this->SetValveValidationRetryLimitReached(false);
            $this->StartValveRuntimeTracking($valveId);
        }

        return $result['success'];
    }

    /**
     * Starts a zone valve and converts the hardware action into a zone-local decision.
     *
     * @param int $valveId The IP-Symcon variable ID of the target valve.
     * @return array{opened: bool, skipZone: bool, reason: string, debugMessage: string}
     */
    protected function OpenZoneValveOrSkip(int $valveId): array
    {
        if ($valveId <= 0 || !IPS_ObjectExists($valveId)) {
            return [
                'opened'       => false,
                'skipZone'     => true,
                'reason'       => 'valve_open_failed',
                'debugMessage' => 'Valve start skipped: the valve ID is invalid or does not exist.',
            ];
        }

        if ($this->OpenSingleValve($valveId)) {
            return [
                'opened'       => true,
                'skipZone'     => false,
                'reason'       => 'valve_open_success',
                'debugMessage' => sprintf('Valve start succeeded: valve ID %d was opened exclusively.', $valveId),
            ];
        }

        return [
            'opened'       => false,
            'skipZone'     => true,
            'reason'       => 'valve_open_failed',
            'debugMessage' => sprintf('Valve start failed: valve ID %d could not be opened exclusively, so the zone is skipped.', $valveId),
        ];
    }

    /**
     * Stores the currently supervised valve runtime and starts the watchdog timer.
     */
    protected function StartValveRuntimeTracking(int $valveId): void
    {
        if ($valveId <= 0 || !IPS_ObjectExists($valveId)) {
            $this->SendDebug('StartValveRuntimeTracking', 'Aborted: invalid valve ID for runtime supervision.', 0);
            $this->ClearValveRuntimeTracking();
            return;
        }

        $payload = [
            'valveId'  => $valveId,
            'openedAt' => time(),
            'version'  => 1
        ];

        $encodedPayload = json_encode($payload);
        if (!is_string($encodedPayload)) {
            $this->SendDebug('StartValveRuntimeTracking', 'Aborted: runtime tracker could not be serialized.', 0);
            $this->ClearValveRuntimeTracking();
            return;
        }

        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_VALVE_RUNTIME_TRACKER, $encodedPayload);
        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_WATERED, '1');
        $this->SetTimerInterval('ValveRuntimeWatchdog', 30000);
        $this->SendDebug('StartValveRuntimeTracking', 'Runtime supervision started for valve ID ' . $valveId . '.', 0);
    }

    /**
     * Clears runtime tracking buffer and stops the watchdog timer.
     */
    protected function ClearValveRuntimeTracking(): void
    {
        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_VALVE_RUNTIME_TRACKER, '');
        $this->SetTimerInterval('ValveRuntimeWatchdog', 0);
    }

    /**
     * Stores the type of the last valve operation for start-blocker decisions.
     */
    protected function SetLastValveOperationType(string $operationType): void
    {
        if (!in_array($operationType, ['close', 'open'], true)) {
            $operationType = '';
        }

        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_LAST_VALVE_OPERATION_TYPE, $operationType);
    }

    /**
     * Returns the type of the last valve operation.
     */
    protected function GetLastValveOperationType(): string
    {
        return (string) $this->GetBuffer(AutoIrrigationContracts::BUFFER_KEY_LAST_VALVE_OPERATION_TYPE);
    }

    /**
     * Stores whether the deferred valve validation already exhausted its retry budget.
     */
    protected function SetValveValidationRetryLimitReached(bool $reached): void
    {
        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_VALVE_VALIDATION_RETRY_LIMIT_REACHED, $reached ? '1' : '');
    }

    /**
     * Returns whether the deferred valve validation exhausted its retry budget.
     */
    protected function IsValveValidationRetryLimitReached(): bool
    {
        return $this->GetBuffer(AutoIrrigationContracts::BUFFER_KEY_VALVE_VALIDATION_RETRY_LIMIT_REACHED) === '1';
    }

    /**
     * Supervises module-internal valve runtime and triggers a safety shutdown on timeout.
     */
    protected function HandleValveRuntimeWatchdog(): void
    {
        $trackerBuffer = $this->GetBuffer(AutoIrrigationContracts::BUFFER_KEY_VALVE_RUNTIME_TRACKER);
        if ($trackerBuffer === '') {
            $this->SetTimerInterval('ValveRuntimeWatchdog', 0);
            return;
        }

        $tracker = $this->decodeJsonArray($trackerBuffer);
        $trackedValveId = isset($tracker['valveId']) ? (int) $tracker['valveId'] : 0;
        $openedAt = isset($tracker['openedAt']) ? (int) $tracker['openedAt'] : 0;

        $valveObjectExists = $trackedValveId > 0 && IPS_ObjectExists($trackedValveId);
        $isOpen = null;

        try {
            if ($valveObjectExists) {
                $isOpen = (bool) GetValue($trackedValveId);
            }
        } catch (Throwable $e) {
            $this->SendDebug('HandleValveRuntimeWatchdog', 'Valve state could not be read: ' . $e->getMessage() . '. Supervision is being reset.', 0);
            $this->ClearValveRuntimeTracking();
            return;
        }

        $watchdogResult = $this->getValveControl()->evaluateRuntimeWatchdog(
            $trackedValveId,
            $openedAt,
            $valveObjectExists,
            $isOpen,
            $this->ReadPropertyInteger('IrrigationZoneMaxRuntime'),
            time()
        );

        if ($watchdogResult['action'] === 'invalid_tracker') {
            $this->SendDebug('HandleValveRuntimeWatchdog', $watchdogResult['debugMessage'], 0);
            $this->ClearValveRuntimeTracking();
            return;
        }

        if ($watchdogResult['action'] === 'already_closed') {
            $this->SendDebug('HandleValveRuntimeWatchdog', $watchdogResult['debugMessage'], 0);

            $activeValveID = (int) $this->GetIDForIdent('ActiveValve');
            if ($activeValveID > 0) {
                SetValue($activeValveID, 0);
            }

            $this->ClearValveRuntimeTracking();
            return;
        }

        if ($watchdogResult['action'] === 'within_limit') {
            return;
        }

        // Technically: persist the already irrigated seconds before the watchdog closes valves and clears tracker state.
        // Functional behavior: target_reached_gate can still detect that the zone runtime was consumed after watchdog intervention.
        // Domain rationale: without persisting elapsed runtime, the orchestrator would observe 0 elapsed minutes and keep a finished zone running until a global cutoff.
        $this->AddCurrentTrackedWateringSecondsToElapsedBuffer();

        // Technically: enforce hard runtime cap by forcing a global shutdown.
        // Functional behavior: every module-controlled valve run is stopped once the configured zone limit is exceeded.
        // Domain rationale: this limits over-irrigation and hardware stress when a sequence does not end as expected.
        $this->SendDebug(
            'HandleValveRuntimeWatchdog',
            $watchdogResult['debugMessage'],
            0
        );

        if (!$this->CloseAllValves()) {
            $this->SendDebug('HandleValveRuntimeWatchdog', 'Safety shutdown could not be completed successfully.', 0);
            $this->StartValveRuntimeTracking($trackedValveId);
            return;
        }

        $activeValveID = (int) $this->GetIDForIdent('ActiveValve');
        if ($activeValveID > 0) {
            SetValue($activeValveID, 0);
        }
    }

    // ============================================================
    // LAWN COOLING
    // ============================================================

    /**
     * Handles the lawn cooling daily timer entrypoint.
     */
    protected function HandleLawnCoolingDaily(): void
    {
        $context = 'LawnCoolingDaily';

        // Technically: consume the advisory precheck marker when the real daily entrypoint begins.
        // Functional behavior: the skip action now derives from the active PRECHECK/RUNNING state instead of an old lead-window marker.
        // Domain rationale: the advisory decision has reached its final start gate and must not remain as an independent runtime state.
        $this->SetLawnCoolingPrecheckActive(false);

        // Technically: re-evaluate configuration, operating mode, maintenance mode and hard valve blockers at the real start time.
        // Functional behavior: the daily entrypoint cannot proceed when any shared global prerequisite is invalid.
        // Domain rationale: a precheck is only advisory; late configuration or hardware changes must still prevent unsafe watering.
        $globalPreconditions = $this->recheck_global_preconditions($context);
        if (!$globalPreconditions['allowed']) {
            $this->SendDebug($context, $globalPreconditions['debugMessage'], 0);
            $this->LawnCoolingGlobalStopAndEnd($globalPreconditions['reason'], $globalPreconditions['debugMessage']);
            $this->scheduleLawnCoolingDailyTimer();
            return;
        }

        // Technically: reject a daily entrypoint while the cooling state machine already owns an active run.
        // Functional behavior: a delayed or duplicated timer callback cannot replace the current zone plan.
        // Domain rationale: restarting during heat relief could reset elapsed runtime and cause excessive watering.
        if ($this->IsLawnCoolingRunActive()) {
            $this->SendDebug($context, 'Daily timer skipped: lawn cooling is already active.', 0);
            $this->scheduleLawnCoolingDailyTimer();
            return;
        }

        // Technically: enter PRECHECK before evaluating cooling-specific gates.
        // Functional behavior: the frontend reflects that the real start decision is being evaluated, not that a valve is running.
        // Domain rationale: distinguishing decision time from irrigation time prevents operators from mistaking a pending run for active watering.
        $this->SetLawnCoolingRunState(LawnCoolingContracts::RUN_STATE_PRECHECK, 'Final start check started.');

        // Technically: reject non-lawn contexts and the disabled cooling feature before acquiring runtime ownership.
        // Functional behavior: a stale timer cannot start cooling after the system type or enable flag changed.
        // Domain rationale: lawn cooling is an opt-in relief action and must never operate as a generic irrigation fallback.
        if ($this->ReadPropertyString('SystemType') !== 'lawn' || !$this->ReadPropertyBoolean('CoolingEnable')) {
            $this->LawnCoolingGlobalStopAndEnd(
                LawnCoolingContracts::STOP_REASON_GLOBAL_PRECHECK_FAIL,
                'Final start check aborted: lawn cooling is not enabled for the current module context.'
            );
            $this->scheduleLawnCoolingDailyTimer();
            return;
        }

        // Technically: acquire the dedicated semaphore before the final gate sequence and release it in the enclosing finally block.
        // Functional behavior: concurrent cooling entrypoints are reduced to one deterministic decision path.
        // Domain rationale: overlapping starts could issue conflicting valve commands and invalidate stop-signal ownership.
        if (!IPS_SemaphoreEnter(LawnCoolingContracts::RUN_LOCK_KEY, 1000)) {
            $this->SetLawnCoolingRunState(LawnCoolingContracts::RUN_STATE_STOPPED, 'Another lawn-cooling run is already active.');
            $this->SendDebug($context, 'Final start check aborted: another lawn-cooling run already holds the run lock.', 0);
            $this->scheduleLawnCoolingDailyTimer();
            return;
        }

        $autoIrrigationLockAcquired = false;
        try {
            $this->SendDebug($context, 'Final start check started: global recheck succeeded.', 0);

            // Technically: compare the complete cooling interval with the complete normal-irrigation interval.
            // Functional behavior: any overlap becomes a global stop before weather data or zone work can authorize cooling.
            // Domain rationale: two water-consuming programs must not compete for the same hydraulic and soil-water window.
            if ($this->isLawnCoolingTimeWindowBlocked($context)) {
                $this->LawnCoolingGlobalStopAndEnd(
                    LawnCoolingContracts::STOP_REASON_GLOBAL_TIMER_WINDOW_BLOCKED,
                    'Final start check blocked: cooling window overlaps normal irrigation.'
                );
                return;
            }

            // Technically: evaluate the six-hour rainfall threshold with the configured source priority and fail-open fallback.
            // Functional behavior: rainfall above 2 mm blocks the run, while unavailable rainfall data does not.
            // Domain rationale: recent effective rain makes cooling redundant, but missing telemetry must not suppress heat relief.
            $rainGate = $this->evaluateLawnCoolingRainGate($context);
            if (!$rainGate['allowed']) {
                $this->LawnCoolingEndBeforeRuntime($rainGate['reason'], $rainGate['debugMessage']);
                return;
            }

            // Technically: evaluate today's archive series for a continuous five-minute threshold event.
            // Functional behavior: a current value or isolated spike cannot authorize the run.
            // Domain rationale: sustained heat stress, rather than sensor noise, is required before adding cooling water.
            $temperatureGate = $this->evaluateLawnCoolingTemperatureGate($context);
            if (!$temperatureGate['allowed']) {
                $this->LawnCoolingEndBeforeRuntime($temperatureGate['reason'], $temperatureGate['debugMessage']);
                return;
            }

            // Technically: filter active UseCooling zones, order them by Sequence and apply the start-only soil gate.
            // Functional behavior: the final start requires at least one eligible cooling zone.
            // Domain rationale: cooling must remain explicitly zone-scoped and avoid wet root zones where relief watering is unnecessary.
            $zoneCandidates = $this->EvaluateLawnCoolingZoneCandidates($context);
            if (!$zoneCandidates['allowed']) {
                $terminalState = $zoneCandidates['reason'] === LawnCoolingContracts::STOP_REASON_ZONE_NO_ACTIVE_COOLING_ZONES
                    || $zoneCandidates['reason'] === LawnCoolingContracts::STOP_REASON_ZONE_SOIL_START_BLOCKED
                    ? LawnCoolingContracts::RUN_STATE_FINISHED
                    : LawnCoolingContracts::RUN_STATE_STOPPED;
                $this->LawnCoolingEndBeforeRuntime(
                    $zoneCandidates['reason'],
                    $zoneCandidates['debugMessage'],
                    $terminalState
                );
                return;
            }

            // Technically: inspect stop and skip buffers immediately before runtime ownership is handed to the active state.
            // Functional behavior: a late user or safety signal wins over every earlier successful gate.
            // Domain rationale: no valve operation may follow a stop decision made after the advisory checks.
            $checkpoint = $this->evaluateLawnCoolingSignalCheckpoint($context, false);
            if (!$checkpoint['allowed']) {
                if ($checkpoint['hardStop']) {
                    $this->LawnCoolingGlobalStopAndEnd($checkpoint['reason'], $checkpoint['debugMessage']);
                } else {
                    $this->SetLawnCoolingRunState(LawnCoolingContracts::RUN_STATE_STOPPED, $checkpoint['debugMessage']);
                    $this->ClearAllLawnCoolingSignals();
                }
                return;
            }

            // Technically: probe the shared AutoIrrigation semaphore before marking cooling as runnable.
            // Functional behavior: cooling yields while the normal irrigation runtime owns its execution lock.
            // Domain rationale: the two water programs must never start concurrently because their hydraulic effects cannot be separated safely.
            $autoIrrigationLockAcquired = IPS_SemaphoreEnter(AutoIrrigationContracts::RUN_LOCK_KEY, 1000);
            if (!$autoIrrigationLockAcquired) {
                $this->SetLawnCoolingRunState(LawnCoolingContracts::RUN_STATE_STOPPED, 'Final start check blocked: normal irrigation holds the run lock.');
                $this->ClearAllLawnCoolingSignals();
                return;
            }

            // Technically: persist the ordered candidates and reset the volatile runtime cursor after all final gates and both lock checks pass.
            // Functional behavior: the next asynchronous tick receives a stable zone plan and starts at the first eligible zone.
            // Domain rationale: a fixed plan prevents configuration reordering during a heat-relief run and avoids opening an unintended zone.
            $this->SetBuffer(LawnCoolingContracts::BUFFER_KEY_PREPARED_ZONES, $this->encodeJsonArray($zoneCandidates['zones']));
            $this->SetBuffer(LawnCoolingContracts::BUFFER_KEY_CURRENT_ZONE_INDEX, '0');
            $this->SetBuffer(LawnCoolingContracts::BUFFER_KEY_ZONE_STARTED_AT, '');

            // Technically: transition to RUNNING and schedule the non-blocking runtime timer.
            // Functional behavior: the actual zone loop continues asynchronously without holding a PHP thread or semaphore between ticks.
            // Domain rationale: valve duration must be supervised by SymCon timers so a stalled process cannot silently overwater.
            $this->SetLawnCoolingRunState(
                LawnCoolingContracts::RUN_STATE_RUNNING,
                sprintf('Final start check succeeded; %d cooling zone(s) are ready to start.', count($zoneCandidates['zones']))
            );
            $this->ClearContextError($context);
            $this->SendDebug($context, 'Final start check succeeded. Starting asynchronous zone run.', 0);
            $this->scheduleLawnCoolingRuntimeTick();
        } catch (Throwable $e) {
            // Technically: convert unexpected gate or adapter failures into the common global stop path.
            // Functional behavior: all valves are closed, the run is stopped and volatile signals are cleared.
            // Domain rationale: an unknown start-path failure must fail closed rather than risk an unmonitored cooling action.
            $this->LogError($context, 'Final start check failed: ' . $e->getMessage(), 430);
            $this->LawnCoolingGlobalStopAndEnd(LawnCoolingContracts::STOP_REASON_GLOBAL_PRECHECK_FAIL, 'Error during the final start check.');
        } finally {
            if ($autoIrrigationLockAcquired) {
                IPS_SemaphoreLeave(AutoIrrigationContracts::RUN_LOCK_KEY);
            }

            IPS_SemaphoreLeave(LawnCoolingContracts::RUN_LOCK_KEY);
            $this->scheduleLawnCoolingDailyTimer();
        }
    }

    /**
     * Advances one asynchronous lawn-cooling runtime slice.
     */
    protected function HandleLawnCoolingRuntimeTick(): void
    {
        $context = 'LawnCoolingRuntimeTick';
        if (!$this->IsLawnCoolingRunActive()) {
            $this->stopLawnCoolingRuntimeTick();
            return;
        }

        // Technically: acquire both runtime semaphores for this short callback only.
        // Functional behavior: one cooling slice executes exclusively and cannot overlap normal irrigation work.
        // Domain rationale: holding a semaphore across timer ticks is unsafe, while releasing it during valve work could permit conflicting hydraulic commands.
        if (!IPS_SemaphoreEnter(LawnCoolingContracts::RUN_LOCK_KEY, 1000)) {
            $this->SendDebug($context, 'Runtime tick skipped: another lawn-cooling run holds the run lock.', 0);
            return;
        }

        $autoLockAcquired = false;
        try {
            $autoLockAcquired = IPS_SemaphoreEnter(AutoIrrigationContracts::RUN_LOCK_KEY, 1000);
            if (!$autoLockAcquired) {
                $this->SendDebug($context, 'Runtime tick postponed: normal irrigation holds the run lock.', 0);
                return;
            }

            $zones = $this->decodeJsonArray($this->ReadBufferedValue(LawnCoolingContracts::BUFFER_KEY_PREPARED_ZONES));
            $currentIndex = $this->readLawnCoolingBufferInt(LawnCoolingContracts::BUFFER_KEY_CURRENT_ZONE_INDEX);
            $startedAt = $this->readLawnCoolingBufferNullableInt(LawnCoolingContracts::BUFFER_KEY_ZONE_STARTED_AT);
            $orchestrator = $this->getLawnCoolingRuntimeOrchestrator();
            $result = $orchestrator->runTick(
                $zones,
                $currentIndex,
                $startedAt,
                $this->ReadPropertyInteger('CoolingTime') * 60,
                [
                    'recheckGlobal'     => fn (): array => $this->recheckLawnCoolingRuntimeGlobalPreconditions($context),
                    'checkSignals'      => fn (bool $firstValveOpened): array => $this->evaluateLawnCoolingSignalCheckpoint($context, $firstValveOpened),
                    'evaluateSoilStart' => fn (array $zone): array => $this->evaluateLawnCoolingZoneSoilStart($zone, $context),
                    'openSingleValve'   => function (int $valveId): bool
                    {
                        $opened = $this->OpenSingleValve($valveId);
                        if ($opened) {
                            $this->SetLawnCoolingFirstValveOpened(true);
                        }

                        return $opened;
                    },
                    'closeAllValves' => fn (): bool => $this->CloseAllValves(),
                ]
            );

            $this->SetBuffer(LawnCoolingContracts::BUFFER_KEY_CURRENT_ZONE_INDEX, (string) $result['nextIndex']);
            $this->SetBuffer(
                LawnCoolingContracts::BUFFER_KEY_ZONE_STARTED_AT,
                $result['startedAt'] === null ? '' : (string) $result['startedAt']
            );
            $this->SendDebug($context, (string) $result['reason'], 0);

            if ($result['outcome'] === 'active') {
                $this->scheduleLawnCoolingRuntimeTick();
                return;
            }

            $this->stopLawnCoolingRuntimeTick();
            if ($result['globalStop']) {
                $stopReason = (string) $result['stopReason'];
                $this->LawnCoolingGlobalStopAndEnd(
                    $stopReason !== '' ? $stopReason : LawnCoolingContracts::STOP_REASON_GLOBAL_STOP_REQUESTED,
                    (string) $result['reason']
                );
                return;
            }

            $this->ClearContextError($context);
            $this->SetLawnCoolingRunState(
                $result['outcome'] === 'finished'
                    ? LawnCoolingContracts::RUN_STATE_FINISHED
                    : LawnCoolingContracts::RUN_STATE_STOPPED,
                (string) $result['reason']
            );
            $this->ClearAllLawnCoolingSignals();
            $this->clearLawnCoolingRuntimeBuffers();
        } catch (Throwable $e) {
            $this->LogError($context, 'Lawn-cooling runtime failed: ' . $e->getMessage(), 430);
            $this->LawnCoolingGlobalStopAndEnd(LawnCoolingContracts::STOP_REASON_GLOBAL_PRECHECK_FAIL, 'Error in the lawn-cooling runtime tick.');
        } finally {
            if ($autoLockAcquired) {
                IPS_SemaphoreLeave(AutoIrrigationContracts::RUN_LOCK_KEY);
            }

            IPS_SemaphoreLeave(LawnCoolingContracts::RUN_LOCK_KEY);
        }
    }

    /**
     * Handles the lawn cooling precheck timer entrypoint.
     */
    protected function HandleLawnCoolingPrecheckTimer(): void
    {
        $this->HandleCoolingPrecheckTimer();
    }

    /**
     * Handles the lawn cooling precheck timer entrypoint.
     */
    protected function HandleCoolingPrecheckTimer(): void
    {
        $this->SetLawnCoolingPrecheckActive(true);
        $this->syncLawnCoolingSkipActionAvailability();
        $keepPrecheckActive = false;

        try {
            $context = 'CoolingPrecheckTimer';

            // Technically: reuse the shared global precondition evaluator for the advisory timer.
            // Functional behavior: invalid configuration or operating mode suppresses the precheck and any notification opportunity.
            // Domain rationale: the advisory path must use the same safety hierarchy as the real start path.
            $globalPreconditions = $this->recheck_global_preconditions($context);
            if (!$globalPreconditions['allowed']) {
                $this->SendDebug($context, $globalPreconditions['debugMessage'], 0);
                $this->LawnCoolingGlobalStopAndEnd(
                    $globalPreconditions['reason'],
                    $globalPreconditions['debugMessage']
                );
                $this->scheduleCoolingPrecheckTimer();
                return;
            }

            // Technically: reject stale or manually triggered prechecks outside the lawn-cooling context.
            // Functional behavior: no advisory state or push notification is produced when cooling is disabled or the system type changed.
            // Domain rationale: the precheck is informational only, but a misleading readiness notification could prompt an invalid operator decision.
            if ($this->ReadPropertyString('SystemType') !== 'lawn' || !$this->ReadPropertyBoolean('CoolingEnable')) {
                $this->SendDebug($context, 'Precheck skipped: SystemType or CoolingEnable is incompatible.', 0);
                return;
            }

            // Technically: evaluate the complete interval overlap before weather and zone gates.
            // Functional behavior: a precheck cannot report cooling as eligible when it would collide with normal irrigation.
            // Domain rationale: early detection avoids misleading readiness signals for a hydraulically unsafe schedule.
            if ($this->isLawnCoolingTimeWindowBlocked($context)) {
                $this->LawnCoolingGlobalStopAndEnd(
                    LawnCoolingContracts::STOP_REASON_GLOBAL_TIMER_WINDOW_BLOCKED,
                    'Precheck blocked: cooling window overlaps normal irrigation.'
                );
                $this->scheduleCoolingPrecheckTimer();
                return;
            }

            // Technically: apply the six-hour rainfall gate using the same source and threshold policy as the final start.
            // Functional behavior: a blocked rainfall result suppresses the advisory outcome without starting anything.
            // Domain rationale: recent rain already supplies the relevant short-term water effect.
            $rainGate = $this->evaluateLawnCoolingRainGate($context);
            if (!$rainGate['allowed']) {
                $this->scheduleCoolingPrecheckTimer();
                return;
            }

            // Technically: require the archived five-minute temperature event before reporting an advisory success.
            // Functional behavior: transient or missing temperature data cannot trigger a positive precheck.
            // Domain rationale: cooling is justified by sustained thermal load, not a single measurement.
            $temperatureGate = $this->evaluateLawnCoolingTemperatureGate($context);
            if (!$temperatureGate['allowed']) {
                $this->scheduleCoolingPrecheckTimer();
                return;
            }

            // Technically: evaluate volatile stop and skip signals before completing the advisory result.
            // Functional behavior: a user cancellation or active stop prevents a misleading successful precheck.
            // Domain rationale: user intent and safety signals must dominate informational readiness.
            $checkpoint = $this->evaluateLawnCoolingSignalCheckpoint($context, $this->IsLawnCoolingFirstValveOpened());
            if (!$checkpoint['allowed']) {
                $this->SendDebug($context, $checkpoint['debugMessage'], 0);
                $this->scheduleCoolingPrecheckTimer();
                return;
            }

            // Technically: prepare and soil-filter the active cooling zones without opening any valve.
            // Functional behavior: the advisory result is positive only when a later start has at least one eligible zone.
            // Domain rationale: a notification for a run with no usable zone would mislead the operator.
            $zoneCandidates = $this->EvaluateLawnCoolingZoneCandidates($context);
            if (!$zoneCandidates['allowed']) {
                $this->scheduleCoolingPrecheckTimer();
                return;
            }

            $this->SendDebug($context, sprintf('Precheck succeeded: %d cooling zone(s) are ready to start.', count($zoneCandidates['zones'])), 0);
            $keepPrecheckActive = true;
            $this->sendLawnCoolingPrecheckPush();
            $this->scheduleCoolingPrecheckTimer();
        } finally {
            if (!$keepPrecheckActive) {
                $this->SetLawnCoolingPrecheckActive(false);
                $this->syncLawnCoolingSkipActionAvailability();
            }
        }
    }

    /**
     * Rechecks all global prerequisites immediately before a cooling action.
     *
     * Technically: combines the module-status guard, operating-mode flags and
     * the existing hard valve-start blocker into one deterministic result.
     * Functionally: no cooling entrypoint may continue while configuration,
     * automatic mode, maintenance mode or valve safety is invalid.
     * Domain rationale: a change after ApplyChanges must take effect before a
     * valve can be opened; otherwise watering could continue during maintenance
     * or after a failed valve closure.
     *
     * @return array{allowed: bool, reason: string, debugMessage: string}
     */
    protected function recheck_global_preconditions(string $context): array
    {
        // Technically: centralize configuration, mode, maintenance and valve-error evaluation in one reusable service.
        // Functional behavior: AutoIrrigation and Lawn Cooling receive the same global start decision.
        // Domain rationale: divergent guard logic could allow one water program to run while the other is unsafe.
        $result = (new GlobalPreconditionEvaluator())->evaluate(
            $this->isModuleConfigurationInvalid($context),
            $this->ReadPropertyBoolean('SystemAutoEnable'),
            $this->ReadPropertyBoolean('SystemMaintenanceEnable'),
            $this->decodeJsonArray($this->ReadAttributeString('ActiveErrors')),
            $this->GetLastValveOperationType(),
            $this->IsValveValidationRetryLimitReached()
        );

        return $result;
    }

    /**
     * Reads the configured archive sources and delegates the six-hour threshold decision.
     *
     * Technical behavior: prefer the archived daily counter, otherwise use rainfall history and fail open on unavailable data.
     * Functional behavior: return one normalized gate result for both precheck and final start.
     * Domain rationale: recent rain should suppress redundant cooling, while telemetry gaps must not prevent heat relief.
     *
     * @return array{allowed: bool, reason: string, debugMessage: string}
     */
    protected function evaluateLawnCoolingRainGate(string $context): array
    {
        $now = new DateTimeImmutable('now');
        $start = $now->modify('-6 hours');
        $rainGate = new LawnCoolingRainGate();

        try {
            $archiveId = $this->GetArchiveInstanceID();
            $rainDayId = $this->normalizeOptionalObjectId($this->ReadPropertyInteger('RainDayVarID'));
            if (IPS_ObjectExists($rainDayId)) {
                $this->validateArchiveVariable($rainDayId, $archiveId, $this->Translate('error.context.daily_rain_variable'), false);
                $archiveValuesRaw = AC_GetLoggedValues($archiveId, $rainDayId, $start->getTimestamp() - 86400, $now->getTimestamp(), 10000);
                $archiveValues = is_array($archiveValuesRaw) ? $archiveValuesRaw : [];
                $result = $rainGate->evaluate($rainGate->calculateDayCounterRainfall($archiveValues, $start, $now));
                $this->SendDebug($context, '[Source: Daily counter] ' . $result['debugMessage'], 0);
                $this->ClearContextError($context);
                return $result;
            }

            $rainId = $this->ReadPropertyInteger('RainVarID');
            $this->validateArchiveVariable($rainId, $archiveId, $this->Translate('error.context.rain_variable'));
            $calculator = new RainHistoryCalculator($this->getDebugLogger());
            $rainfall = $calculator->getRainfallForDate(
                $archiveId,
                $rainId,
                $start->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s')
            );
            $result = $rainGate->evaluate($rainfall);
            $this->ClearContextError($context);
        } catch (Throwable $e) {
            $result = $rainGate->evaluate(null);
            $this->SendDebug($context, 'Rain check cannot be evaluated technically; fail-open fallback is active. ' . $e->getMessage(), 0);
            return $result;
        }

        $this->SendDebug($context, '[Source: Rain intensity] ' . $result['debugMessage'], 0);
        $this->ClearContextError($context);
        return $result;
    }

    /**
     * Compares the complete cooling interval with the configured irrigation interval.
     *
     * Technical behavior: delegate interval arithmetic to ConfigurationValidator.
     * Functional behavior: return one global overlap decision for precheck and final start.
     * Domain rationale: simultaneous water demand can distort hydraulic pressure and root-zone water assumptions.
     */
    protected function isLawnCoolingTimeWindowBlocked(string $context): bool
    {
        $validator = new ConfigurationValidator();
        $overlap = $validator->coolingWindowsOverlap(
            $this->decodeJsonArray($this->ReadPropertyString('CoolingStartTime')),
            $this->ReadPropertyInteger('CoolingTime'),
            $this->decodeJsonArray($this->ReadPropertyString('IrrigationStartTime')),
            $this->decodeJsonArray($this->ReadPropertyString('IrrigationMaxRuntime'))
        );

        $this->SendDebug($context, $overlap
            ? 'Cooling blocked: time windows overlap.'
            : 'Cooling time window does not overlap normal irrigation.', 0);

        return $overlap;
    }

    /**
     * Loads today's archived temperature history and delegates the sustained-threshold decision.
     *
     * Technical behavior: evaluate samples from local midnight through now against the five-minute event rule.
     * Functional behavior: only a valid continuous heat event permits cooling to proceed.
     * Domain rationale: isolated sensor spikes do not establish persistent turf heat stress and should not trigger watering.
     *
     * @return array{allowed: bool, reason: string, debugMessage: string}
     */
    protected function evaluateLawnCoolingTemperatureGate(string $context): array
    {
        $temperatureVarId = $this->ReadPropertyInteger('TemperatureVarID');
        if ($temperatureVarId <= 0 || !IPS_ObjectExists($temperatureVarId)) {
            $this->SendDebug($context, 'Temperature gate blocked: temperature variable is invalid.', 0);

            return [
                'allowed'      => false,
                'reason'       => LawnCoolingContracts::STOP_REASON_ZONE_NO_VALID_TEMPERATURE_ARCHIVE,
                'debugMessage' => 'Temperature gate blocked: temperature variable is invalid.',
            ];
        }

        try {
            $archiveId = $this->GetArchiveInstanceID();
            $this->validateArchiveVariable($temperatureVarId, $archiveId, $this->Translate('error.context.temperature_variable'));
            $now = new DateTimeImmutable('now');
            $todayStart = new DateTimeImmutable('today');
            $archiveValuesRaw = AC_GetLoggedValues($archiveId, $temperatureVarId, $todayStart->getTimestamp(), $now->getTimestamp(), 0);
            $archiveValues = is_array($archiveValuesRaw) ? $archiveValuesRaw : [];
        } catch (Throwable $e) {
            $this->SendDebug($context, 'Temperature gate blocked: archive values could not be read. ' . $e->getMessage(), 0);
            return [
                'allowed'      => false,
                'reason'       => LawnCoolingContracts::STOP_REASON_ZONE_NO_VALID_TEMPERATURE_ARCHIVE,
                'debugMessage' => 'Temperature gate blocked: no valid temperature archive values are available.',
            ];
        }

        $result = (new LawnCoolingTemperatureGate())->evaluate(
            $archiveValues,
            $this->ReadPropertyInteger('CoolingTemp'),
            $todayStart,
            $now
        );
        $this->SendDebug($context, $result['debugMessage'], 0);
        if ($result['allowed']) {
            $this->ClearContextError($context);
        }

        return $result;
    }

    /**
     * Builds the final list of zones that can participate in cooling.
     *
     * Technical behavior: delegate filtering, ordering and start-only soil evaluation to the cooling services.
     * Functional behavior: return only active, explicitly enabled and start-eligible zones.
     * Domain rationale: cooling is an opt-in relief action and must not water inactive, non-cooling or already-moist zones.
     *
     * @return array{allowed: bool, zones: array<int, array<string, mixed>>, skippedZones: array<int, array{zone: array<string, mixed>, reason: string, debugMessage: string}>, reason: string, debugMessage: string}
     */
    protected function EvaluateLawnCoolingZoneCandidates(string $context): array
    {
        $planner = $this->getLawnCoolingZoneStartPlanner();
        $coolingZones = $planner->prepareActiveCoolingZones($this->getZonesTree());
        $zoneInputs = $coolingZones === [] ? [] : $this->BuildLawnCoolingZoneCandidateInputs($coolingZones);

        return $this->getLawnCoolingZoneCandidateService()->evaluateCandidates($planner, $coolingZones, $zoneInputs, $context);
    }

    /**
     * @param array<int, array<string, mixed>> $coolingZones
     * @return array<int, array{zoneLabel: string, zoneSoilValue: float|null, globalSoilValue: float|null, useGlobalSoil: bool, soilMinMoisture: int, zoneSoilVarId: int, globalSoilVarId: int}>
     *
     * Technical behavior: resolve the configured zone and optional global soil inputs into the service contract.
     * Functional behavior: every candidate receives deterministic sensor-priority and threshold data.
     * Domain rationale: zone-local moisture must take precedence, while missing optional telemetry must not create false blocking.
     */
    protected function BuildLawnCoolingZoneCandidateInputs(array $coolingZones): array
    {
        $globalSoilVarId = $this->normalizeOptionalObjectId($this->ReadPropertyInteger('GlobalSoilMoistureVarID'));
        $globalSoilValue = $this->ReadOptionalFloatValue($globalSoilVarId);
        $zoneInputs = [];

        foreach ($coolingZones as $index => $zone) {
            $zoneSoilVarId = $this->extractZoneSoilMoistureVarId($zone);
            $zoneInputs[$index] = [
                'zoneLabel'          => $this->BuildRuntimeZoneLabel($zone),
                'zoneSoilValue'      => $this->ReadOptionalFloatValue($zoneSoilVarId),
                'globalSoilValue'    => $globalSoilValue,
                'useGlobalSoil'      => $this->extractZoneUseGlobalSoilMoisture($zone),
                'soilMinMoisture'    => $this->ReadPropertyInteger('SoilMinMoisture'),
                'zoneSoilVarId'      => $zoneSoilVarId,
                'globalSoilVarId'    => $globalSoilVarId,
            ];
        }

        return $zoneInputs;
    }

    /**
     * Evaluates the cooling zone's start-only soil gate using the canonical source priority.
     *
     * Technical behavior: resolve zone and optional global readings, then delegate threshold evaluation to the cooling planner.
     * Functional behavior: wet zones are skipped locally while missing optional telemetry does not block a cooling zone.
     * Domain rationale: cooling targets heat relief, so it must avoid unnecessary saturation without requiring every optional sensor to be available.
     *
     * @param array<string, mixed> $zone
     * @return array{allowed: bool, skipZone: bool, source: string, measuredSoil: ?float, reason: string, debugMessage: string}
     */
    protected function evaluateLawnCoolingZoneSoilStart(array $zone, string $context): array
    {
        $zoneSoilVarId = $this->extractZoneSoilMoistureVarId($zone);
        $globalSoilVarId = $this->normalizeOptionalObjectId($this->ReadPropertyInteger('GlobalSoilMoistureVarID'));
        $result = $this->getLawnCoolingZoneStartPlanner()->evaluateSoilStartGate(
            $this->ReadOptionalFloatValue($zoneSoilVarId),
            $this->ReadOptionalFloatValue($globalSoilVarId),
            $this->extractZoneUseGlobalSoilMoisture($zone),
            $this->ReadPropertyInteger('SoilMinMoisture'),
            $zoneSoilVarId,
            $globalSoilVarId
        );
        $this->SendDebug($context, $this->BuildRuntimeZoneLabel($zone) . ': ' . $result['debugMessage'], 0);

        return $result;
    }

    /**
     * Rechecks all global runtime conditions that can invalidate an active cooling slice.
     *
     * Technical behavior: combine the shared global evaluator with cooling context and interval guards.
     * Functional behavior: an active zone stops immediately when mode, configuration or schedule safety changes.
     * Domain rationale: a run that was safe at start can become unsafe after a property change or a competing irrigation window begins.
     *
     * @return array{allowed: bool, reason: string, debugMessage: string}
     */
    protected function recheckLawnCoolingRuntimeGlobalPreconditions(string $context): array
    {
        $result = $this->recheck_global_preconditions($context);
        if (!$result['allowed']) {
            return $result;
        }

        if ($this->ReadPropertyString('SystemType') !== 'lawn' || !$this->ReadPropertyBoolean('CoolingEnable')) {
            return [
                'allowed'      => false,
                'reason'       => LawnCoolingContracts::STOP_REASON_GLOBAL_PRECHECK_FAIL,
                'debugMessage' => 'Runtime recheck failed: lawn cooling is no longer active or the module context is invalid.',
            ];
        }

        if ($this->isLawnCoolingTimeWindowBlocked($context)) {
            return [
                'allowed'      => false,
                'reason'       => LawnCoolingContracts::STOP_REASON_GLOBAL_TIMER_WINDOW_BLOCKED,
                'debugMessage' => 'Runtime recheck failed: cooling window overlaps normal irrigation.',
            ];
        }

        return [
            'allowed'      => true,
            'reason'       => '',
            'debugMessage' => 'Runtime recheck succeeded.',
        ];
    }

    protected function scheduleLawnCoolingRuntimeTick(): void
    {
        $this->SetTimerInterval(LawnCoolingContracts::TIMER_IDENT_RUNTIME, LawnCoolingContracts::RUNTIME_TICK_INTERVAL_MS);
    }

    protected function stopLawnCoolingRuntimeTick(): void
    {
        $this->SetTimerInterval(LawnCoolingContracts::TIMER_IDENT_RUNTIME, 0);
    }

    protected function readLawnCoolingBufferInt(string $key): int
    {
        $value = trim($this->ReadBufferedValue($key));

        return $value !== '' && is_numeric($value) ? max(0, (int) $value) : 0;
    }

    protected function readLawnCoolingBufferNullableInt(string $key): ?int
    {
        $value = trim($this->ReadBufferedValue($key));

        return $value !== '' && is_numeric($value) ? (int) $value : null;
    }

    protected function clearLawnCoolingRuntimeBuffers(): void
    {
        $this->SetBuffer(LawnCoolingContracts::BUFFER_KEY_PREPARED_ZONES, '');
        $this->SetBuffer(LawnCoolingContracts::BUFFER_KEY_CURRENT_ZONE_INDEX, '');
        $this->SetBuffer(LawnCoolingContracts::BUFFER_KEY_ZONE_STARTED_AT, '');
    }

    /**
     * Ends a cooling start attempt without classifying a normal gate result as a global safety stop.
     *
     * Technically: set the appropriate terminal state and clear volatile runtime state without raising the global stop signal.
     * Functional behavior: weather and zone decisions end only the current daily attempt, while configuration and hardware failures keep the hard-stop path.
     * Domain rationale: no valve has opened at this point, so a normal start blocker must not look like a system-wide safety failure.
     */
    protected function LawnCoolingEndBeforeRuntime(
        string $reason,
        string $stateReason,
        string $terminalState = LawnCoolingContracts::RUN_STATE_STOPPED
    ): void {
        $this->SendDebug('LawnCooling', sprintf('Cooling-Startversuch beendet (%s): %s', $reason, $stateReason), 0);
        $this->SetLawnCoolingRunState($terminalState, $stateReason);
        $this->ClearAllLawnCoolingSignals();
        $this->stopLawnCoolingRuntimeTick();
        $this->clearLawnCoolingRuntimeBuffers();
    }

    /**
     * Performs the fail-safe terminal action for a global cooling blocker.
     */
    protected function LawnCoolingGlobalStopAndEnd(string $reason, string $stateReason): void
    {
        // Technically: request a stop, close every valve, set STOPPED and clear volatile signals.
        // Functionally: a global blocker terminates cooling without permitting another valve operation.
        // Domain rationale: configuration or mode changes can make continued watering unsafe, while signal cleanup prevents stale stops from leaking into the next day.
        $this->SendDebug('LawnCooling', sprintf('Globaler Cooling-Stop (%s): alle Ventile werden geschlossen.', $reason), 0);
        $this->SetLawnCoolingStopRequested(true);
        $closeSucceeded = true;
        if ($this->IsLawnCoolingRunActive()) {
            $closeSucceeded = $this->CloseAllValves();
        }
        if (!$closeSucceeded) {
            $this->LogError(
                'LawnCoolingGlobalStop',
                'Safety abort: valves could not be closed reliably.',
                430
            );
            $reason = LawnCoolingContracts::STOP_REASON_GLOBAL_VALVE_CLOSE_FAILED;
            $stateReason .= ' Valve closing failed.';
        }
        $this->SetLawnCoolingRunState(LawnCoolingContracts::RUN_STATE_STOPPED, $stateReason);
        $this->ClearAllLawnCoolingSignals();
        $this->stopLawnCoolingRuntimeTick();
        $this->clearLawnCoolingRuntimeBuffers();
    }

    /**
     * Returns whether cooling currently owns an active runtime state.
     */
    protected function IsLawnCoolingRunActive(): bool
    {
        // Technically: resolve the optional frontend state variable defensively and verify the object before reading its value.
        // Functional behavior: modules or test doubles without a registered cooling state are treated as idle.
        // Domain rationale: cooling must never block normal irrigation merely because its optional status display has not been created yet.
        try {
            $stateVarID = (int) $this->GetIDForIdent(LawnCoolingContracts::RUN_STATE_IDENT);
        } catch (Throwable) {
            return false;
        }

        if ($stateVarID <= 0 || !IPS_ObjectExists($stateVarID)) {
            return false;
        }

        try {
            $state = (string) GetValue($stateVarID);
        } catch (Throwable) {
            return false;
        }

        return in_array($state, [
            LawnCoolingContracts::RUN_STATE_PRECHECK,
            LawnCoolingContracts::RUN_STATE_RUNNING,
        ], true);
    }

    /**
     * Schedules the lawn cooling daily timer based on CoolingStartTime and current module state.
     *
     * Technically: calculates the next daily run time from CoolingStartTime property
     * and sets the timer interval accordingly. Disables timer if configuration is invalid.
     * Functional behavior: the timer triggers once per day at the configured start time,
     * respecting the SystemType == "lawn" and CoolingEnable == true guards.
     * Domain rationale: regular, predictable lawn cooling triggers require a daily timer
     * that respects operational modes but does not block configuration changes.
     */
    protected function scheduleLawnCoolingDailyTimer(): void
    {
        // Technically: re-run the same readiness guard that ApplyChanges already enforces.
        // Functional behavior: the timer disables immediately when the instance is not ready.
        // Domain rationale: a stale or partially configured instance must not have an active timer.
        if ($this->isModuleConfigurationInvalid('scheduleLawnCoolingDailyTimer')) {
            $this->SetTimerInterval(LawnCoolingContracts::TIMER_IDENT_DAILY, 0);
            return;
        }

        if ($this->shouldDisableLawnCoolingScheduler('scheduleLawnCoolingDailyTimer', LawnCoolingContracts::TIMER_IDENT_DAILY)) {
            return;
        }

        // CoolingStartTime format is already validated by ConfigurationValidator::validateCoolingConfiguration() in ApplyChanges.
        // If isModuleConfigurationInvalid() returned false, CoolingStartTime is guaranteed to be valid.
        $startTime = $this->decodeJsonArray($this->ReadPropertyString('CoolingStartTime'));

        // Technically: calculate the next run timestamp and set the interval.
        // Functional behavior: timer fires at the configured time tomorrow if today's time has passed.
        // Domain rationale: daily triggers must be predictable and not race against system clock.
        $now = new DateTimeImmutable('now');
        $nextRun = $now->setTime((int) $startTime['hour'], (int) $startTime['minute'], (int) $startTime['second']);

        if ($nextRun <= $now) {
            $nextRun = $nextRun->modify('+1 day');
        }

        $secondsUntilRun = $nextRun->getTimestamp() - $now->getTimestamp();
        $intervalMs = max(1000, $secondsUntilRun * 1000);
        $this->SetTimerInterval(LawnCoolingContracts::TIMER_IDENT_DAILY, $intervalMs);

        $this->SendDebug(
            'scheduleLawnCoolingDailyTimer',
            'Next lawn-cooling run at ' . $nextRun->format('Y-m-d H:i:s') . ' (in ' . $secondsUntilRun . ' seconds).',
            0
        );
    }

    /**
     * Schedules the lawn cooling precheck timer with a 5-minute offset before CoolingStartTime.
     *
     * Technically: calculates the precheck time (CoolingStartTime - 5 minutes) and schedules accordingly.
     * Functional behavior: the timer triggers 5 minutes before the main cooling run to evaluate conditions early.
     * Domain rationale: pre-evaluation allows push notifications and early blocking decisions without race conditions.
     */
    protected function scheduleCoolingPrecheckTimer(): void
    {
        // Technically: re-run the same readiness guard that ApplyChanges already enforces.
        // Functional behavior: the timer disables immediately when the instance is not ready.
        // Domain rationale: a stale or partially configured instance must not have an active timer.
        if ($this->isModuleConfigurationInvalid('scheduleCoolingPrecheckTimer')) {
            $this->SetTimerInterval(LawnCoolingContracts::TIMER_IDENT_PRECHECK, 0);
            return;
        }

        if ($this->shouldDisableLawnCoolingScheduler('scheduleCoolingPrecheckTimer', LawnCoolingContracts::TIMER_IDENT_PRECHECK)) {
            return;
        }

        // CoolingStartTime format is already validated by ConfigurationValidator::validateCoolingConfiguration() in ApplyChanges.
        // If isModuleConfigurationInvalid() returned false, CoolingStartTime is guaranteed to be valid.
        $startTime = $this->decodeJsonArray($this->ReadPropertyString('CoolingStartTime'));

        // Technically: calculate the precheck time (5 minutes before start) and set the interval.
        // Functional behavior: timer fires 5 minutes before the main cooling start time.
        // Domain rationale: early evaluation window allows push notification and gate decisions before the actual run.
        $now = new DateTimeImmutable('now');
        $startTimeObj = $now->setTime((int) $startTime['hour'], (int) $startTime['minute'], (int) $startTime['second']);
        $precheckTime = $startTimeObj->modify('-' . LawnCoolingContracts::PRECHECK_OFFSET_MINUTES . ' minutes');

        // If precheck time has already passed today, schedule for tomorrow
        if ($precheckTime <= $now) {
            $precheckTime = $precheckTime->modify('+1 day');
        }

        $secondsUntilPrecheck = $precheckTime->getTimestamp() - $now->getTimestamp();
        $intervalMs = max(1000, $secondsUntilPrecheck * 1000);
        $this->SetTimerInterval(LawnCoolingContracts::TIMER_IDENT_PRECHECK, $intervalMs);

        $this->SendDebug(
            'scheduleCoolingPrecheckTimer',
            'Next lawn-cooling precheck at ' . $precheckTime->format('Y-m-d H:i:s') . ' (in ' . $secondsUntilPrecheck . ' seconds).',
            0
        );
    }

    /**
     * Evaluates whether lawn-cooling timers must be disabled due to system mode or cooling switch.
     *
     * Technically: checks SystemType/CoolingEnable and disables the given timer when incompatible.
     * Functional behavior: both daily and precheck schedulers share one consistent gate decision.
     * Domain rationale: lawn cooling must never run for non-lawn setups or when intentionally switched off.
     */
    protected function shouldDisableLawnCoolingScheduler(string $context, string $timerIdent): bool
    {
        if ($this->ReadPropertyString('SystemType') !== 'lawn' || !$this->ReadPropertyBoolean('CoolingEnable')) {
            $this->SetTimerInterval($timerIdent, 0);
            $this->SendDebug($context, 'Lawn-cooling timer disabled: SystemType or CoolingEnable is incompatible.', 0);
            return true;
        }

        return false;
    }

    /**
     * Applies the user skip action before or during a lawn-cooling run.
     *
     * Technical behavior: stores the requested skip in the volatile buffer and upgrades a late request to the existing stop signal.
     * Functional behavior: a user can cancel the pending daily run, while a request after valve actuation terminates the active run safely.
     * Domain rationale: cancellation is harmless before water delivery starts; after actuation, the system must close valves instead of silently changing the plan.
     */
    protected function HandleLawnCoolingSkipAction(mixed $value): void
    {
        $skipRequested = is_bool($value) ? $value : ((int) $value !== 0);
        if (!$skipRequested) {
            $this->ClearLawnCoolingSkipCurrentRun();
            $this->SetValue(LawnCoolingContracts::SKIP_VARIABLE_IDENT, false);
            $this->syncLawnCoolingSkipActionAvailability();
            $this->SendDebug('RequestAction', 'LawnCoolingSkipCurrentRun reset.', 0);
            return;
        }

        if ($this->IsLawnCoolingFirstValveOpened()) {
            $this->SetLawnCoolingStopRequested(true);
            $this->SetValue(LawnCoolingContracts::SKIP_VARIABLE_IDENT, true);
            $this->syncLawnCoolingSkipActionAvailability();
            $this->SendDebug('RequestAction', 'LawnCoolingSkipCurrentRun promoted to hard stop after valve opening.', 0);
            return;
        }

        $this->SetLawnCoolingSkipCurrentRun(true);
        $this->SetValue(LawnCoolingContracts::SKIP_VARIABLE_IDENT, true);
        $this->syncLawnCoolingSkipActionAvailability();
        $this->SendDebug('RequestAction', 'LawnCoolingSkipCurrentRun set for the current run.', 0);
    }

    /**
     * Stores the lawn cooling stop request flag in the module buffer.
     *
     * Technically: writes a '1' or empty string to the buffer key.
     * Functional behavior: SetBuffer stores volatile runtime state without disk I/O overhead.
     * Domain rationale: stop signals must be checked frequently during zone loops and need fast access.
     */
    protected function SetLawnCoolingStopRequested(bool $requested): void
    {
        $this->SetBuffer(LawnCoolingContracts::STOP_SIGNAL_BUFFER_KEY, $requested ? '1' : '');
    }

    /**
     * Returns true when a lawn cooling stop request is pending.
     */
    protected function IsLawnCoolingStopRequested(): bool
    {
        return $this->GetBuffer(LawnCoolingContracts::STOP_SIGNAL_BUFFER_KEY) === '1';
    }

    /**
     * Clears the lawn cooling stop request flag in the module buffer.
     */
    protected function ClearLawnCoolingStopRequested(): void
    {
        $this->SetLawnCoolingStopRequested(false);
    }

    /**
     * Stores the lawn cooling skip-today flag in the module buffer.
     *
     * Technically: writes a '1' or empty string to the buffer key.
     * Functional behavior: SetBuffer stores volatile runtime state; skip signal is valid only before first valve opens.
     * Domain rationale: skip decision must be communicated early to prevent zone startup.
     */
    protected function SetLawnCoolingSkipCurrentRun(bool $skip): void
    {
        $this->SetBuffer(LawnCoolingContracts::SKIP_SIGNAL_BUFFER_KEY, $skip ? '1' : '');
    }

    /**
     * Returns true when lawn cooling skip-today is requested.
     */
    protected function IsLawnCoolingSkipCurrentRun(): bool
    {
        return $this->GetBuffer(LawnCoolingContracts::SKIP_SIGNAL_BUFFER_KEY) === '1';
    }

    /**
     * Clears the lawn cooling skip-today flag in the buffer and frontend variable.
     *
     * Technical behavior: removes the volatile signal and resets the registered boolean variable when it exists.
     * Functional behavior: consumed skips and terminal stops are no longer shown as an active user request.
     * Domain rationale: a daily skip is a one-shot command; retaining true in the frontend would suggest that a future run is still cancelled.
     */
    protected function ClearLawnCoolingSkipCurrentRun(): void
    {
        $this->SetLawnCoolingSkipCurrentRun(false);
        if ($this->GetExistingFrontendVariableID(LawnCoolingContracts::SKIP_VARIABLE_IDENT) > 0) {
            $this->SetValue(LawnCoolingContracts::SKIP_VARIABLE_IDENT, false);
        }
    }

    /**
     * Clears all lawn cooling runtime signals (stop and skip).
     *
     * Technically: resets both buffer keys to empty strings.
     * Functional behavior: called at run start and run end to ensure clean state transitions.
     * Domain rationale: runtime signal hygiene prevents state pollution between runs.
     */
    protected function ClearAllLawnCoolingSignals(): void
    {
        $this->ClearLawnCoolingStopRequested();
        $this->ClearLawnCoolingSkipCurrentRun();
        $this->ClearLawnCoolingFirstValveOpened();
        $this->SetLawnCoolingPrecheckActive(false);
        $this->syncLawnCoolingSkipActionAvailability();
    }

    /**
     * Stores the lawn cooling first-valve-opened flag in the module buffer.
     *
     * Technically: a boolean flag is persisted as a single buffer value to distinguish
     * user skip semantics before any valve has opened from hard-stop semantics afterwards.
     * Functional behavior: once the first valve is opened, a skip request is no longer
     * accepted as a harmless cancellation and must be treated as a forced stop.
     * Domain rationale: the system must never quietly skip a cooling run after the first
     * actual valve actuation, because the irrigation sequence has already begun and cannot
     * be reversed without opening other valves later in the cycle.
     */
    protected function SetLawnCoolingFirstValveOpened(bool $opened): void
    {
        $this->SetBuffer(LawnCoolingContracts::FIRST_VALVE_OPENED_BUFFER_KEY, $opened ? '1' : '');
        $this->syncLawnCoolingSkipActionAvailability();
    }

    /**
     * Synchronizes whether the frontend may request a lawn-cooling skip.
     *
     * Technical behavior: enables the boolean action only while the cooling state is PRECHECK or RUNNING.
     * Functional behavior: the skip control is unavailable while idle and remains available during active cooling so it can request a hard stop.
     * Domain rationale: skipping is meaningful only for today's pending or active cooling run; after termination there is no run left to cancel.
     */
    protected function syncLawnCoolingSkipActionAvailability(): void
    {
        try {
            $skipVariableID = $this->GetExistingFrontendVariableID(LawnCoolingContracts::SKIP_VARIABLE_IDENT);
            if ($skipVariableID <= 0) {
                return;
            }

            $enabled = $this->ReadPropertyString('SystemType') === 'lawn'
                && $this->ReadPropertyBoolean('CoolingEnable')
                && ($this->IsLawnCoolingRunActive() || $this->IsLawnCoolingPrecheckActive());
            if ($enabled) {
                $this->EnableAction(LawnCoolingContracts::SKIP_VARIABLE_IDENT);
                return;
            }

            $this->DisableAction(LawnCoolingContracts::SKIP_VARIABLE_IDENT);
        } catch (Throwable) {
            // Frontend synchronization is optional for lightweight test doubles and incomplete instances.
        }
    }

    /**
     * Stores whether the advisory cooling precheck is currently executing.
     */
    protected function SetLawnCoolingPrecheckActive(bool $active): void
    {
        $this->SetBuffer(LawnCoolingContracts::PRECHECK_ACTIVE_BUFFER_KEY, $active ? '1' : '');
    }

    /**
     * Returns true while the advisory cooling precheck is evaluating its gates.
     */
    protected function IsLawnCoolingPrecheckActive(): bool
    {
        return $this->GetBuffer(LawnCoolingContracts::PRECHECK_ACTIVE_BUFFER_KEY) === '1';
    }

    /**
     * Resolves an existing instance-owned frontend variable without triggering an IP-Symcon missing-ident warning.
     *
     * Technical behavior: queries the module ident table via GetIDForIdent and suppresses missing-ident warnings/exceptions.
     * Functional behavior: safely returns the object ID when registered or 0 when the variable does not exist yet.
     * Domain rationale: enables defensive lookups during early startup and configuration changes before variables are registered.
     */
    protected function GetExistingFrontendVariableID(string $ident): int
    {
        try {
            $id = @$this->GetIDForIdent($ident);
            return $id > 0 ? $id : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Returns true when the lawn cooling loop has already opened at least one valve.
     */
    protected function IsLawnCoolingFirstValveOpened(): bool
    {
        return $this->GetBuffer(LawnCoolingContracts::FIRST_VALVE_OPENED_BUFFER_KEY) === '1';
    }

    /**
     * Clears the lawn cooling first-valve-opened flag.
     */
    protected function ClearLawnCoolingFirstValveOpened(): void
    {
        $this->SetLawnCoolingFirstValveOpened(false);
    }

    /**
     * Evaluates the lawn cooling runtime checkpoint for stop and skip signals.
     *
     * Technically: stop requests always win and skip requests are only valid before the
     * first valve opening. After the first valve is opened, skip must be upgraded to a
     * hard stop to avoid unsafe partial execution.
     * Functional behavior: returns a structured allow/stop result that the runtime can
     * consume before a new zone is started or when the loop is evaluating a late signal.
     * Domain rationale: skip decisions are a user convenience before any active work; once
     * the system has started opening valves, the only safe continuation is a hard stop.
     *
     * @return array{allowed: bool, hardStop: bool, reason: string, debugMessage: string}
     */
    protected function evaluateLawnCoolingSignalCheckpoint(string $context, bool $firstValveOpened = false): array
    {
        if ($this->IsLawnCoolingStopRequested()) {
            $this->SendDebug($context, 'Lawn-cooling stop signal detected: hard stop is active.', 0);
            return [
                'allowed'      => false,
                'hardStop'     => true,
                'reason'       => 'hard_stop',
                'debugMessage' => 'Hard stop detected: LawnCoolingStopRequested is active.',
            ];
        }

        if ($this->IsLawnCoolingSkipCurrentRun()) {
            if (LawnCoolingContracts::isSkipSignalAllowedBeforeFirstValve($firstValveOpened)) {
                $this->SendDebug($context, 'Lawn-cooling skip signal accepted: still before the first valve opening.', 0);
                return [
                    'allowed'      => false,
                    'hardStop'     => false,
                    'reason'       => 'skip_today',
                    'debugMessage' => 'SkipCurrentRun accepted: run will be skipped before the first valve opening.',
                ];
            }

            $this->SetLawnCoolingStopRequested(true);
            $this->SendDebug($context, 'Lawn-cooling skip signal treated as a hard stop after the first valve opening.', 0);
            return [
                'allowed'      => false,
                'hardStop'     => true,
                'reason'       => 'hard_stop_after_first_valve',
                'debugMessage' => 'SkipCurrentRun treated as a hard stop after the first valve opening.',
            ];
        }

        return [
            'allowed'      => true,
            'hardStop'     => false,
            'reason'       => 'none',
            'debugMessage' => 'No stop or skip signals are active for the current lawn-cooling checkpoint.',
        ];
    }

    // ============================================================
    // STATE, ERROR & STATUS MANAGEMENT
    // ============================================================

    /**
     * Initializes run-state storage with a valid default while preserving existing terminal state.
     */
    protected function InitializeAutoIrrigationRunState(): void
    {
        $stateVarID = (int) $this->GetIDForIdent(AutoIrrigationContracts::RUN_STATE_IDENT);
        if ($stateVarID <= 0) {
            $this->SendDebug('InitializeAutoIrrigationRunState', 'RunState variable could not be found.', 0);
            return;
        }

        $currentState = (string) GetValue($stateVarID);
        if (AutoIrrigationContracts::isValidRunState($currentState)) {
            return;
        }

        $this->SetAutoIrrigationRunState(
            AutoIrrigationContracts::RUN_STATE_FINISHED,
            'Initial state set because no valid run state was available.'
        );
    }

    /**
     * Sets the orchestrator run state only when the target state is valid and changed.
     */
    protected function SetAutoIrrigationRunState(string $state, string $reason = ''): void
    {
        if (!AutoIrrigationContracts::isValidRunState($state)) {
            $this->SendDebug('SetAutoIrrigationRunState', 'Invalid run state ignored: ' . $state, 0);
            return;
        }

        $stateVarID = (int) $this->GetIDForIdent(AutoIrrigationContracts::RUN_STATE_IDENT);
        if ($stateVarID <= 0) {
            $this->SendDebug('SetAutoIrrigationRunState', 'RunState-Variable ist nicht registriert.', 0);
            return;
        }

        $previousState = (string) GetValue($stateVarID);
        if ($previousState === $state) {
            return;
        }

        $this->SetValue(AutoIrrigationContracts::RUN_STATE_IDENT, $state);

        $message = 'State change: ' . $previousState . ' -> ' . $state;
        if ($reason !== '') {
            $message .= ' | reason: ' . $reason;
        }

        $this->SendDebug('AutoIrrigationRunState', $message, 0);
    }

    /**
     * Initializes lawn cooling run-state storage with a valid terminal default.
     */
    protected function InitializeLawnCoolingRunState(): void
    {
        $stateVarID = (int) $this->GetIDForIdent(LawnCoolingContracts::RUN_STATE_IDENT);
        if ($stateVarID <= 0) {
            $this->SendDebug('InitializeLawnCoolingRunState', 'Lawn-cooling status variable could not be found.', 0);
            return;
        }

        $currentState = (string) GetValue($stateVarID);
        if (in_array($currentState, LawnCoolingContracts::allowedRunStates(), true)) {
            return;
        }

        $this->SetLawnCoolingRunState(
            LawnCoolingContracts::RUN_STATE_FINISHED,
            'Initial state set because no valid run state was available.'
        );
    }

    /**
     * Sets the lawn cooling run state only when the target state is valid and changed.
     */
    protected function SetLawnCoolingRunState(string $state, string $reason = ''): void
    {
        if (!in_array($state, LawnCoolingContracts::allowedRunStates(), true)) {
            $this->SendDebug('SetLawnCoolingRunState', 'Invalid run state ignored: ' . $state, 0);
            return;
        }

        $stateVarID = (int) $this->GetIDForIdent(LawnCoolingContracts::RUN_STATE_IDENT);
        if ($stateVarID <= 0) {
            $this->SendDebug('SetLawnCoolingRunState', 'Lawn-cooling status variable is not registered.', 0);
            return;
        }

        $previousState = (string) GetValue($stateVarID);
        if ($previousState === $state) {
            return;
        }

        $this->SetValue(LawnCoolingContracts::RUN_STATE_IDENT, $state);
        $this->syncLawnCoolingSkipActionAvailability();

        $message = 'State change: ' . $previousState . ' -> ' . $state;
        if ($reason !== '') {
            $message .= ' | reason: ' . $reason;
        }

        $this->SendDebug('LawnCoolingRunState', $message, 0);
    }

    /**
     * Sets the module to the error state, logs the error, and updates the status variable.
     * Context normalization is performed centrally in ModuleErrorContexts::normalize().
     */
    protected function LogError(string $context, string $message, int $code): void
    {
        $localizedMessage = $this->getLocalizedErrorMessage($code, $message);
        $formattedMessage = sprintf(
            $this->Translate('error.log.entry'),
            date('H:i:s'),
            $context,
            $localizedMessage
        );

        // Technically: mirror the error into the instance debug channel.
        // Functional behavior: every logged error stays visible in the Symcon debug window.
        // Domain rationale: operators need traceable diagnostics for irrigation faults, API issues, and configuration errors.
        $this->SendDebug($context, $message, 0);

        // Technically: persist the same error in the Symcon main log.
        // Functional behavior: the message survives beyond the instance debug view.
        // Domain rationale: persistent logging is required for post-run analysis and operational auditing.
        IPS_LogMessage('GardenIrrigationControl.' . $this->InstanceID, $formattedMessage);

        // Record errors in memory
        $errors = $this->decodeJsonArray($this->ReadAttributeString('ActiveErrors'));
        $errorContextKey = $this->normalizeErrorContextKey($context);
        $errors[$errorContextKey] = $code; // In context X, error code Y occurred
        $this->WriteAttributeString('ActiveErrors', $this->encodeJsonArray($errors));

        // Set instance status to "error"
        $this->SetStatus($code);
    }

    protected function getLocalizedErrorMessage(int $code, string $fallback): string
    {
        $translationKey = match ($code) {
            104     => 'status.waiting_for_configuration',
            200     => 'status.configuration.invalid_system_type',
            201     => 'status.configuration.invalid_location',
            202     => 'status.configuration.invalid_temperature_variable',
            203     => 'status.configuration.invalid_humidity_variable',
            204     => 'status.configuration.invalid_pressure_variable',
            205     => 'status.configuration.invalid_wind_variable',
            206     => 'status.configuration.invalid_wind_height',
            207     => 'status.configuration.invalid_max_wind_speed',
            208     => 'status.configuration.invalid_rain_variable',
            209     => 'status.configuration.invalid_daily_rain_archive',
            210     => 'status.configuration.invalid_baseline_eto',
            211     => 'status.configuration.invalid_baseline_months',
            220     => 'status.configuration.invalid_global_soil_moisture_variable',
            221     => 'status.configuration.invalid_soil_moisture_threshold',
            222     => 'status.configuration.invalid_soil_moisture_mode',
            230     => 'status.configuration.invalid_irrigation_interval',
            231     => 'status.configuration.invalid_irrigation_start_time',
            232     => 'status.configuration.invalid_irrigation_max_runtime',
            233     => 'status.configuration.invalid_irrigation_min_runtime',
            234     => 'status.configuration.invalid_zone_max_runtime',
            235     => 'status.configuration.zone_max_runtime_below_minimum',
            236     => 'status.configuration.irrigation_max_runtime_too_short',
            240     => 'status.configuration.invalid_lawn_cooling_start_time',
            241     => 'status.configuration.invalid_lawn_cooling_runtime',
            242     => 'status.configuration.invalid_lawn_cooling_temperature',
            243     => 'status.configuration.overlapping_lawn_cooling_and_irrigation',
            244     => 'status.configuration.invalid_lawn_cooling_push_instance',
            245     => 'status.configuration.invalid_lawn_cooling_visualization_instance',
            250     => 'status.zone.none_configured',
            251     => 'status.zone.name_too_short',
            252     => 'status.zone.invalid_orientation',
            253     => 'status.zone.invalid_valve',
            254     => 'status.zone.invalid_soil_moisture_sensor',
            255     => 'status.zone.global_soil_moisture_missing',
            256     => 'status.zone.invalid_sequence',
            257     => 'status.zone.invalid_precipitation_rate',
            258     => 'status.zone.invalid_slope',
            259     => 'status.zone.duplicate_sequence',
            260     => 'status.zone.duplicate_valve',
            261     => 'status.zone.valve_not_boolean',
            300     => 'status.runtime.auto_and_maintenance_conflict',
            301     => 'status.runtime.archive_handler_missing',
            400     => 'status.runtime.valve_open_failed',
            401     => 'status.runtime.valve_not_closed',
            420     => 'status.history.eto_access_failed',
            421     => 'status.history.eto_incomplete',
            430     => 'status.calculation.eto_failed',
            440     => 'status.calculation.valve_runtime_failed',
            450     => 'status.calculation.rain_history_failed',
            460     => 'status.calculation.zone_archive_failed',
            461     => 'status.calculation.water_storage_write_failed',
            470     => 'status.calculation.rain_forecast_failed',
            480     => 'status.push.invalid_instance_type',
            481     => 'status.push.title_too_long',
            482     => 'status.push.text_too_long',
            483     => 'status.push.invalid_type',
            484     => 'status.push.target_missing',
            485     => 'status.push.send_failed',
            default => null,
        };

        if ($translationKey === null) {
            return $fallback;
        }

        $localizedMessage = $this->Translate($translationKey);

        return $localizedMessage !== $translationKey ? $localizedMessage : $fallback;
    }

    protected function translateValidationResult(ValidationResult $result): string
    {
        return $this->translateMessage($result->translationKey, $result->parameters);
    }

    /**
     * @param array<int, scalar|null> $parameters
     */
    protected function translateMessage(string $translationKey, array $parameters = []): string
    {
        $translated = $this->Translate($translationKey);

        return $parameters === []
            ? $translated
            : sprintf($translated, ...$parameters);
    }

    protected function translateException(LocalizedException|LocalizedInvalidArgumentException|LocalizedRuntimeException $exception): string
    {
        return $this->translateMessage($exception->translationKey, $exception->parameters);
    }

    /**
     * Convenience function to directly pass a caught exception.
     */
    protected function LogException(string $context, Exception $e, int $code): void
    {
        $this->LogError($context, $e->getMessage(), $code);
    }

    /**
     * Resets the error status of a single error.
     */
    protected function ClearContextError(string $context): void
    {
        $errors = $this->decodeJsonArray($this->ReadAttributeString('ActiveErrors'));
        $errorContextKey = $this->normalizeErrorContextKey($context);

        if (isset($errors[$errorContextKey])) {
            unset($errors[$errorContextKey]);
            $this->WriteAttributeString('ActiveErrors', $this->encodeJsonArray($errors));

            // Technically: restore the active instance status only after the final persisted error context was removed.
            // Functional behavior: a successful retry clears stale error indicators immediately without requiring ApplyChanges.
            // Domain rationale: operators must see the recovered state directly after a resolved weather, archive, valve, or notification fault; otherwise a stale alarm can conceal the current operational condition.
            if ($errors === [] && $this->GetStatus() >= self::MODULE_STATUS_ERROR_MIN) {
                $this->SetStatus(102);
                $this->SendDebug('ClearContextError', 'All active errors cleared. Instance status set to ready (102).', 0);
            }
        }
    }

    /**
     * Clears error status completely.
     */
    protected function ClearError(): void
    {
        // Technically: remove all persisted active-error keys and restore the nominal status.
        // Functional behavior: the instance leaves the error state only after the full error set has been cleared.
        // Domain rationale: a clean restart state must not retain stale faults that could suppress a new irrigation cycle.
        $this->WriteAttributeString('ActiveErrors', '[]');
        $this->SetStatus(102); // 102 = Instance is active and running without errors (Green)
    }

    /**
     * Normalizes technical sub-contexts to stable ActiveErrors keys.
     */
    protected function normalizeErrorContextKey(string $context): string
    {
        return ModuleErrorContexts::normalize($context);
    }

    protected function getDebugLogger(): DebugLoggerInterface
    {
        return $this->debugLogger ??= new ModuleLogger([$this, 'debugLogger']);
    }

    protected function getValveControl(): ValveControl
    {
        return $this->valveControl ??= new ValveControl($this->getDebugLogger());
    }

    protected function getPauseSnapshotBuilder(): AutoIrrigationPauseSnapshotBuilder
    {
        return $this->pauseSnapshotBuilder ??= new AutoIrrigationPauseSnapshotBuilder();
    }

    protected function getSoilRuntimeSnapshotBuilder(): SoilRuntimeSnapshotBuilder
    {
        return $this->soilRuntimeSnapshotBuilder ??= new SoilRuntimeSnapshotBuilder();
    }

    protected function getZoneStartPipelineService(): ZoneStartPipelineService
    {
        return $this->zoneStartPipelineService ??= new ZoneStartPipelineService($this->getDebugLogger());
    }

    protected function getLawnCoolingZoneStartPlanner(): LawnCoolingZoneStartPlanner
    {
        return $this->lawnCoolingZoneStartPlanner ??= new LawnCoolingZoneStartPlanner();
    }

    protected function getLawnCoolingZoneCandidateService(): LawnCoolingZoneCandidateService
    {
        return $this->lawnCoolingZoneCandidateService ??= new LawnCoolingZoneCandidateService($this->getDebugLogger());
    }

    protected function getLawnCoolingRuntimeOrchestrator(): LawnCoolingRuntimeOrchestrator
    {
        return $this->lawnCoolingRuntimeOrchestrator ??= new LawnCoolingRuntimeOrchestrator();
    }

    /**
     * Validates that a variable exists and is archived in the archive handler.
     * Throws exception if validation fails (no error suppression via @).
     *
     * @param int $varID The variable ID to validate
     * @param int $archiveID The archive handler ID
     * @param string $varName Human-readable name for error messages
     * @return void
     * @throws Exception If variable doesn't exist or archiving is not enabled
     */
    protected function validateArchiveVariable(int $varID, int $archiveID, string $varName, bool $required = true): void
    {
        if ($varID === 0) {
            if ($required) {
                throw new LocalizedException('error.module.variable_missing', [$varName]);
            }

            return;
        }

        if (!IPS_ObjectExists($varID)) {
            throw new LocalizedException('error.module.variable_missing', [$varName]);
        }

        try {
            $isLogged = AC_GetLoggingStatus($archiveID, $varID);
            if (!$isLogged) {
                throw new LocalizedException('error.module.archive_logging_disabled', [$varName]);
            }
        } catch (Exception $e) {
            throw new LocalizedException('error.module.archive_error', [$varName, $e->getMessage()]);
        }
    }

    /**
     * Retrieves the instance ID of the IP-Symcon archive handler.
     *
     * @return int The instance ID of the archive handler.
     * @throws Exception If the archive handler is not found in the system.
     */
    protected function GetArchiveInstanceID(): int
    {
        $archiveInstances = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveInstances) === 0) {
            throw new LocalizedException('error.module.archive_handler_missing');
        }
        return (int) $archiveInstances[0];
    }

    /**
     * Checks whether the module is currently blocked by configuration errors.
     *
     * Public entry points use this guard to avoid duplicating the same runtime
     * readiness check in every method.
     *
     * @param string $context The calling method name for debug output.
     * @return bool True if the module is not ready for execution.
     */
    protected function isModuleConfigurationInvalid(string $context): bool
    {
        $status = $this->GetStatus();
        if ($status === self::MODULE_STATUS_NOT_CONFIGURED || ($status >= self::MODULE_STATUS_ERROR_MIN && $status <= self::MODULE_STATUS_ERROR_MAX)) {
            $this->SendDebug($context, 'Aborted: module is not configured correctly (status: ' . $status . ')', 0);
            return true;
        }
        return false;
    }

    /**
     * Safely decode JSON string to array (strict_types compatible).
     * Per AGENTS.md §7: Helper performs no error handling - caller decides.
     * Returns empty array silently on any failure (JSON parse error or non-array result).
     *
     * @param string $json JSON string to decode
     * @return array<array-key, mixed> Decoded array, or empty array [] on any failure
     */
    protected function decodeJsonArray(string $json): array
    {
        if (empty($json)) {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * Technically: decode ZonesTree once per request and cache the decoded array in memory.
     * Functional behavior: repeated lookups return the same decoded zone configuration without repeated JSON parsing.
     * Domain rationale: zone configuration is stable for a running request, so repeated decoding inside runtime ticks wastes CPU without changing irrigation decisions.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getZonesTree(): array
    {
        /** @var array<int, array<string, mixed>> $zonesTree */
        $zonesTree = $this->zonesTreeCache ??= $this->decodeJsonArray($this->ReadPropertyString('ZonesTree'));

        return $zonesTree;
    }

    /**
     * Technical rule: serialize a validated PHP array to JSON with a safe fallback on encoding failure.
     * Functional behavior: callers always receive a string, so attribute and buffer writes remain deterministic.
     * Domain rationale: persistent irrigation state must not break because a single runtime payload cannot be encoded; a stable fallback avoids losing the controller state.
     *
     * @param array<array-key, mixed> $data
     */
    protected function encodeJsonArray(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '{}';
        }
    }

    /**
     * Builds a sorted list of recent day strings ending with yesterday.
     *
     * The returned list is ascending by date and is used as the target
     * processing window for zone water balance history updates.
     *
     * @param int $days Number of days to include.
     * @return array<int, string> Date list in Y-m-d format.
     */
    protected function buildRecentWaterBalanceDates(int $days): array
    {
        if ($days < 1) {
            return [];
        }

        $yesterday = (new DateTimeImmutable('today'))->modify('-1 day');
        $dates = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $dates[] = $yesterday->modify('-' . $offset . ' day')->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * Schedules the daily timer to the next 00:30 execution.
     */
    protected function scheduleWaterBalanceHistoryTimer(): void
    {
        $now = new DateTimeImmutable('now');
        $nextRun = $now->setTime(0, 30, 0);

        if ($nextRun <= $now) {
            $nextRun = $nextRun->modify('+1 day');
        }

        $secondsUntilRun = $nextRun->getTimestamp() - $now->getTimestamp();
        $intervalMs = max(1000, $secondsUntilRun * 1000);
        $this->SetTimerInterval('UpdateWaterBalanceHistoryTimer', $intervalMs);

        $this->SendDebug(
            'scheduleWaterBalanceHistoryTimer',
            'Next run at ' . $nextRun->format('Y-m-d H:i:s') . ' (in ' . $secondsUntilRun . ' seconds).',
            0
        );
    }

    protected function ReadOptionalFloatValue(int $varId): ?float
    {
        if ($varId <= 0 || !IPS_ObjectExists($varId)) {
            return null;
        }

        try {
            return (float) GetValue($varId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $zone
     */
    protected function extractZoneValveId(array $zone): int
    {
        return isset($zone['ValveVarID']) && is_numeric($zone['ValveVarID']) ? (int) $zone['ValveVarID'] : 0;
    }

    protected function normalizeOptionalObjectId(int $objectId): int
    {
        // Technically: convert the SelectVariable/SelectInstance clear-placeholder ID 1 to the internal unset value 0.
        // Functional behavior: optional object properties consistently use 0 when no object is selected, while all other IDs remain available for validation.
        // Domain rationale: an optional sensor or notification target may be removed during maintenance; treating the UI placeholder as a real object would create a false configuration reference and hide the intended unset state.
        // Risk without this boundary: ApplyChanges would report a misleading missing-object error for a deliberately cleared optional field, or runtime logic could evaluate the placeholder as a configured object.
        return $objectId === 1 ? 0 : $objectId;
    }

    /**
     * @param array<string, mixed> $zone
     */
    protected function extractZoneSoilMoistureVarId(array $zone): int
    {
        if (!isset($zone['SoilMoistureVarID']) || !is_numeric($zone['SoilMoistureVarID'])) {
            return 0;
        }

        return $this->normalizeOptionalObjectId((int) $zone['SoilMoistureVarID']);
    }

    /**
     * @param array<string, mixed> $zone
     */
    protected function extractZoneSprinklerPrecipitationRate(array $zone): float
    {
        return isset($zone['sprinklerPrecipitationRate']) && is_numeric($zone['sprinklerPrecipitationRate']) ? (float) $zone['sprinklerPrecipitationRate'] : 0.0;
    }

    /**
     * @param array<string, mixed> $zone
     */
    protected function extractZoneUseGlobalSoilMoisture(array $zone): bool
    {
        return isset($zone['UseGlobalSoilMoisture']) && (bool) $zone['UseGlobalSoilMoisture'];
    }

    /**
     * @param array<string, mixed> $zone
     */
    protected function BuildRuntimeZoneLabel(array $zone): string
    {
        $name = isset($zone['Name']) && is_string($zone['Name']) && trim($zone['Name']) !== ''
            ? trim($zone['Name'])
            : 'unnamed';

        $sequence = isset($zone['Sequence']) && is_numeric($zone['Sequence'])
            ? (int) $zone['Sequence']
            : -1;

        return sprintf('%s (Seq %d)', $name, $sequence);
    }

    private function dispatchInternalTimerCallback(string $ident): bool
    {
        // Handle internal async timer callbacks without generic debug spam.
        // ValidateValvesAsync is called by SetTimerInterval and should not spam the debug log.
        if ($ident === 'ValidateValvesAsync') {
            $this->ValidateValvesAsync();
            return true;
        }

        if ($ident === 'ValveRuntimeWatchdog') {
            $this->HandleValveRuntimeWatchdog();
            return true;
        }

        if ($ident === 'UpdateWaterBalanceHistoryTimer') {
            $this->RefreshWaterBalanceHistory();
            return true;
        }

        if ($ident === AutoIrrigationContracts::TIMER_IDENT_DAILY) {
            $this->HandleAutoIrrigationDaily();
            return true;
        }

        if ($ident === AutoIrrigationContracts::TIMER_IDENT_RUNTIME) {
            $this->HandleAutoIrrigationRuntimeTick();
            return true;
        }

        if ($ident === LawnCoolingContracts::TIMER_IDENT_DAILY) {
            $this->HandleLawnCoolingDaily();
            return true;
        }

        if ($ident === LawnCoolingContracts::TIMER_IDENT_PRECHECK || $ident === 'CoolingPrecheckTimer') {
            $this->HandleCoolingPrecheckTimer();
            return true;
        }

        if ($ident === LawnCoolingContracts::TIMER_IDENT_RUNTIME) {
            $this->HandleLawnCoolingRuntimeTick();
            return true;
        }

        return false;
    }

    private function dispatchUserAction(string $ident, mixed $value): void
    {
        if ($ident === LawnCoolingContracts::SKIP_VARIABLE_IDENT) {
            $this->HandleLawnCoolingSkipAction($value);
            return;
        }

        // ==========================================
        // Switch to automatic mode in the Visu
        // ==========================================
        if ($ident === 'AutoEnable') {
            if ($value == true) {
                // Technically: store the new automatic-mode state and clear the opposing maintenance flag.
                // Functional behavior: enabling automatic mode immediately disables manual maintenance control in the UI.
                // Domain rationale: both modes must never compete for valve control because that would create conflicting operator intent.
                IPS_SetProperty($this->InstanceID, 'SystemAutoEnable', true);
                IPS_SetProperty($this->InstanceID, 'SystemMaintenanceEnable', false);
            } else {
                // Technically: persist the disabled automatic-mode state.
                // Functional behavior: the instance leaves scheduled irrigation inactive.
                // Domain rationale: explicit operator deactivation must stop autonomous watering without altering unrelated configuration.
                IPS_SetProperty($this->InstanceID, 'SystemAutoEnable', false);
                $this->SetAutoIrrigationStopRequested(true);
            }

            // Apply Changes (launches ApplyChanges internally)
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        // ==========================================
        // Switch to maintenance mode in the Visu
        // ==========================================
        if ($ident === 'MaintenanceEnable') {
            if ($value == true) {
                // Technically: store the new maintenance-mode state and clear the opposing automatic flag.
                // Functional behavior: enabling maintenance mode immediately disables scheduled irrigation in the UI.
                // Domain rationale: service operations require a guaranteed non-automatic state so no valve can reopen unexpectedly.
                IPS_SetProperty($this->InstanceID, 'SystemMaintenanceEnable', true);
                IPS_SetProperty($this->InstanceID, 'SystemAutoEnable', false);
                $this->SetAutoIrrigationStopRequested(true);
            } else {
                // Technically: persist the disabled maintenance-mode state.
                // Functional behavior: the instance returns to a non-maintenance configuration.
                // Domain rationale: manual deactivation must restore normal control without silently modifying the watering plan.
                IPS_SetProperty($this->InstanceID, 'SystemMaintenanceEnable', false);
            }

            // Apply Changes (launches ApplyChanges internally)
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        // ==========================================
        // Click on the maintenance dropdown (manual valve control)
        // ==========================================
        if ($ident === 'ActiveValve') {
            // Only switch it on if maintenance mode is actually ACTIVE
            if (!$this->ReadPropertyBoolean('SystemMaintenanceEnable')) {
                $this->SendDebug('RequestAction', 'ActiveValve: aborted: manual valve control is blocked because maintenance mode is inactive.', 0);
                return;
            }

            // The user selects “All Closed” (value 0)
            if ($value === 0) {
                $this->SendDebug('RequestAction', 'ActiveValve: option "all closed" selected.', 0);

                // Check whether the closure was actually successful
                if (!$this->CloseAllValves()) {
                    echo $this->Translate('action.manual_valve.close_failed');
                }
                $this->SetValue($ident, 0);
                return;
            }

            // The user selects a specific valve (the value is the variable ID)
            if ($value > 0 && IPS_ObjectExists($value)) {
                if (!$this->OpenSingleValve($value)) {
                    echo $this->Translate('action.manual_valve.open_failed');
                }
                $this->SetValue($ident, $value);
                return;
            }
        }
    }

    /**
     * Executes the daily run body within the acquired semaphore.
     *
     * @param array{blocked: bool, reason: string, debugMessage: string} $hardStartBlocker
     */
    private function executeDailyRunBody(array $hardStartBlocker): void
    {
        // Technically: clear the stop buffer, reset the prepared-zone cache and move into PRECHECK.
        // Functional behavior: each daily run starts from a clean runtime state.
        // Domain rationale: a previous abort must not leak into a new day, otherwise a fresh run could stop immediately or reuse stale zones.
        $this->ClearAutoIrrigationStopRequested();
        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_PREPARED_ZONES, '');
        $this->SetAutoIrrigationRunState(AutoIrrigationContracts::RUN_STATE_PRECHECK, 'Daily run started.');

        $planner = new AutoIrrigationZoneStartPlanner();
        $precheck = $planner->evaluateStartPrecheck(
            $this->ReadPropertyBoolean('SystemAutoEnable'),
            $this->ReadPropertyBoolean('SystemMaintenanceEnable'),
            true,
            $hardStartBlocker['blocked'],
            $hardStartBlocker['reason'],
            $hardStartBlocker['debugMessage']
        );

        $this->SendDebug('AutoIrrigationDaily', 'AutoIrrigationDaily started: stop signal reset, run state set to PRECHECK, and daily run prepared for orchestration.', 0);

        if (!$precheck['allowed']) {
            // Technically: the planner returns a terminal state and explanation for hard start blockers.
            // Functional behavior: the run ends deterministically without entering the zone loop.
            // Domain rationale: blocked starts must fail closed so no valve can open when the system is not in a safe state.
            $this->SendDebug('AutoIrrigationDaily', $precheck['debugMessage'], 0);
            $this->SetAutoIrrigationRunState($precheck['terminalState'], $precheck['reason']);
            return;
        }

        // Technically: decode and normalize the configured zones once before any later loop logic uses them.
        // Functional behavior: only active zones with a valid Sequence are kept for the current run.
        // Domain rationale: inactive or unsorted zones must not influence watering order or create spurious irrigation starts.
        $zonesTree = $this->getZonesTree();
        $activeZones = $planner->prepareActiveZones($zonesTree);

        if ($activeZones === []) {
            // Technically: finish the run explicitly when no eligible zone remains.
            // Functional behavior: the timer cycle ends cleanly instead of leaving PRECHECK active.
            // Domain rationale: an empty active set is not an error; it simply means there is nothing to irrigate today.
            $this->SendDebug('AutoIrrigationDaily', 'Precheck succeeded, but no active zones were found. The run ends cleanly.', 0);
            $this->SetAutoIrrigationRunState(AutoIrrigationContracts::RUN_STATE_FINISHED, 'No active zones available.');
            return;
        }

        // Technically: persist the planned zone list in the module buffer for the next orchestrator step.
        // Functional behavior: the later phase can continue without recalculating the same zone filter and sort.
        // Domain rationale: the orchestrator needs a stable daily snapshot so later checks do not reorder zones mid-run.
        $this->SendDebug('AutoIrrigationDaily', sprintf('Precheck succeeded. %d active zone(s) loaded in start order.', count($activeZones)), 0);

        // Technically: evaluate the stop signal again immediately before the run would enter zone work.
        // Functional behavior: a late stop request prevents the controller from proceeding past the planning stage.
        // Domain rationale: a safety or user stop must win even after the entry-point has already prepared the day plan.
        $stopCheckpoint = $planner->evaluateStopSignalCheckpoint($this->IsAutoIrrigationStopRequested(), 'pre_zone_start');
        if (!$stopCheckpoint['allowed']) {
            $this->SendDebug('AutoIrrigationDaily', $stopCheckpoint['debugMessage'], 0);
            $this->GlobalStopAndEnd(null, 'stop_signal_pre_zone_start', 'Stop signal detected before zone start.');
            return;
        }

        // Technically: persist the prepared zone list into the module buffer as a serialized daily snapshot.
        // Functional behavior: later runtime slices can reuse the exact same ordered zone set without recalculating it.
        // Domain rationale: the daily irrigation plan must stay stable after precheck so that runtime decisions remain deterministic.
        $encodedActiveZones = json_encode($activeZones);
        if (is_string($encodedActiveZones)) {
            $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_PREPARED_ZONES, $encodedActiveZones);
        }

        // Technically: transition into RUNNING exactly when the orchestrator starts processing zone runtime checks.
        // Functional behavior: the frontend state now reflects that the daily run is actively evaluating runtime gates.
        // Domain rationale: operators need a truthful live state so they can distinguish planning from active irrigation decisions.
        $this->SetBuffer(AutoIrrigationContracts::BUFFER_KEY_CURRENT_ZONE_WATERED, '');
        $this->SetAutoIrrigationCurrentZoneIndex(0);
        $this->SetAutoIrrigationCurrentZoneElapsedWateringSeconds(0);
        $this->SetAutoIrrigationRunStartTimestamp(time());
        $this->SetAutoIrrigationRunState(AutoIrrigationContracts::RUN_STATE_RUNNING, 'Daily runtime phase started.');

        $this->ContinueAutoIrrigationRuntime();
    }

    private function buildRuntimeCallbacks(): AutoIrrigationRuntimeCallbacks
    {
        return new AutoIrrigationRuntimeCallbacks(
            debugLogger: function (string $context, string $message): void
            {
                $this->SendDebug($context, $message, 0);
            },
            isAutoEnabled: fn (): bool => $this->ReadPropertyBoolean('SystemAutoEnable'),
            isMaintenanceEnabled: fn (): bool => $this->ReadPropertyBoolean('SystemMaintenanceEnable'),
            isStopRequested: fn (): bool => $this->IsAutoIrrigationStopRequested(),
            setRunState: function (string $state, string $reason): void
            {
                $this->SetAutoIrrigationRunState($state, $reason);
            },
            getWindPauseSnapshot: fn (): array => $this->BuildWindPauseSnapshot(),
            getRainPauseSnapshot: fn (): array => $this->BuildRainPauseSnapshot(),
            runResumeReentryPipeline: fn (array $zone, string $resumeReason): array => $this->BuildResumeReentryPipelineResult($zone, $resumeReason),
            getSoilRuntimeSnapshot: fn (array $zone): array => $this->BuildSoilRuntimeSnapshot($zone),
            getTargetReachedSnapshot: fn (array $zone): array => $this->BuildTargetReachedSnapshot($zone),
            getDailyCutoffSnapshot: fn (): array => [
                'runStartTimestamp' => $this->GetAutoIrrigationRunStartTimestamp(),
                'maxRuntimeSeconds' => $this->BuildIrrigationMaxRuntimeSeconds(),
            ],
            handleZoneTerminalAction: function (array $zone, string $reason): void
            {
                // Technical behavior: route every non-global Resume-Reentry failure to the zone-local terminal action.
                // Functional behavior: the current zone is skipped while the daily orchestrator retains its run anchor and can evaluate subsequent zones.
                // Domain rationale: a resume-specific data or threshold failure affects only the paused zone; treating it globally would discard the daily budget and allow later zones to bypass remaining-runtime protection.
                if (
                    $reason === 'resume_reentry_zone_stop'
                    || str_starts_with($reason, 'Resume-Reentry:')
                    || $reason === 'soil_runtime_above_threshold_confirmed'
                    || $reason === 'target_reached'
                    || $reason === 'resume_reopen_failed'
                ) {
                    $this->ZoneStopAndNext($zone, $reason);

                    return;
                }

                $this->GlobalStopAndEnd($zone, $reason, 'Global stop from the runtime phase.');
            },
            handleZonePauseAction: function (array $zone, string $reason): void
            {
                $this->HandleZonePauseAction($zone, $reason);
            },
            handleZoneResumeAction: fn (array $zone, string $reason): array => $this->ResumeZoneAfterPause($zone, $reason)
        );
    }

    /**
     * Sends the optional advisory notification for a successful cooling precheck.
     *
     * Technical behavior: checks the opt-in flag and configured visualization instance before resolving and sending the message key.
     * Functional behavior: only an ALLOW precheck can produce a push; BLOCK and INVALID paths return before this method is reached.
     * Domain rationale: the notification is advisory and must never imply that a cooling run was authorized or already started.
     */
    private function sendLawnCoolingPrecheckPush(): void
    {
        $context = 'CoolingPrecheckTimer';
        if (!$this->ReadPropertyBoolean('CoolingPushEnable')) {
            $this->SendDebug($context, 'Precheck ALLOW: push is disabled.', 0);
            return;
        }

        $pushInstance = $this->normalizeOptionalObjectId($this->ReadPropertyInteger('CoolingPushInstance'));
        if ($pushInstance <= 0) {
            $this->SendDebug($context, 'Precheck ALLOW: push suppressed because no push instance is configured.', 0);
            return;
        }

        $messageKey = LawnCoolingContracts::PRECHECK_PUSH_MESSAGE_KEY;
        $title = $this->Translate($messageKey . '.title');
        $text = $this->Translate($messageKey . '.text');
        $targetId = (int) $this->GetIDForIdent(LawnCoolingContracts::SKIP_VARIABLE_IDENT);
        if ($this->SendPushNotification($pushInstance, $title, $text, 'Info', $targetId)) {
            $this->SendDebug($context, 'Precheck ALLOW: push sent successfully.', 0);
            return;
        }

        $this->SendDebug($context, 'Precheck ALLOW: push could not be sent.', 0);
    }

    // ============================================================
    // CONFIGURATION VALIDATION & FRONTEND SETUP
    // ============================================================

    /**
     * @return array{archiveHandlerID: int, systemType: string}|null
     */
    private function validateBaseAndArchive(ConfigurationValidator $validator): ?array
    {
        // ==========================================
        // If the required fields are still set to their default values, the module will wait for configuration
        // ==========================================
        $baseFieldsValidation = $validator->validateRequiredBaseFields(
            $this->ReadPropertyString('SystemType'),
            $this->ReadPropertyString('Location'),
            $this->ReadPropertyInteger('TemperatureVarID'),
            $this->ReadPropertyInteger('HumidityVarID'),
            $this->ReadPropertyInteger('RainVarID'),
            $this->ReadPropertyInteger('PressureVarID'),
            $this->ReadPropertyInteger('WindVarID')
        );
        if (!$baseFieldsValidation->valid) {
            $this->LogError('ApplyChanges', $this->translateValidationResult($baseFieldsValidation), $baseFieldsValidation->errorCode);
            return null;
        }

        // ==========================================
        // ARCHIVE REVIEW
        // ==========================================
        try {
            $archiveHandlerID = $this->GetArchiveInstanceID();
        } catch (Exception $e) {
            $this->LogException('ApplyChanges', $e, 301);
            return null;
        }
        $this->ClearContextError('ApplyChanges');

        // ==========================================
        // 1. LOCATION, WEATHER & SENSORS (REQUIRED)
        // ==========================================

        // The system type must not be empty
        $systemType = $this->ReadPropertyString('SystemType');
        if (empty($systemType)) {
            $this->LogError('ApplyChanges', 'Configuration error: no valid system type was selected.', 200);
            return null;
        }

        // The system type must be associated with a known metric configuration
        if ($this->getMetricsForType($systemType, 'ApplyChanges') === null) {
            return null;
        }

        // Lawn cooling only for SystemType "lawn." For other types, automatically remove the configuration.
        if ($systemType !== 'lawn') {
            $requiresReapply = false;

            if ($this->ReadPropertyBoolean('CoolingEnable')) {
                IPS_SetProperty($this->InstanceID, 'CoolingEnable', false);
                $requiresReapply = true;
            }

            $zonesForCoolingReset = $this->getZonesTree();
            if (!empty($zonesForCoolingReset)) {
                $zonesChanged = false;

                foreach ($zonesForCoolingReset as &$zone) {
                    if (!empty($zone['UseCooling'])) {
                        $zone['UseCooling'] = false;
                        $zonesChanged = true;
                    }
                }
                unset($zone);

                if ($zonesChanged) {
                    $encodedZones = json_encode($zonesForCoolingReset);
                    if (is_string($encodedZones)) {
                        IPS_SetProperty($this->InstanceID, 'ZonesTree', $encodedZones);
                        $requiresReapply = true;
                    }
                }
            }

            if ($requiresReapply) {
                $this->SendDebug('ApplyChanges', 'SystemType is not lawn: CoolingEnable and UseCooling were normalized to FALSE. Applying the updated configuration.', 0);
                IPS_ApplyChanges($this->InstanceID);
                return null;
            }
        }

        return [
            'archiveHandlerID' => $archiveHandlerID,
            'systemType'       => $systemType,
        ];
    }

    private function validateDomainConfig(ConfigurationValidator $validator, int $archiveHandlerID, string $systemType): bool
    {
        // Location must not be empty and must have a valid latitude and longitude
        $locationData = $this->decodeJsonArray($this->ReadPropertyString('Location'));
        $locationValidation = $validator->validateLocation($locationData);
        if (!$locationValidation->valid) {
            $this->LogError('ApplyChanges', $this->translateValidationResult($locationValidation), $locationValidation->errorCode);
            return false;
        }

        // Check if the required variable IDs exist in the system and if archive data is available
        try {
            $this->validateArchiveVariable($this->ReadPropertyInteger('TemperatureVarID'), $archiveHandlerID, $this->Translate('error.context.temperature_variable'));
        } catch (Exception $e) {
            $this->LogError('ApplyChanges', 'Configuration error: temperature variable is missing, does not exist, or its archive is not enabled. ' . $e->getMessage(), 202);
            return false;
        }

        try {
            $this->validateArchiveVariable($this->ReadPropertyInteger('HumidityVarID'), $archiveHandlerID, 'Luftfeuchtigkeits-Variable');
        } catch (Exception $e) {
            $this->LogError('ApplyChanges', 'Configuration error: humidity variable is missing, does not exist, or its archive is not enabled. ' . $e->getMessage(), 203);
            return false;
        }

        try {
            $this->validateArchiveVariable($this->ReadPropertyInteger('RainVarID'), $archiveHandlerID, $this->Translate('error.context.rain_variable'));
        } catch (Exception $e) {
            $this->LogError('ApplyChanges', 'Configuration error: rain variable is missing, does not exist, or its archive is not enabled. ' . $e->getMessage(), 208);
            return false;
        }

        $rainDayVarID = $this->normalizeOptionalObjectId($this->ReadPropertyInteger('RainDayVarID'));
        if (IPS_ObjectExists($rainDayVarID)) {
            try {
                $this->validateArchiveVariable($rainDayVarID, $archiveHandlerID, $this->Translate('error.context.daily_rain_variable'), false);
            } catch (Exception $e) {
                $this->LogError('ApplyChanges', 'Configuration error: archive for daily rain variable is not enabled. ' . $e->getMessage(), 209);
                return false;
            }
        }

        try {
            $this->validateArchiveVariable($this->ReadPropertyInteger('PressureVarID'), $archiveHandlerID, $this->Translate('error.context.pressure_variable'));
        } catch (Exception $e) {
            $this->LogError('ApplyChanges', 'Configuration error: air pressure variable is missing, does not exist, or its archive is not enabled. ' . $e->getMessage(), 204);
            return false;
        }

        try {
            $this->validateArchiveVariable($this->ReadPropertyInteger('WindVarID'), $archiveHandlerID, $this->Translate('error.context.wind_variable'));
        } catch (Exception $e) {
            $this->LogError('ApplyChanges', 'Configuration error: wind variable is missing, does not exist, or its archive is not enabled. ' . $e->getMessage(), 205);
            return false;
        }

        // Technically: validate the wind sensor mounting height against the supported operating range.
        // Functional behavior: values outside the range are rejected before any calculation uses them.
        // Domain rationale: wind correction formulas are only stable within realistic sensor heights, and invalid heights would distort evapotranspiration and runtime estimates.
        $windHeight = $this->ReadPropertyFloat('WindHeight');
        $windMaxSpeed = $this->ReadPropertyInteger('WindMaxSpeed');
        $windValidation = $validator->validateWindConfiguration($windHeight, $windMaxSpeed);
        if (!$windValidation->valid) {
            $this->LogError('ApplyChanges', $this->translateValidationResult($windValidation), $windValidation->errorCode);
            return false;
        }

        // Technically: validate the configured fallback baseline ETo before it is used by the zone planner.
        // Functional behavior: only realistic baseline values are accepted for later irrigation planning.
        // Domain rationale: a baseline that is too small or too large would produce either under-irrigation or unsafe catch-up irrigation after missing history.
        $baselineETO = $this->ReadPropertyFloat('BaselineETO');
        $startMonth = $this->ReadPropertyInteger('BaselineStartMonth');
        $endMonth = $this->ReadPropertyInteger('BaselineEndMonth');
        $baselineValidation = $validator->validateBaseline($baselineETO, $startMonth, $endMonth);
        if (!$baselineValidation->valid) {
            $this->LogError('ApplyChanges', $this->translateValidationResult($baselineValidation), $baselineValidation->errorCode);
            return false;
        }

        // Technically: validate the irrigation interval in whole days.
        // Functional behavior: the scheduler only accepts a bounded, non-zero interval for zone reuse.
        // Domain rationale: interval logic must prevent daily repetition as well as excessively long gaps that would ignore crop water demand.
        $irrigationInterval = $this->ReadPropertyInteger('IrrigationInterval');
        $irrigationStartTime = $this->decodeJsonArray($this->ReadPropertyString('IrrigationStartTime'));
        $irrigationMaxRuntime = $this->decodeJsonArray($this->ReadPropertyString('IrrigationMaxRuntime'));

        // Technically: verify that the configured start-time JSON contains the required hour component.
        // Functional behavior: malformed time objects are rejected before they can reach timer setup.
        // Domain rationale: irrigation start anchors must remain deterministic; corrupt time data would break the daily scheduling window.
        $irrigationMinRuntime = $this->ReadPropertyFloat('IrrigationMinRuntime');
        $IrrigationZoneMaxRuntime = $this->ReadPropertyInteger('IrrigationZoneMaxRuntime');
        $runtimeBudgetValidation = $validator->validateRuntimeBudget(
            $irrigationInterval,
            $irrigationStartTime,
            $irrigationMaxRuntime,
            $irrigationMinRuntime,
            $IrrigationZoneMaxRuntime
        );
        if (!$runtimeBudgetValidation->valid) {
            $this->LogError('ApplyChanges', $this->translateValidationResult($runtimeBudgetValidation), $runtimeBudgetValidation->errorCode);
            return false;
        }

        // ==========================================
        // 4. SOIL MOISTURE (OPTIONAL & DEPENDENCIES)
        // ==========================================

        // if GlobalSoilMoistureVarID is selected, the object must exist
        $globalSoilMoistureID = $this->normalizeOptionalObjectId($this->ReadPropertyInteger('GlobalSoilMoistureVarID'));
        $soilMinMoisture = $this->ReadPropertyInteger('SoilMinMoisture');
        $soilMoistureMode = $this->ReadPropertyInteger('SoilMoistureMode');
        $soilValidation = $validator->validateSoilMoistureConfiguration(
            $globalSoilMoistureID,
            IPS_ObjectExists($globalSoilMoistureID),
            $soilMinMoisture,
            $soilMoistureMode
        );
        if (!$soilValidation->valid) {
            $this->LogError('ApplyChanges', $this->translateValidationResult($soilValidation), $soilValidation->errorCode);
            return false;
        }

        // ==========================================
        // 5. LAWN COOLING
        // ==========================================
        $coolingStartTime = $this->decodeJsonArray($this->ReadPropertyString('CoolingStartTime'));
        $coolingTime = $this->ReadPropertyInteger('CoolingTime');
        $coolingTemp = $this->ReadPropertyInteger('CoolingTemp');
        $coolingEnable = $this->ReadPropertyBoolean('CoolingEnable');
        $coolingPushEnable = $this->ReadPropertyBoolean('CoolingPushEnable');
        $coolingPushInstance = $this->normalizeOptionalObjectId($this->ReadPropertyInteger('CoolingPushInstance'));
        $coolingValidation = $validator->validateCoolingConfiguration(
            $systemType,
            $coolingEnable,
            $coolingStartTime,
            $coolingTime,
            $coolingTemp,
            $irrigationStartTime,
            $irrigationMaxRuntime,
            $coolingPushEnable,
            $coolingPushInstance
        );
        if (!$coolingValidation->valid) {
            $this->LogError('ApplyChanges', $this->translateValidationResult($coolingValidation), $coolingValidation->errorCode);
            return false;
        }

        return true;
    }

    /**
     * @return array{zones: array<int, array<string, mixed>>, frontendValves: array<int, array{Name: string, ID: int}>}|null
     */
    private function validateZones(ConfigurationValidator $validator, int $archiveHandlerID): ?array
    {
        // ==========================================
        // 6. ZONE CONFIGURATION (TREE)
        // ==========================================
        $zones = $this->getZonesTree();

        if (empty($zones)) {
            $this->LogError('ApplyChanges', 'Zone error: no zone configured.', 250);
            return null;
        }

        $sequences = [];
        $valveIDsUsed = [];
        $frontendValves = [];
        $validOrientations = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        $globalSoilMoistureID = $this->normalizeOptionalObjectId($this->ReadPropertyInteger('GlobalSoilMoistureVarID'));

        foreach ($zones as $zone) {
            $zoneBaseValidation = $validator->validateZoneBeforeArchiveChecks($zone, $validOrientations);
            if (!$zoneBaseValidation->valid) {
                $this->LogError('ApplyChanges', $this->translateValidationResult($zoneBaseValidation), $zoneBaseValidation->errorCode);
                return null;
            }

            try {
                $this->validateArchiveVariable(
                    $zone['ValveVarID'],
                    $archiveHandlerID,
                    sprintf($this->Translate('error.context.valve_variable_for_zone'), (string) $zone['Name'])
                );
            } catch (Exception $e) {
                $this->LogError('ApplyChanges', 'Zone error: a valve could not be checked or its archive is not enabled. ' . $e->getMessage(), 253);
                return null;
            }

            try {
                $valveVariable = IPS_GetVariable((int) $zone['ValveVarID']);
                if (($valveVariable['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN) {
                    $this->LogError('ApplyChanges', 'Zone error: valve variables must be Boolean.', 261);
                    return null;
                }
            } catch (Exception $e) {
                $this->LogError('ApplyChanges', 'Zone error: valve type could not be checked. ' . $e->getMessage(), 261);
                return null;
            }

            $zoneValveUniquenessValidation = $validator->validateZoneValveUniqueness($zone, $valveIDsUsed);
            if (!$zoneValveUniquenessValidation->valid) {
                $this->LogError('ApplyChanges', $this->translateValidationResult($zoneValveUniquenessValidation), $zoneValveUniquenessValidation->errorCode);
                return null;
            }

            // The valve variable must ALWAYS be unique across all zones (including inactive ones)
            $valveIDsUsed[] = $zone['ValveVarID'];

            $zoneSoilMoistureVarId = $this->extractZoneSoilMoistureVarId($zone);

            // SoilMoistureVarID: only a normalized positive ID is treated as configured.
            if ($zoneSoilMoistureVarId > 0 && !IPS_ObjectExists($zoneSoilMoistureVarId)) {
                $this->LogError('ApplyChanges', 'Zone error: the assigned zone soil moisture sensor does not exist.', 254);
                return null;
            }

            $zonePostArchiveValidation = $validator->validateZonePostArchiveChecks($zone, $globalSoilMoistureID, $sequences);
            if (!$zonePostArchiveValidation->valid) {
                $this->LogError('ApplyChanges', $this->translateValidationResult($zonePostArchiveValidation), $zonePostArchiveValidation->errorCode);
                return null;
            }
            $sequences[] = $zone['Sequence'];

            //Write the values to an array that is needed for the front-end display
            $frontendValves[$zone['Sequence']] = [
                'Name' => (string) $zone['Name'],
                'ID'   => (int) $zone['ValveVarID']
            ];
        }

        return [
            'zones'          => $zones,
            'frontendValves' => $frontendValves,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $zones
     * @param array<int, array{Name: string, ID: int}> $frontendValves
     */
    private function finalizeFrontendAndTimers(ConfigurationValidator $validator, array $zones, array $frontendValves): void
    {
        // ==========================================
        // 7. MAINTENANCE & OTHER MATTERS
        // ==========================================

        // Technically: enforce mutual exclusion between automatic irrigation and maintenance mode.
        // Functional behavior: the instance refuses a configuration where both modes are active together.
        // Domain rationale: maintenance work must have priority over scheduled watering so valves do not open during service operations.
        $maintenanceValidation = $validator->validateMaintenanceExclusivity(
            $this->ReadPropertyBoolean('SystemAutoEnable'),
            $this->ReadPropertyBoolean('SystemMaintenanceEnable')
        );
        if (!$maintenanceValidation->valid) {
            $this->LogError('ApplyChanges', $this->translateValidationResult($maintenanceValidation), $maintenanceValidation->errorCode);
            return;
        }

        // ==========================================
        // If every test was error-free
        // ==========================================
        $errors = $this->decodeJsonArray($this->ReadAttributeString('ActiveErrors'));

        // We ignore hardware errors in maintenance mode; everything else remains unchanged
        if (!$this->ReadPropertyBoolean('SystemAutoEnable')) {
            // If we are in maintenance mode, temporarily remove the aggregated valve error from the check
            unset($errors['ValveControl']);
        }

        if (count($errors) > 0) {
            // There is still at least one error -> Use the most recent error code
            $lastCode = end($errors);
            $this->SetStatus($lastCode);
            $this->SendDebug('ApplyChanges', 'Instance active; retaining error status: ' . $lastCode, 0);
        } else {
            // No errors in memory -> all clear!
            $this->SetStatus(102);
            $this->SendDebug('ApplyChanges', sprintf('Validation succeeded. %d zones loaded. Instance active (102).', count($zones)), 0);
        }

        // ==========================================
        // Check for status changes in automatic and maintenance mode
        // ==========================================
        // Read the new values from storage
        $newAuto = $this->ReadPropertyBoolean('SystemAutoEnable');
        $newMaintenance = $this->ReadPropertyBoolean('SystemMaintenanceEnable');

        // Get the old values from the frontend variables (if they already exist)
        $autoEnableID = $this->GetExistingFrontendVariableID('AutoEnable');
        $oldAuto = $autoEnableID > 0 ? $this->GetValue('AutoEnable') : $newAuto;
        $maintenanceEnableID = $this->GetExistingFrontendVariableID('MaintenanceEnable');
        $oldMaintenance = $maintenanceEnableID > 0 ? $this->GetValue('MaintenanceEnable') : $newMaintenance;

        // Has the automatic mode OR the maintenance mode changed? If yes, close all valves
        if ($newAuto !== $oldAuto || $newMaintenance !== $oldMaintenance) {
            if (($oldAuto === true && $newAuto === false) || ($oldMaintenance === false && $newMaintenance === true)) {
                $this->SetAutoIrrigationStopRequested(true);
                $this->SendDebug('ApplyChanges', 'Mode change detected: stop signal set for AutoIrrigationDaily.', 0);
            }

            $this->SendDebug('ApplyChanges', 'Mode change detected: closing all valves for safety.', 0);
            if (!$this->CloseAllValves()) {
                $this->SendDebug('ApplyChanges', 'ABORTED: valves could not be closed safely during the mode change!', 0);
                return;
            }
        }

        // ==========================================
        // Configure Variables for the Front End
        // ==========================================

        // Register, enable, and sync automatic mode
        $this->RegisterVariableBoolean('AutoEnable', $this->Translate('variable.auto_enable'), '~Switch', 10);
        $this->EnableAction('AutoEnable');
        $this->SetValue('AutoEnable', $this->ReadPropertyBoolean('SystemAutoEnable'));

        // Register, enable, and sync maintenance mode
        $this->RegisterVariableBoolean('MaintenanceEnable', $this->Translate('variable.maintenance_enable'), '~Switch', 20);
        $this->EnableAction('MaintenanceEnable');
        $this->SetValue('MaintenanceEnable', $this->ReadPropertyBoolean('SystemMaintenanceEnable'));

        // Register runtime state variable
        $this->RegisterVariableString(AutoIrrigationContracts::RUN_STATE_IDENT, $this->Translate('variable.auto_irrigation_run_state'), '', 30);
        $this->DisableAction(AutoIrrigationContracts::RUN_STATE_IDENT);
        $this->InitializeAutoIrrigationRunState();

        // Register lawn cooling runtime state as a read-only frontend variable.
        $this->RegisterVariableString(LawnCoolingContracts::RUN_STATE_IDENT, $this->Translate('variable.lawn_cooling_run_state'), '', 31);
        $this->DisableAction(LawnCoolingContracts::RUN_STATE_IDENT);

        // Register the user skip control before initializing the cooling state because a state transition synchronizes this action.
        $this->RegisterVariableBoolean(LawnCoolingContracts::SKIP_VARIABLE_IDENT, $this->Translate('variable.lawn_cooling_skip'), '~Switch', 32);
        $this->InitializeLawnCoolingRunState();

        // Technically: inspect the registered cooling state before clearing a volatile precheck marker.
        // Functional behavior: stale advisory prechecks are removed only when no cooling run is active.
        // Domain rationale: a precheck belongs to one pending run; retaining it after termination could incorrectly expose a cancellation action for a future cycle.
        if (!$this->IsLawnCoolingRunActive()) {
            $this->SetLawnCoolingPrecheckActive(false);
        }

        // Synchronize the registered user skip control with valve progress.
        $this->syncLawnCoolingSkipActionAvailability();

        // Register and enable the individual zone valves. These values must be created dynamically as they can be changed by the user
        $this->BuildActiveValveProfile($frontendValves);

        // Control the dropdown for the valves
        if ($this->ReadPropertyBoolean('SystemMaintenanceEnable')) {
            $this->EnableAction('ActiveValve');
        } else {
            $this->DisableAction('ActiveValve');
        }

        $this->scheduleWaterBalanceHistoryTimer();
        $this->scheduleAutoIrrigationDailyTimer();

        // Schedule lawn cooling timers
        $this->scheduleLawnCoolingDailyTimer();
        $this->scheduleCoolingPrecheckTimer();
    }

    /**
     * Returns the target configuration metrics based on the system type identifier.
     * This acts as the single source of truth for plant coefficients and depletion limits.
     *
     * @return array{maxDeficit: float, kc: float}|null
     */
    private function getMetricsForType(string $type, string $context = 'getMetricsForType'): ?array
    {
        $mapping = [
            'lawn' => [
                'maxDeficit' => 12.0,
                'kc'         => 1.00
            ],
            'beds_shrubs' => [
                'maxDeficit' => 22.0,
                'kc'         => 0.75
            ],
            'raised_beds' => [
                'maxDeficit' => 7.0,
                'kc'         => 1.15
            ]
        ];

        if (!array_key_exists($type, $mapping)) {
            $this->LogError($context, 'Configuration error: no valid system type was selected.', 200);
            return null;
        }

        return $mapping[$type];
    }
}
