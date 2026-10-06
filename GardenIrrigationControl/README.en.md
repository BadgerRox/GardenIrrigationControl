# Garden Irrigation Control

**<p>Wählen Sie Ihre Sprache / Choose your language:</p>**
[![Deutsch](https://img.shields.io/badge/Sprache-Deutsch-darkgreen?style=flat&logo=germany)](README.md)
[![English](https://img.shields.io/badge/Language-English-007acc?style=flat&logo=unitedstates)](README.en.md)

Garden Irrigation Control is an automated garden irrigation system for IP-Symcon. The irrigation amount is dynamically calculated from current weather data, location information, and previous consumption for targeted watering without waterlogging or drought stress.

## Table of Contents

1. [Features](#1--features)
2. [Requirements](#2--requirements)
3. [Setup in IP-Symcon](#3--setup-in-ip-symcon)
4. [Configuration Areas](#4--configuration-areas)
5. [How It Works](#5--how-it-works)
6. [Error and Safety Logic](#6--error-and-safety-logic)
7. [Status Variables and Automatic Objects](#7--status-variables-and-automatic-objects)
8. [Usage Notes](#8--usage-notes)
9. [Configuration Help for Weather Stations and Services](#9--configuration-help-for-weather-stations-and-services)
10. [FAQ](#10--faq)
11. [Disclaimer](#11--disclaimer)
12. [Changelog](#12--changelog)
13. [Attribution](#13--attribution)

---

## 1. 💧 Features

* **Mathematical water demand calculation:** Determines potential water loss ([evapotranspiration](https://en.wikipedia.org/wiki/Evapotranspiration)) according to the FAO-56 (*modified Penman-Monteith*) standard.
* **Manufacturer-independent:** Compatible with all valves and sprinklers controllable through IP-Symcon using standard switching variables (`Boolean`).
* **Multi-zone control:** Independent configuration of multiple irrigation circuits.
* **Targeted planting profiles:** Preset parameters for lawns, beds and shrubs, as well as raised beds.
* **Local weather and forecast data:** Integration of historical values as well as precipitation and wind forecasts via `open-meteo.com`.
* **Advanced logic functions:**
  * Optional integration of soil moisture sensors for advanced control.
  * Integrated lawn cooling function on extremely hot days.
  * Automatic suspension of irrigation in case of rain, wind, or a high probability of rain.

---

## 2. ✅ Requirements

* IP-Symcon version 9 or later.
* A local weather station or weather service providing live values for temperature, humidity, precipitation, air pressure, and wind speed.
* Valves or sprinklers controllable through IP-Symcon as `Boolean` switching variables.

---

## 3. ⚙️ Setup in IP-Symcon

The **Garden Irrigation Control** module can be found using the quick filter under *Add Instance*.

Further information about adding instances can be found in the [IP-Symcon documentation](https://www.symcon.de/en/service/documentation/basics/instances/#Add_instance).

---

## 4. 🧭 Configuration Areas

### 4.1 Location, Weather & Sensors

> [!IMPORTANT]
> * **Units:** Garden Irrigation Control requires the specified units. Values from your weather station or weather service using different units must be converted before they are passed to the module.
> * **Archive logging:** Archive logging with `Standard` aggregation must be enabled for all specified variables.

* `System type`: Currently, *Lawn*, *Beds & shrubs*, and *Raised beds & containers* are available.
* `Location`: The geographic location where irrigation is operated.
* `Temperature`: Current live outdoor temperature. Unit: `°C`.
* `Humidity`: Current live humidity. Unit: `%`.
* `Rain`: Current live precipitation values. Unit: `mm/h` per m².
* `Rain (optional)`: Accumulated daily precipitation values. Unit: `mm`.
* `Absolute air pressure`: Current live air pressure. Unit: `hPa`.
* `Wind speed`: Current live wind speed. Unit: `km/h`.
* `Anemometer height above ground`: Mounting height above the ground. Select `2 m` when using an external weather service.
* `Wind speed at which irrigation is blocked/stopped`: Threshold for pausing automatic irrigation, for example to prevent sprinkler drift.
* `Consider rain forecast`: When enabled, the module retrieves the forecast from `open-meteo.com` before starting. If at least `5 mm` of precipitation with a probability of at least `70 %` is expected within the next 12 hours, irrigation is automatically suspended for that day.

> [!TIP]
> Tips for assigning variables for different weather stations and weather services can be found in the [configuration help](#9--configuration-help-for-weather-stations-and-services).

### 4.2 Average Evapotranspiration

Baseline ETo describes the average daily water loss through evapotranspiration and serves as the basis for demand calculation. Garden Irrigation Control retrieves this value automatically via `open-meteo.com`. For even more accurate results, values from archived data of your own weather station can be entered instead.

* `start month`: Month in which irrigation normally begins.
* `end month`: Month in which the irrigation season normally ends.

### 4.3 Calculation & Runtimes

* `Maximum interval between irrigations`: Defines the maximum number of days between two irrigation cycles. An interval of several days prevents shallow root growth and strengthens plant resilience. See [How It Works](#5--how-it-works) for more information.
* `Irrigation start time`: The time at which irrigation starts.
* `Maximum irrigation runtime`: Together with the start time, defines when irrigation must be finished at the latest.
* `Minimum runtime per zone`: Irrigation is not performed when the minimum runtime is not reached, avoiding short switching signals.
* `Maximum runtime limit per zone`: Absolute limit for how long a valve may remain open. (This also applies to manual control in maintenance mode.)

### 4.4 Soil Moisture

Soil moisture sensors can be integrated for a more precise assessment of soil moisture, either globally for the entire system or individually per zone. When using a global sensor, it is recommended to select, in the zone configuration, the zone with start sequence 1 in which the sensor is actually installed.

* `Soil moisture sensor (global)`: Current live soil moisture. Unit: `%`.
* `Moisture threshold`: Percentage at which irrigation is stopped. Recommendation for lawns at a depth of 5–10 cm: `30–40 %`; at 10–15 cm: `55–65 %`.
* `Behavior`: Select whether soil moisture is checked only before irrigation starts (start criterion) or also during ongoing irrigation (start & stop criterion).

### 4.5 Lawn Cooling

Lawn cooling is available only for the *Lawn* system type and when automatic irrigation is enabled. The goal is to briefly irrigate the lawn and adjacent areas during extreme daytime temperatures to prevent heat damage. Cooling is performed only if there has been no rain in the six hours before starting. Lawn cooling should be used only during extreme heat because regular short irrigation encourages shallow root growth.

* `Enable lawn cooling`: Enables or disables the cooling function.
* `Cooling start time`: The time at which cooling starts.
* `Only when exceeding X degrees for at least 5 minutes`: Temperature threshold in `°C` for starting cooling. The temperature must continuously exceed this value for at least five minutes.
* `Send a push notification`: Enables a push notification five minutes before cooling starts.
* `Visualization instance`: Selects the target visualization instance for notifications. See the [IP-Symcon documentation](https://www.symcon.de/en/service/documentation/module-reference/core-instances/notification-control/).

### 4.6 Zone Configuration

> [!IMPORTANT]
> * **Archive logging:** Archive logging with `Standard` aggregation must be enabled for all valves.

* `Active`: Enables or disables irrigation for this zone.
* `Start sequence`: Determines the order in which zones start.
* `Name`: Free-form name of the zone.
* `Precipitation rate`: Precipitation amount from the valves or sprinklers in `mm/min`. Open the valve manually for 10 minutes, determine the water consumption from the meter, and divide the consumption by the irrigated area in m² and then by 10.
* `Slope`: Accounts for sloped terrain; greater runoff requires adjusted application.
* `Orientation`: Accounts for sunlight exposure and shaded areas.
* `Valve`: Assignment of the switching element for this zone. Because this solution is manufacturer-independent, the hardware (e.g., KNX, Homematic, Zigbee, Z-Wave, GPIO, Siemens LOGO!, Shelly) does not matter. The only requirement is that the valve be represented in IP-Symcon as a switching variable (Boolean / On-Off). 
* `Soil moisture sensor`: Optional soil moisture sensor for this zone.
* `Use global soil moisture values`: Enables or disables use of the global sensor for this zone.
* `Enable lawn cooling`: Enables or disables cooling for this zone.

### 4.7 Maintenance & Other

* `Enable automatic irrigation`: Enables or disables automatic irrigation, including lawn cooling.
* `Enable maintenance mode`: Stops automatic operation and enables manual switching of the valves.

### 4.8 Debug Tools (Expert Mode)

> [!CAUTION]
> These settings are intended exclusively for error diagnosis. They can make irreversible data changes.

* `Recreate ETo history`: Recalculates missing daily ETo values for the previous 14 days using archived weather data.
* `Reload zone archive data`: Deletes the stored water balance history for all zones and rebuilds it using the previous 14 days.
* `Reset zone archive data + water storage`: Reads archived valve and weather data again to update the water balance.
* `Debug output of attributes`: Displays internal attributes in the debug window.
* `Debug output of rain forecast`: Manually tests the forecast interface and displays the output in the debug window.
* `Send test push notification`: Sends a test notification to the configured devices.

---

## 5. 🔄 How It Works

### 5.1 Basic Principle

The module does not control irrigation according to fixed times, but through a dynamic control loop. The goal is to deliver water according to demand while considering:

* **Meteorology & balance:** ETo evapotranspiration, precipitation, and rain forecast.
* **Zone profile:** Sun exposure, slope, and individual soil moisture.
* **Safety & protection:** Automatic stops in wind or rain, as well as hardware protection.
* **Time windows:** Defined operating times and check intervals.

### 5.2 Process and Decision Scheme

When triggered, the module checks the following conditions for each active zone in sequence:

1. **Safety check:** Is the wind too strong or is it currently raining? (see 5.4)
2. **Time window check:** Is the current time within the permitted irrigation window?
3. **Demand check:** Is there an actual water deficit according to the water balance (see 5.3) or soil moisture?
4. **Hardware check:** Is the minimum runtime reached and the maximum runtime not exceeded?

### 5.3 Water Balance and Dynamic Interval Control

For each zone, the module maintains a continuous water deficit account based on evapotranspiration (ETo) and precipitation.

* **Maximum interval as the check window:** The configured interval defines the maximum number of days until the next check. After it expires, irrigation takes place only if an actual water deficit exists. Precipitation automatically postpones the start.
* **Early trigger when demand is high:** If the accumulated deficit exceeds the *maximum permitted runtime per interval*, the system does not wait until the interval ends. It brings irrigation forward to prevent drought damage.

### 5.4 Wind and Rain Pauses

During active irrigation, the module continuously checks critical weather conditions:

* Wind speed above the configured limit
* Precipitation within short time windows

If a critical condition is met, irrigation is paused or stopped to prevent unnecessary water application.

### 5.5 Additional Functions

* Valve runtime monitoring.
* Automatic resumption after temporary weather pauses.
* State checks when configuration changes.
* Error detection in the instance with activation of status and runtime information.

---

## 6. 🛡️ Error and Safety Logic

The module contains several protective mechanisms for safe operation:

* Error status for missing or invalid configuration.
* Valve runtime monitoring.
* Prevention of parallel or conflicting irrigation processes.
* Pauses during rain and strong wind.
* Prevention of irrigation when the data basis is incomplete.
* Guard mechanisms for maintenance mode and disabled automatic operation.

In case of critical errors, the relevant information is transferred to the instance status so it can be monitored through IP-Symcon.

---

## 7. 📊 Status Variables and Automatic Objects

When the instance is created, the required variables, objects, and timers are created automatically. The exact number and names depend on the configuration.

### 7.1 Variables

| Ident | Typ | Beschreibung | Anmerkung |
| :--- | :--- | :--- | :--- |
| `SystemAutoEnable` | Boolean | Enables or disables automatic irrigation | |
| `SystemMaintenanceEnable` | Boolean | Enables or disables maintenance mode | |
| `AutoIrrigationRunState` | String | Shows the automatic irrigation status | Read-only |
| `LawnCoolingRunState` | String | Shows the lawn cooling status | Read-only |
| `LawnCoolingSkipCurrentRun` | Boolean | Skips lawn cooling | Writable only after push delivery or while cooling is running |
| `ActiveValve` | Integer | Shows the currently active valve | Writable only in maintenance mode |

---

## 8. 💡 Usage Notes

### 8.1 Ideal Conditions

The module is particularly suitable for:

* Residential and allotment gardens
* Lawns with multiple zones
* Beds and shrub areas with defined irrigation logic

### 8.2 Data Quality Requirements

> [!WARNING]
> Irrigation calculation is based on **pure mathematics**. The module processes the supplied parameters strictly logically. If the input data differs from actual conditions, this will inevitably lead to waterlogging or drought stress. The user is responsible for providing valid data.

Control accuracy depends directly on the quality of the input data:

* **Weather data:** Must be updated reliably and promptly.
* **Configuration:** Valves and control variables must be assigned correctly.
* **Sensors:** Must be properly calibrated and provide plausible values.
* **System parameters:** Intervals and irrigation amounts must correspond to actual conditions.

**Recommended tools:**
* **Check weather data:** Set up [Watchdog](https://www.symcon.de/en/service/documentation/module-reference/notifications/watchdog/) to detect weather data failures early.
* **Monitor network status:** Modules such as [DeviceMonitor](https://github.com/Schnittcher/IPS-DeviceMonitor) can continuously monitor the weather station's online availability.
* **Error monitoring:** Use modules such as [LogAnalyzer](https://github.com/BugForgeNerd/LogAnalyzer) to respond specifically to messages from *Garden Irrigation Control*.
* **Proactive alerting:** Set up [notifications](https://www.symcon.de/en/service/documentation/module-reference/notifications/notification/) to be informed immediately of irregularities.

### 8.3 Recommended Practice

* Test the system with trial runs before the first fully automatic operation.
* Validate weather and soil moisture variables.
* Set rain thresholds and wind limits realistically.
* Carefully check sequences and states in large systems or systems with complex zone structures.

---

## 9. ☁️ Configuration Help for Weather Stations and Services

### 9.1 Froggit/Ecowitt Weather Stations

Froggit and Ecowitt weather stations can be integrated into IP-Symcon using the [Froggit](https://github.com/IPSAttain/Froggit) module. The correct units must be configured through the app or web interface.

Assign the variables as follows:

| Assignment in Garden Irrigation Control | Weather station variable ident |
| :--- | :--- |
| Temperature | `tempf` |
| Humidity | `humidity` |
| Rain in mm/h | `rrain_piezo` |
| Rain in mm | `drain_piezo` |
| Absolute air pressure | `baromabsin` |
| Wind speed | `windspeedmph` |

---

## 10. ❓ FAQ

### 10.1 Can an upstream pump, for example for cistern or rainwater, be switched directly through the module?

**No.** The module currently does not directly control upstream pumps or master valves.

However, users can easily implement this in IP-Symcon, for example with a simple **flow plan** or an **event**: The pump is activated as soon as any zone variable changes to `true`.

*Note:* If the community requires native implementation in the module, this feature can be submitted as a feature request in the GitHub repository.

---

## 11. ⚠️ Disclaimer

> [!CAUTION]
> Use of this software is at your **own risk**. The author accepts no liability for damage caused by operating the module or by control malfunctions.

This exclusion applies in particular to:

* **Hardware and property damage:** Water damage caused by valves that do not close, are stuck, or are incorrectly controlled, as well as burst pipes or defective installations.
* **Vegetation damage:** Wilting, death, or damage to plants, lawns, and plantings caused by waterlogging or drought stress.
* **Data and system errors:** Consequential damage caused by incorrect sensor values, failure of external data sources such as weather services, or misconfiguration in IP-Symcon.

The user is responsible for taking suitable protective measures for the hardware installation, such as physical pressure reducers, watchdogs, maximum runtime limits at the actuator level, and regular visual inspections.

---

## 12. 📝 Changelog

A detailed overview of all changes, bug fixes, and new features can be found in [CHANGELOG.md](../CHANGELOG.md).

---

## 13. 🤝 Attribution

## 13.1 Open-Meteo
This module uses the weather API from [Open-Meteo.com](https://open-meteo.com/).

* **Weather Data License:** The data is licensed under the [Creative Commons Attribution 4.0 International (CC BY 4.0)](https://creativecommons.org/licenses/by/4.0/) license.
* **Terms of Use:** Free API usage is limited to non-commercial purposes. Commercial use or high call rates require a paid API key from Open-Meteo.