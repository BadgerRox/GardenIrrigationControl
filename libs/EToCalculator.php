<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

class EToCalculator
{
    private ?DebugLoggerInterface $logger;

    public function __construct(?DebugLoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Main function: validates the data, fetches the API values, and drives the calculation.
     *
     * @param array<string, mixed> $archiveData
     */
    public function calculateDailyETo(
        float $latitude,
        float $longitude,
        string $dateUS,
        int $dayOfYear,
        string $timezone,
        float $windSensorHeightMeters,
        array $archiveData,
        ?HttpClientInterface $httpClient = null
    ): float {

        $this->logger?->debug('EToCalculator', 'Starting calculation for ' . $dateUS . ' (day of year: ' . $dayOfYear . ')');

        // 1. One-time validation of the raw data
        if (empty($archiveData)) {
            throw new LocalizedRuntimeException('error.eto.archive_data_missing');
        }

        $validatedArchiveData = $this->validateArchiveDataPayload($archiveData);

        $tempf_min = $validatedArchiveData['temp_min'];
        $this->logger?->debug('EToCalculator', 'Temp min: ' . $tempf_min . ' °C');
        $tempf_max = $validatedArchiveData['temp_max'];
        $this->logger?->debug('EToCalculator', 'Temp max: ' . $tempf_max . ' °C');
        $humidity_min = max(0.0, min(100.0, $validatedArchiveData['humidity_min']));
        $this->logger?->debug('EToCalculator', 'Feuchte min: ' . $humidity_min . ' %');
        $humidity_max = max(0.0, min(100.0, $validatedArchiveData['humidity_max']));
        $this->logger?->debug('EToCalculator', 'Feuchte max: ' . $humidity_max . ' %');
        $baromabsin_avg = $validatedArchiveData['baro_avg'];
        $this->logger?->debug('EToCalculator', 'Luftdruck avg: ' . $baromabsin_avg . ' hPa');
        $windspeedkmh_avg = $validatedArchiveData['wind_avg'];
        $this->logger?->debug('EToCalculator', 'Windgeschwindigkeit avg: ' . $windspeedkmh_avg . ' km/h');

        if ($tempf_min < -50.0 || $tempf_max > 60.0 || $tempf_min > $tempf_max) {
            throw new LocalizedRuntimeException('error.eto.temperature_out_of_range');
        }

        // 2. API retrieval for radiation and elevation above sea level
        $this->logger?->debug('EToCalculator', 'Requesting radiation data and elevation from Open-Meteo...');
        $apiData = $this->fetchOpenMeteoData($latitude, $longitude, $dateUS, $timezone, $httpClient);
        $seehight = (float) $apiData['elevation'];
        $rs = (float) $apiData['radiation'];
        $this->logger?->debug('EToCalculator', sprintf('API data received: elevation=%sm, yesterday shortwave radiation sum (Rs)=%s MJ/m²', $seehight, $rs));

        // 3. Execute the mathematical building blocks in sequence
        $esData = $this->calculateSaturationVaporPressure($tempf_min, $tempf_max);
        $this->logger?->debug('EToCalculator', 'esTmin: ' . $esData['esTmin'] . ' kPa');
        $this->logger?->debug('EToCalculator', 'esTmax: ' . $esData['esTmax'] . ' kPa');
        $this->logger?->debug('EToCalculator', 'Saturation vapor pressure: ' . $esData['esTmean'] . ' kPa');

        $ea = $this->calculateActualVaporPressure($esData['esTmin'], $esData['esTmax'], $humidity_min, $humidity_max, $esData['esTmean']);
        $this->logger?->debug('EToCalculator', 'Actual vapor pressure (ea): ' . $ea . ' kPa');

        $delta = $this->calculateSlopeOfVaporPressureCurve($tempf_min, $tempf_max, $esData['esTmean']);
        $this->logger?->debug('EToCalculator', 'Slope of the saturation vapor pressure curve: ' . $delta . ' kPa/°C');

        $gamma = $this->calculatePsychrometricConst($baromabsin_avg);
        $this->logger?->debug('EToCalculator', 'Psychrometric constant: ' . $gamma . ' kPa/°C');

        $u2 = $this->calculateWindSpeedAt2M($windspeedkmh_avg, $windSensorHeightMeters);
        $this->logger?->debug('EToCalculator', 'Wind speed at 2 m height: ' . $u2 . ' m/s');

        $ra = $this->calculateExtraterrestrialRadiation($latitude, $dayOfYear);
        $this->logger?->debug('EToCalculator', 'extraterrestrial radiation (Ra): ' . $ra . ' MJ/m²/day');

        $rso = $this->calculateClearSkyRadiation($ra, $seehight);
        $this->logger?->debug('EToCalculator', 'clear sky radiation (Rso): ' . $rso . ' MJ/m²/day');

        $rnl = $this->calculateNetLongwaveRadiation($tempf_min, $tempf_max, $ea, $rs, $rso);
        $this->logger?->debug('EToCalculator', 'outgoing longwave radiation (Rnl): ' . $rnl . ' MJ/m²/day');

        $rn = $this->calculateNetRadiation($rs, $rnl);
        $this->logger?->debug('EToCalculator', 'Net Radiation (Rn): ' . $rn . ' MJ/m²/day');

        // 4. Finale FAO-56 Penman-Monteith Synthese
        $eto = $this->executePenmanMonteith($delta, $gamma, $rn, $u2, $esData['esTmean'], $ea, $tempf_min, $tempf_max);
        $this->logger?->debug('EToCalculator', 'Reference evapotranspiration (ETo): ' . $eto . ' mm/day');

        return $eto;
    }

    /**
     * Retrieve the radiation data and sea level data from the API.
     *
     * @return array{elevation: float, radiation: float}
     */
    protected function fetchOpenMeteoData(float $latitude, float $longitude, string $dateUS, string $timezone, ?HttpClientInterface $httpClient = null): array
    {
        $url = sprintf(
            'https://satellite-api.open-meteo.com/v1/archive?latitude=%s&longitude=%s&daily=shortwave_radiation_sum&timezone=%s&start_date=%s&end_date=%s',
            $latitude,
            $longitude,
            urlencode($timezone),
            $dateUS,
            $dateUS
        );

        // If no client was passed from outside (e.g. during live operation),
        // we create the real client. This block is skipped in PHPUnit tests!
        if ($httpClient === null) {
            $httpClient = new SimpleHttpClient();
        }
        $apiData = $httpClient->SendHTTPRequest($url);

        if (isset($apiData['error']) && $apiData['error'] === true) {
            throw new LocalizedRuntimeException('error.eto.weather_api_failed', [$apiData['reason'] ?? 'Unknown API error']);
        }

        if (!isset($apiData['elevation']) || !isset($apiData['daily']['shortwave_radiation_sum'][0])) {
            throw new LocalizedRuntimeException('error.eto.invalid_api_structure');
        }

        if ((float) $apiData['daily']['shortwave_radiation_sum'][0] < 0.0) {
            throw new LocalizedRuntimeException('error.eto.negative_radiation');
        }

        return [
            'elevation' => (float) $apiData['elevation'],
            'radiation' => (float) $apiData['daily']['shortwave_radiation_sum'][0]
        ];
    }

    /* ---------------------------------------------------
    Calculate the vapor pressure at saturation (es)
    --------------------------------------------------- */
    // es = 0.61078 * exp(17.27*T / (T + 237.3))  [kPa]
    // delivers the maximum possible water vapor amount at a given temperature
    /**
     * @return array{esTmin: float, esTmax: float, esTmean: float}
     */
    protected function calculateSaturationVaporPressure(float $tMin, float $tMax): array
    {
        $esTmin = 0.61078 * exp((17.27 * $tMin) / ($tMin + 237.3));
        $esTmax = 0.61078 * exp((17.27 * $tMax) / ($tMax + 237.3));
        $esTmean = ($esTmin + $esTmax) / 2.0;

        if ($esTmean <= 0.0) {
            throw new LocalizedRuntimeException('error.eto.saturation_vapor_pressure_invalid');
        }

        return ['esTmin' => $esTmin, 'esTmax' => $esTmax, 'esTmean' => $esTmean];
    }

    /* ---------------------------------------------------
    actual vapor pressure based on min/max relative humidity (ea)
    --------------------------------------------------- */
    // ea = (es(Tmin)*RHmax + es(Tmax)*RHmin) / 2
    // provides the averaged actual water pressure in the air
    protected function calculateActualVaporPressure(float $esTmin, float $esTmax, float $rhMin, float $rhMax, float $esTmean): float
    {
        $ea = (($esTmin * ($rhMax / 100.0)) + ($esTmax * ($rhMin / 100.0))) / 2.0;

        if ($ea > $esTmean) {
            $this->logger?->debug('EToCalculator', 'Warning: actual vapor pressure exceeds saturation vapor pressure; clamping to saturation vapor pressure (Es).');
            $ea = $esTmean;
        }
        return $ea;
    }

    /* ---------------------------------------------------
    Slope of the vapor pressure saturation curve (Δ)
    --------------------------------------------------- */
    // Δ = 4098 * es / (T + 237.3)²
    // describes how strongly it changes with temperature
    // important for weighting the radiation component of ETo
    protected function calculateSlopeOfVaporPressureCurve(float $tMin, float $tMax, float $esTmean): float
    {
        $tAvg = ($tMin + $tMax) / 2.0;
        $delta = (4098.0 * $esTmean) / pow(($tAvg + 237.3), 2);

        if ($delta <= 0.0) {
            throw new LocalizedRuntimeException('error.eto.vapor_pressure_slope_invalid');
        }
        return $delta;
    }

    /* ---------------------------------------------------
    Psychrometric constant (γ)
    --------------------------------------------------- */
    // γ = 0.000665 * P
    // describes the relationship between temperature, pressure, and the amount of water vapor the air can hold
    // depends directly on atmospheric pressure P in kPa
    protected function calculatePsychrometricConst(float $baroHpa): float
    {
        $baroKpa = $baroHpa * 0.1; // Converting hPa to kPa
        $gamma = 0.000665 * $baroKpa;
        if ($gamma <= 0.0) {
            throw new LocalizedRuntimeException('error.eto.psychrometric_constant_invalid');
        }
        return $gamma;
    }

    /* ---------------------------------------------------
    Convert wind speed to 2 m height
    --------------------------------------------------- */
    // Conversion of the measured wind speed to 2 m according to FAO-56:
    // u2 = u(z) * 4.87 / ln(67.8*z - 5.42)
    // Wind speed correction due to friction over the ground
    protected function calculateWindSpeedAt2M(float $windKmh, float $windSensorHeightMeters): float
    {
        $windMs = $windKmh / 3.6; // Converting km/h to m/s
        $logArg = 67.8 * $windSensorHeightMeters - 5.42;

        // Extended protection against log(<=0) and division by zero (log(1) = 0)
        if ($logArg <= 0.0 || abs($logArg - 1.0) < 0.001) {
            throw new LocalizedRuntimeException('error.eto.wind_sensor_height_invalid', [$windSensorHeightMeters]);
        }

        return max(0.0, $windMs * (4.87 / log($logArg)));
    }

    /* ---------------------------------------------------
    Extraterrestrial radiation (Ra)
    --------------------------------------------------- */
    protected function calculateExtraterrestrialRadiation(float $latitude, int $dayOfYear): float
    {
        $gsc = 0.0820; // Solar constant MJ/m²/min
        $dr = 1.0 + 0.033 * cos(((2.0 * pi()) / 365.0) * $dayOfYear);  // Earth-Sun distance correction (dimensionless)
        $fi = $latitude * (pi() / 180.0); // Geographic latitude [rad]
        $delta_kl = 0.409 * sin(((2.0 * pi()) / 365.0) * $dayOfYear - 1.39); //Declination of the Sun [rad]
        $ws = acos(-tan($fi) * tan($delta_kl)); //Sunset Angle [rad]

        return ((24.0 * 60.0) / pi()) * $gsc * $dr * ($ws * sin($fi) * sin($delta_kl) + cos($fi) * cos($delta_kl) * sin($ws));
    }

    /* ---------------------------------------------------
    Clear sky radiation (Rso)
    --------------------------------------------------- */
    protected function calculateClearSkyRadiation(float $ra, float $seehight): float
    {
        return (0.75 + 2.0 * pow(10, -5) * $seehight) * $ra;
    }

    /* ---------------------------------------------------
    Outgoing long-wave radiation (Rnl) (Stefan-Boltzmann law plus corrections for humidity and clouds)
    --------------------------------------------------- */
    // Rnl = σ * ( (Tmin⁴ + Tmax⁴)/2 ) * (0.34 - 0.14*sqrt(ea)) * (1.35*(Rs/Rso) - 0.35)
    // Stefan-Boltzmann law: thermal radiation of the Earth's surface
    // Modifiers:
    //   • (0.34 - 0.14√ea)  → damping due to humidity
    //   • (1.35*Rs/Rso - 0.35) → cloud correction
    // Ratio Rs/Rso is capped because otherwise physically incorrect values may be produced
    protected function calculateNetLongwaveRadiation(float $tMin, float $tMax, float $ea, float $rs, float $rso): float
    {
        $sigma = 4.903 * pow(10, -9); // MJ/m²/day/K⁴ - Stefan-Boltzmann constant
        $tMinK = $tMin + 273.15;
        $tMaxK = $tMax + 273.15;

        $ratio = $rso > 0 ? ($rs / $rso) : 1.0;

        // Cap on cloudy/very bright days
        $ratio = max(0.3, min(1.0, $ratio));

        return $sigma * ((pow($tMinK, 4) + pow($tMaxK, 4)) / 2.0) * (0.34 - 0.14 * sqrt($ea)) * (1.35 * $ratio - 0.35);
    }

    /* ---------------------------------------------------
    Net Radiation (Rn)
    --------------------------------------------------- */
    // Rn = (1 - α)*Rs - Rnl
    // Net radiation = short-wave gain – long-wave loss
    protected function calculateNetRadiation(float $rs, float $rnl): float
    {
        $alpha = 0.23; // Surface albedo (= share of shortwave solar radiation that is reflected). Grass is ~ 0.23
        return (1.0 - $alpha) * $rs - $rnl;
    }

    /* ---------------------------------------------------
    Daily ETO - FAO-56 Penman-Monteith
    --------------------------------------------------- */
    // ETo = [0.408*Δ*(Rn - G) + γ*(900/(T+273.15))*u2*(es - ea)] / [Δ + γ*(1 + 0.34*u2)]
    //
    // Left side (radiation component):
    //   0.408*Δ*(Rn - G)
    //   describes evaporation through available energy
    //
    // Right side (aerodynamic component):
    //   γ * (900/(T+273.15)) * u2 * (es - ea)
    //   Evaporation due to wind and dryness
    //
    // Denominator:
    //   Δ + γ*(1 + 0.34*u2)
    //   normalizes both components to a physically correct total value
    protected function executePenmanMonteith(
        float $delta,
        float $gamma,
        float $rn,
        float $u2,
        float $esTmean,
        float $ea,
        float $tMin,
        float $tMax
    ): float {
        $g = 0.0; // Soil heat flux (MJ m⁻² day⁻¹), often negligible for daily values
        $tAvgK = (($tMin + $tMax) / 2.0) + 273.15;

        // Validation of the denominator according to your template
        $denominator = $delta + $gamma * (1.0 + 0.34 * $u2);
        if ($denominator <= 0.0) {
            throw new LocalizedRuntimeException('error.eto.penman_monteith_denominator_invalid');
        }

        $eto = (0.408 * $delta * ($rn - $g) + $gamma * (900.0 / $tAvgK) * $u2 * ($esTmean - $ea)) / $denominator;

        // A negative ETo value means physically: on that day the net energy balance was negative
        // The atmosphere/surface emitted more longwave energy than shortwave solar energy arrived.
        // This is possible on cold, humid days with relatively high outgoing radiation and leads to actual evaporation being practically zero (possibly even condensation or frost formation).
        if ($eto < 0.0) {
            $eto = 0.0;
        }

        // Log a warning for extremely high values (>15 mm)
        if ($eto > 15.0) {
            $this->logger?->debug('EToCalculator', 'Warning: ETo is unusually high (>15 mm/day).');
        }

        return $eto;
    }

    /**
     * Validates required payload keys once and returns normalized float values.
     *
     * @param array<string, mixed> $archiveData
     * @return array{temp_min: float, temp_max: float, humidity_min: float, humidity_max: float, baro_avg: float, wind_avg: float}
     */
    protected function validateArchiveDataPayload(array $archiveData): array
    {
        $requiredKeys = ['temp_min', 'temp_max', 'humidity_min', 'humidity_max', 'baro_avg', 'wind_avg'];
        $missingKeys = [];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $archiveData)) {
                $missingKeys[] = $key;
            }
        }

        if (count($missingKeys) > 0) {
            throw new LocalizedRuntimeException('error.eto.archive_data_missing_fields', [implode(', ', $missingKeys)]);
        }

        $normalized = [];
        foreach ($requiredKeys as $key) {
            if (!is_numeric($archiveData[$key])) {
                throw new LocalizedRuntimeException('error.eto.archive_data_non_numeric', [$key]);
            }
            $normalized[$key] = (float) $archiveData[$key];
        }

        return $normalized;
    }
}
