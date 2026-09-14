# Garden Irrigation Control

**<p>Wählen Sie Ihre Sprache / Choose your language:</p>**
[![Deutsch](https://img.shields.io/badge/Sprache-Deutsch-darkgreen?style=flat&logo=germany)](README.md)
[![English](https://img.shields.io/badge/Language-English-007acc?style=flat&logo=unitedstates)](README.en.md)

Garden Irrigation Control ist eine automatisierte Gartenbewässerung für IP-Symcon. Die Bewässerungsmenge wird dynamisch aus aktuellen Wetterdaten, Standortinformationen und dem bisherigen Verbrauch berechnet – für eine zielgerichtete Versorgung ohne Staunässe oder Trockenstress.

## Inhaltsverzeichnis

1. [Funktionsumfang](#1--funktionsumfang)
2. [Voraussetzungen](#2--voraussetzungen)
3. [Einrichtung in IP-Symcon](#3--einrichtung-in-ip-symcon)
4. [Konfigurationsbereiche](#4--konfigurationsbereiche)
5. [Funktionsweise](#5--funktionsweise)
6. [Fehler- und Sicherheitslogik](#6--fehler-und-sicherheitslogik)
7. [Statusvariablen und automatische Objekte](#7--statusvariablen-und-automatische-objekte)
8. [Hinweise zur Nutzung](#8--hinweise-zur-nutzung)
9. [Konfigurationshilfe von Wetterstationen und Diensten](#9--konfigurationshilfe-von-wetterstationen-und-diensten)
10. [FAQ](#10--faq)
11. [Haftungsausschluss (Disclaimer)](#11--haftungsausschluss-disclaimer)
12. [Changelog](#12--changelog)
13. [Attribution](#13--attribution)

---

## 1. 💧 Funktionsumfang

* **Mathematische Berechnung des Wasserbedarfs:** Ermittlung des potenziellen Wasserverlusts ([Evapotranspiration](https://de.wikipedia.org/wiki/Evapotranspiration)) nach dem Standard der FAO-56 (*modified Penman-Monteith*).
* **Herstellerunabhängig:** Kompatibel mit allen über IP-Symcon steuerbaren Ventilen und Sprinklern, die über Standard-Schaltvariablen (`Boolean`) angesteuert werden.
* **Mehrzonen-Steuerung:** Unabhängige Konfiguration mehrerer Bewässerungskreise.
* **Zielgerichtete Bepflanzungsprofile:** Vordefinierte Parameter für Rasenflächen, Beete/Sträucher sowie Hochbeete.
* **Lokale Wetter- und Prognosedaten:** Einbindung historischer Werte sowie Niederschlags- und Windvorhersagen via `open-meteo.com`.
* **Erweiterte Logikfunktionen:**
  * Optionale Einbindung von Bodenfeuchtesensoren zur erweiterten Steuerung.
  * Integrierte Rasen-Kühlungsfunktion an extrem heißen Tagen.
  * Automatische Aussetzung der Bewässerung bei Regen, Wind oder hoher Regenwahrscheinlichkeit.

---

## 2. ✅ Voraussetzungen

* IP-Symcon ab Version 9.
* Eine lokale Wetterstation oder ein Wetterdienst, der Live-Werte für Temperatur, Luftfeuchtigkeit, Niederschlag, Luftdruck und Windgeschwindigkeit zur Verfügung stellt.
* Ventile oder Sprinkler, die über IP-Symcon als `Boolean`-Schaltvariable ansteuerbar sind.

---

## 3. ⚙️ Einrichtung in IP-Symcon

Unter *Instanz hinzufügen* kann das Modul **Garden Irrigation Control** mithilfe des Schnellfilters gefunden werden.

Weitere Informationen zum Hinzufügen von Instanzen finden sich in der [Dokumentation von IP-Symcon](https://www.symcon.de/de/service/dokumentation/grundlagen/instanzen/#Instanz_hinzuf%C3%BCgen).

---

## 4. 🧭 Konfigurationsbereiche

### 4.1 Standort, Wetter & Sensoren

> [!IMPORTANT]
> * **Einheiten:** Garden Irrigation Control setzt zwingend die angegebenen Einheiten voraus. Abweichende Werte von Ihrer Wetterstation oder Ihrem Wetterdienst müssen anwenderseitig umgerechnet werden, bevor sie an das Modul übergeben werden.
> * **Archivierung:** Bei allen angegebenen Variablen muss die Archivierung mit der Aggregation „Standard“ aktiviert sein.

* `Anlagentyp`: Aktuell stehen *Rasen*, *Beete & Sträucher* sowie *Hochbeete & Kübel* zur Verfügung.
* `Standort`: Der Geostandort, an dem die Bewässerung betrieben wird.
* `Temperatur`: Aktuelle (Live-)Außentemperatur. Einheit: `°C`.
* `Luftfeuchtigkeit`: Aktuelle (Live-)Luftfeuchtigkeit. Einheit: `%`.
* `Regen`: Aktuelle (Live-)Niederschlagswerte. Einheit: `mm/h` je m².
* `Regen (optional)`: Addierte Tageswerte (Tageszähler) der Niederschlagsmenge zur genaueren Berechnung. Einheit: `mm`.
* `Absoluter Luftdruck`: Aktueller (Live-)Luftdruck. Einheit: `hPa`.
* `Windgeschwindigkeit`: Aktuelle (Live-)Windgeschwindigkeit. Einheit: `km/h`.
* `Montagehöhe Windmesser`: Montagehöhe über dem Boden. Bei Nutzung eines externen Wetterdienstes sind `2 m` auszuwählen.
* `Ab welcher Windgeschwindigkeit wird blockiert/gestoppt`: Schwellenwert zur Pausierung der automatischen Bewässerung (z. B. um Verwehung bei Sprinklern zu verhindern).
* `Regenvorhersage`: Bei aktivierter Option ruft das Modul vor dem Start die Wetterprognose von `open-meteo.com` ab. Werden für die nächsten 12 Stunden mindestens `5 mm` Niederschlag mit einer Wahrscheinlichkeit von mindestens `70 %` erwartet, wird die Bewässerung für diesen Tag automatisch ausgesetzt.

> [!TIP]
> Tipps zur passenden Variablenzuordnung für verschiedene Wetterstationen und Wetterdienste finden sich in der [Konfigurationshilfe](#9-konfigurationshilfe-von-wetterstationen-und-diensten).

### 4.2 Durchschnittliche Verdunstung

Die Baseline-ETo beschreibt den durchschnittlichen täglichen Wasserverlust durch Verdunstung und dient als Basis für die Bedarfsberechnung. Garden Irrigation Control ruft diesen Wert automatisch über `open-meteo.com` ab. Für noch genauere Ergebnisse können alternativ Werte aus Archivdaten einer eigenen Wetterstation eingetragen werden.

* `Startmonat`: Monat, ab dem in der Regel mit der Bewässerung begonnen wird.
* `Endmonat`: Monat, an dem die Bewässerungssaison regulär endet.

### 4.3 Berechnung & Laufzeiten

* `max. Intervall zwischen den Bewässerungen`: Legt den maximalen Abstand in Tagen fest, der zwischen zwei Bewässerungen vergehen soll. Ein mehrtägiges Intervall verhindert ein flaches Wurzelwachstum und stärkt die Widerstandsfähigkeit der Pflanzen. Weitere Infos unter [Funktionsweise](#5-funktionsweise).
* `Startzeitpunkt der Bewässerung`: Der Zeitpunkt, zu dem die Bewässerung startet.
* `maximale Laufzeit`: Legt zusammen mit dem Startzeitpunkt fest, wann die Bewässerung spätestens beendet sein muss.
* `Mindestlaufzeit je Zone`: Bei Unterschreitung der Mindestlaufzeit wird die Bewässerung nicht durchgeführt, um kurze Schaltsignale zu vermeiden.
* `Max. Laufzeit-Limit je Zone`: Absolute Begrenzung der Öffnungsdauer eines Ventils (gilt auch bei manueller Steuerung im Wartungsmodus).

### 4.4 Bodenfeuchte

Zur genaueren Beurteilung der Bodenfeuchte können Bodenfeuchtesensoren eingebunden werden – entweder global für die gesamte Anlage oder individuell pro Zone. Bei Nutzung eines globalen Sensors empfiehlt es sich, in der Zonen-Konfiguration diejenige Zone mit Startreihenfolge 1 auszuwählen, in welcher der Sensor tatsächlich verbaut ist.

* `Bodenfeuchtesensor (global)`: Aktuelle (Live-)Bodenfeuchtigkeit. Einheit: `%`.
* `Feuchtigkeits-Grenzwert`: Prozentwert, ab dem die Bewässerung gestoppt wird. Empfehlung für Rasen (Tiefe 5–10 cm): `30–40 %`; (Tiefe 10–15 cm): `55–65 %`.
* `Verhalten`: Auswahl, ob die Bodenfeuchte nur vor dem Start der Bewässerung (Start-Kriterium) oder auch während der laufenden Bewässerung (Start-Stopp-Kriterium) geprüft werden soll.

### 4.5 Rasen-Kühlung

Die Rasenkühlung ist nur beim Anlagentyp *Rasen* und bei aktivierter automatischer Bewässerung verfügbar.  
Ziel ist es, den Rasen und angrenzende Flächen bei extremen Tagestemperaturen kurzzeitig zu beregnen, um Hitzeschäden zu verhindern. Die Kühlung wird nur durchgeführt, sofern es in den letzten 6 Stunden vor dem Start nicht geregnet hat. Die Rasenkühlung sollte nur bei extremer Hitze genutzt werden, da regelmäßige Kurzberegnung flaches Wurzelwachstum fördert.

* `Rasenkühlung aktivieren`: Aktiviert/deaktiviert die Kühlfunktion.
* `Startzeitpunkt der Kühlung`: Der Zeitpunkt, zu dem die Kühlung startet.
* `nur bei Überschreitung von X °C für min. 5 Minuten`: Schwellwert in `°C` für den Start der Kühlung. Die Temperatur muss diesen Wert mindestens 5 Minuten lang ununterbrochen überschreiten.
* `Pushbenachrichtigung versenden`: Aktiviert den Versand einer Pushnachricht 5 Minuten vor Kühlungsstart.
* `Visualisierungsinstanz`: Auswahl der Ziel-Visualisierungsinstanz für Benachrichtigungen (beachte die [Dokumentation von IP-Symcon](https://www.symcon.de/de/service/dokumentation/modulreferenz/kern-instanzen/notification-control/)).

### 4.6 Zonen-Konfiguration

> [!IMPORTANT]
> * **Archivierung:** Bei allen Ventilen muss die Archivierung mit der Aggregation „Standard“ aktiviert sein.

* `Aktiv`: Aktiviert/deaktiviert die Bewässerung dieser Zone.
* `Name`: Freier Name der Zone.
* `Beregnungsleistung`: Niederschlagsmenge in `mm/min` durch die Ventile bzw. Sprinkler.  
  *`Ermittlung`:* Ventil 10 Minuten manuell öffnen, Wasserverbrauch laut Zähler ermitteln. Verbrauch durch bewässerte Fläche (m²) und anschließend durch 10 teilen.
* `Gefälle/Steigung`: Berücksichtigt Hanglagen (höherer Ablauf erfordert angepasste Ausbringung).
* `Ausrichtung`: Berücksichtigt Sonneneinstrahlung und Schattenbereiche.
* `Ventil`: Zuweisung des Schaltelements für diese Zone. Dank der Herstellerunabhängigkeit spielt die Hardware (z. B. KNX, Homematic, Zigbee, Z-Wave, GPIO, Siemens LOGO!, Shelly) keine Rolle. Einzige Voraussetzung ist, dass das Ventil in IP-Symcon als Schaltvariable (Boolean / An-Aus) abgebildet ist. 
* `Bodenfeuchtesensor`: Optionaler zonenbezogener Bodenfeuchtesensor.
* `Globale Bodenfeuchte-Werte verwenden`: Schaltet die Nutzung des globalen Sensors für diese Zone ein/aus.
* `Rasenkühlung aktivieren`: Schaltet die Kühlfunktion für diese Zone ein/aus.

### 4.7 Wartung & Sonstiges

* `automatische Bewässerung aktivieren`: Schaltet die Automatik inklusive Rasenkühlung ein/aus.
* `Wartungsmodus aktivieren`: Beendet die Automatik und ermöglicht das manuelle Schalten der Ventile.

### 4.8 Debug-Werkzeuge (Expertenmodus)

> [!CAUTION]
> Diese Einstellungen dienen ausschließlich der Fehlerdiagnose. Es können irreversible Datenänderungen vorgenommen werden.

* `ETo-Historie neu erstellen`: Berechnet fehlende tägliche ETo-Werte der vergangenen 14 Tage anhand der archivierten Wetterdaten neu.
* `Zonen-Archivdaten neu einlesen`: Löscht die gespeicherte Wasserbilanz-Historie aller Zonen und baut sie anhand der letzten 14 Tage neu auf.
* `Zonen-Archivdaten + Wasserspeicher zurücksetzen`: Liest die archivierten Ventil- und Wetterdaten erneut ein, um die Wasserbilanz zu aktualisieren.
* `Debug-Ausgabe der Attribute`: Gibt interne Attribute im Debug-Fenster aus.
* `Debug-Ausgabe der Regenvorhersage`: Manueller Testlauf der Vorhersage-Schnittstelle (Ausgabe im Debug-Fenster).
* `Test-Pushnachricht versenden`: Verschickt eine Testnachricht an die konfigurierten Geräte.

---

## 5. 🔄 Funktionsweise

### 5.1 Grundprinzip

Das Modul steuert die Bewässerung nicht nach starren Uhrzeiten, sondern über einen dynamischen Regelkreis. Ziel ist eine bedarfsgerechte Wasserabgabe unter Berücksichtigung von:

* **Meteorologie & Bilanz:** ETo-Verdunstung, Niederschlag und Regenvorhersage.
* **Zonen-Profil:** Sonnenexposition, Gefälle und individuelle Bodenfeuchte.
* **Sicherheit & Schutz:** Automatische Stopps bei Wind/Regen sowie Schutz der Hardware.
* **Zeitfenster:** Definierte Betriebszeiten und Prüfintervalle.

### 5.2 Ablauf und Entscheidungsschema

Bei Auslösung prüft das Modul für jede aktive Zone nacheinander folgende Bedingungen:

1. **Sicherheits-Check:** Weht der Wind zu stark oder regnet es aktuell? (siehe 5.4)
2. **Zeitfenster-Check:** Liegt der aktuelle Zeitpunkt im erlaubten Bewässerungsfenster?
3. **Bedarfs-Check:** Besteht gemäß Wasserbilanz (siehe 5.3) oder Bodenfeuchte ein tatsächliches Wasserdefizit?
4. **Hardware-Check:** Wird die Mindestlaufzeit erreicht und die maximale Laufzeit nicht überschritten?

### 5.3 Wasserbilanz und Dynamische Intervall-Steuerung

Das Modul führt für jede Zone ein fortlaufendes Wasserdefizit-Konto auf Basis der Evapotranspiration (ETo) und des Niederschlags.

* **Das maximale Intervall als Prüffenster:**  
  Das eingestellte Intervall definiert den maximalen Abstand in Tagen vor der nächsten Prüfung. Nach Ablauf wird nur gegossen, wenn tatsächlich ein Wasserdefizit besteht. Bei Niederschlag verschiebt sich der Start automatisch.
* **Vorzeitige Auslösung bei hohem Bedarf:**  
  Übersteigt das kumulierte Defizit die *maximal zulässige Laufzeit pro Intervall*, wartet das System nicht bis zum Ende des Intervalls, sondern zieht die Bewässerung vor, um Trockenschäden zu vermeiden.

### 5.4 Wind- und Regenpausen

Während einer aktiven Bewässerung prüft das Modul laufend kritische Wetterbedingungen:

* Windgeschwindigkeit über dem konfigurierten Limit
* Niederschlag innerhalb kurzer Zeitfenster

Ist eine kritische Bedingung erfüllt, wird die Beregnung pausiert bzw. beendet, um unnötige Wasserabgabe zu verhindern.

### 5.5 Zusatzfunktionen

* Laufzeitüberwachung der Ventile.
* Automatische Wiederaufnahme nach temporären Wetter-Pausen.
* Zustandsprüfung bei geänderten Konfigurationen.
* Fehlererkennung in der Instanz mit Aktivierung von Status- und Laufzeitinformationen.

---

## 6. 🛡️ Fehler- und Sicherheitslogik

Das Modul enthält mehrere Schutzmechanismen für einen sicheren Betrieb:

* Fehlerstatus bei fehlender oder fehlerhafter Konfiguration.
* Laufzeitüberwachung der Ventile.
* Verhinderung von parallelen oder widersprüchlichen Bewässerungsabläufen.
* Pausen bei Regen und starkem Wind.
* Vermeidung von Beregnung bei unvollständiger Datenbasis.
* Guard-Mechanismen für Wartungsmodus und deaktivierte Automatik.

Bei kritischen Fehlern werden die relevanten Informationen in den Instanzstatus übernommen, damit diese per IP-Symcon überwacht werden können.

---

## 7. 📊 Statusvariablen und automatische Objekte

Beim Anlegen der Instanz werden automatisch die benötigten Variablen, Objekte und Timer erzeugt. Die genaue Anzahl und Bezeichnung hängt von der Konfiguration ab.

### 7.1 Variablen

| Ident | Typ | Beschreibung | Anmerkung |
| :--- | :--- | :--- | :--- |
| `AutoEnable` | Boolean | Aktiviert/deaktiviert die automatische Bewässerung | |
| `MaintenanceEnable` | Boolean | Aktiviert/deaktiviert den Wartungsmodus | |
| `AutoIrrigationRunState` | String | Zeigt den Status der automatischen Bewässerung | Schreibgeschützt |
| `LawnCoolingRunState` | String | Zeigt den Status der Rasenkühlung | Schreibgeschützt |
| `LawnCoolingSkipCurrentRun` | Boolean | Überspringt/stoppt die aktuelle Rasenkühlung | Schreibrecht nur aktiv nach Push-Versand oder während der Kühlung |
| `ActiveValve` | Integer | Zeigt das aktuell aktive Ventil | Schreibrecht nur im Wartungsmodus aktiv |

---

## 8. 💡 Hinweise zur Nutzung

### 8.1 Ideale Einsatzbedingungen

Das Modul eignet sich besonders für:

* Haus- und Kleingartenanlagen
* Rasenflächen mit Mehrfachzonen
* Beete und Strauchbereiche mit definierter Bewässerungslogik

### 8.2 Qualitätsanforderungen an die Daten

> [!WARNING]
> Die Berechnung zur Bewässerung basiert auf **reiner Mathematik** – das Modul verarbeitet die gelieferten Parameter strikt logisch. Weichen die Eingangsdaten von den realen Gegebenheiten ab, führt dies unweigerlich zu Staunässe oder Trockenstress. Die Verantwortung für die Bereitstellung valider Daten liegt beim Anwender.

Die Genauigkeit der Steuerung hängt direkt von der Qualität der Eingangsdaten ab:

* **Wetterdaten:** Müssen zuverlässig und zeitnah aktualisiert werden.
* **Konfiguration:** Ventile und Steuerungsvariablen müssen korrekt zugewiesen sein.
* **Sensoren:** Müssen ordnungsgemäß kalibriert sein sowie plausible Werte liefern.
* **Anlagen-Parameter:** Intervalle und Beregnungsmengen müssen den tatsächlichen Gegebenheiten entsprechen.

**Empfohlene Tools:**
* **Wetterdaten prüfen:** Richte [Watchdog](https://www.symcon.de/de/service/dokumentation/modulreferenz/benachrichtigungen/watchdog/) ein, um Ausfälle der Wetterdaten frühzeitig zu erkennen.
* **Netzwerkstatus überwachen:** Mit Modulen wie [DeviceMonitor](https://github.com/Schnittcher/IPS-DeviceMonitor) lässt sich die Online-Verfügbarkeit der Wetterstation kontinuierlich kontrollieren.
* **Fehler-Monitoring:** Nutze Module wie [LogAnalyzer](https://github.com/BugForgeNerd/LogAnalyzer), um gezielt auf Meldungen von *Garden Irrigation Control* zu reagieren.
* **Proaktives Alarming:** Richte [Benachrichtigungen](https://www.symcon.de/de/service/dokumentation/modulreferenz/benachrichtigungen/benachrichtigung/) ein, um bei Unregelmäßigkeiten sofort informiert zu werden.

### 8.3 Empfohlene Praxis

* Vor der ersten vollautomatischen Nutzung zunächst mit Testläufen prüfen.
* Wetter- und Bodenfeuchtevariablen validieren.
* Regenschwellen und Windgrenzen realistisch einstellen.
* Bei großen Anlagen oder komplexen Zonenstrukturen die Reihenfolge und Zustände sorgfältig prüfen.

---

## 9. ☁️ Konfigurationshilfe von Wetterstationen und Diensten

### 9.1 Froggit/Ecowitt Wetterstationen

Wetterstationen von Froggit und Ecowitt lassen sich über das Modul [Froggit](https://github.com/IPSAttain/Froggit) in IP-Symcon einbinden. Die korrekten Einheiten sind per App oder über das Webinterface einzustellen.

Die Zuordnung der Variablen hat wie folgt zu erfolgen:

| Zuordnung in Garden Irrigation Control | Ident der Variablen der Wetterstation |
| :--- | :--- |
| Temperatur | `tempf` |
| Luftfeuchtigkeit | `humidity` |
| Regen in mm/h | `rrain_piezo` |
| Regen in mm | `drain_piezo` |
| Absoluter Luftdruck | `baromabsin` |
| Windgeschwindigkeit | `windspeedmph` |

---

## 10. ❓ FAQ

### 10.1 Kann eine vorgeschaltete Pumpe (z. B. für Zisternen- oder Regenwasser) direkt über das Modul mitgeschaltet werden?

**Nein.** Das Modul steuert aktuell keine vorgeschalteten Pumpen oder Hauptventile (Master Valves) direkt an. 

Eine Ansteuerung lässt sich jedoch unkompliziert nutzerseitig in IP-Symcon umsetzen – beispielsweise über einen einfachen **Ablaufplan** oder ein **Ereignis**: Sobald eine beliebige Zonen-Variable auf `true` wechselt, wird die Pumpe aktiviert.

*Hinweis:* Sollte für eine native Implementierung im Modul entsprechender Bedarf in der Community bestehen, kann diese Funktion über einen Feature-Request im GitHub-Repository eingereicht werden.

---

## 11. ⚠️ Haftungsausschluss (Disclaimer)

> [!CAUTION]
> Die Nutzung dieser Software erfolgt auf **eigenes Risiko**. Der Autor übernimmt keinerlei Haftung für Schäden, die durch den Betrieb des Moduls oder Fehlfunktionen der Steuerung entstehen.

Der Ausschluss gilt insbesondere für:

* **Hardware- und Sachschäden:** Wasserschäden durch nicht schließende, hängende oder fehlerhaft angesteuerte Ventile, Rohrbrüche oder defekte Installationen.
* **Vegetationsschäden:** Das Verdorren, Eingehen oder Schädigungen von Pflanzen, Rasenflächen und Bepflanzungen durch Staunässe oder Trockenstress.
* **Daten- und Systemfehler:** Folgeschäden durch fehlerhafte Sensorwerte, Ausfall externer Datenquellen (z. B. Wetterdienste) oder Fehlkonfigurationen in IP-Symcon.

Der Anwender ist selbst dafür verantwortlich, geeignete Schutzmaßnahmen an der Hardware-Installation zu ergreifen (z. B. physische Druckminderer, Watchdogs, maximale Laufzeitbegrenzungen auf Aktor-Ebene und regelmäßige Sichtprüfungen).

---

## 12. 📝 Changelog

Eine detaillierte Übersicht aller Änderungen, Fehlerbehebungen und neuen Features findest du in der [CHANGELOG.md](../CHANGELOG.md).

---

## 13. 🤝 Attribution

## 13.1 Open-Meteo
Dieses Modul nutzt die Wetter-API von [Open-Meteo.com](https://open-meteo.com/).

* **Lizenz der Wetterdaten:** Die Daten stehen unter der Lizenz [Creative Commons Attribution 4.0 International (CC BY 4.0)](https://creativecommons.org/licenses/by/4.0/).
* **Nutzungsbedingungen:** Die kostenfreie API-Nutzung ist auf nicht-kommerzielle Zwecke beschränkt. Für kommerzielle Nutzung oder hohe Abfrageraten ist ein kostenpflichtiger API-Key von Open-Meteo erforderlich.