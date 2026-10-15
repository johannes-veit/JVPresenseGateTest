# P03 – Kamera-Priorisierung und Zweipersonen-Erkennung (15.10.2026)

## Bestand und Abgrenzung

- Bekannte Hauptkameras: **JV_LEFT** und **WORK_LEFT**, beide **IPC-HFW5442E-ZE**, Dahua Firmware **2.840.0000000.28.R** vom **07.08.2024**.
- Im aktuell verwendeten P03-Richtungsnachweis: **JV_LEFT → WORK_LEFT = HOME_TO_LAGER**; umgekehrt = LAGER_TO_HOME. Beide Mast-Kameras sind notwendig, TiOC **JV Terrasse** ist nur Backup. **WORK_LEFT** hat zusätzlich die bestehende Straßen-IVS-Regel.
- **Korrigierter früherer Fehler:** Die exakten TiOC-Modelle waren bereits am 05.10.2026 in früheren Chatangaben bekannt. **JV Terrasse und JV Hof/Garage = Dahua IPC-PDW3849-A180-AS-PV**. Firmware der beiden konkreten TiOC bleibt hier unbekannt; keine neue Abfrage der Kamera.
- TiOC Duo = **zwei 4-MP-Sensoren, zu 180° horizontaler Panoramaansicht zusammengesetzt**, SMD 4.0 und IVS Tripwire/Intrusion mit Menschen-/Fahrzeugklassifizierung, EPTZ zur Vergrößerung/Verfolgung intelligenter Alarme. **Das offizielle Modell-Datenblatt nennt keine eigenständige People-Counting-Funktion.** Breites Sichtfeld kann zwei nebeneinander gehende Personen eher gemeinsam im Bild halten, löst aber **keine zertifizierte Trennung/Zählung von genau zwei Personen** aus. Die tatsächlich sichtbare Treppen- und Straßenstrecke je Kamera muss durch vorhandene Bilder bestätigt werden.
  Herstellerquelle: https://www.dahuasecurity.com/products/network-products/network-cameras/WizSense-Series/TiOC/IPC-PDW3849-A180-AS-PV
- Die bisher gelernte Straßenrichtung ist **RightToLeft = OUT**, **LeftToRight = IN**. Eine gelernte Richtung ist **kein** Live-Straßenübertritt.
- SHADOW ist read-only; WLAN wird separat entwickelt. Niemand wird als zu Hause/abwesend eingetragen; keine Alarm- oder Kamerasteuerung.

## Hersteller-Abgleich

Dahua **IPC-HFW5442E-ZE (WizMind 5)**:
- IVS: Tripwire/Intrusion mit Klassifizierung Human/Vehicle; SMD 3.0.
- People Counting: Tripwire-/Bereichszählung, Queue Management, Report-Ausgabe.
- Offizielle Produktangabe: **drei umschaltbare** intelligente Ressourcen **IVS, Face Detection, People Counting**. Ein Wechsel auf People Counting kann die aktuell benötigten IVS-Grenzüberschreitungen deaktivieren. **Nicht automatisch umschalten.**
  Quelle: https://www.dahuasecurity.com/de/products/All-Products/Network-Cameras/WizMind-8-Series/5-Series/4MP/IPC-HFW5442E-ZE

Installierte TiOC **IPC-PDW3849-A180-AS-PV (JV Terrasse und JV Hof/Garage)**:
- IVS Tripwire/Intrusion mit menschlicher Klassifizierung, SMD 4.0, 180° Bild aus 2 × 4 MP, EPTZ.
- Dahua nennt keine native Tripwire/Area-Personenzählung für dieses Modell. Es bleibt **nur ein Kamera-Hinweis** für gemeinsam erkannte Personen, kein automatischer Zwei-Personen-Nachweis.
  Herstellerquelle: https://www.dahuasecurity.com/products/network-products/network-cameras/WizSense-Series/TiOC/IPC-PDW3849-A180-AS-PV

Personenzähl-Ereignisse laut Dahua CGI HTTP API:
- IVS: `Code=CrossLineDetection;action=Start;index=0;data={...}`, teils mit `ObjectID`, `EventID`, `GroupID`, `Direction`, `ObjectType=Human`. `ObjectID` ist **kameralokal/regelbezogen**, möglicherweise recycelt; `EventID` ist keine Person-ID. Ein Ereignis kann zwei Menschen überlappen. Auch zwei Ereignisse sind nicht zwingend zwei Personen.
- `Code=NumberStat;action=Start;index=0;data={"Number":120,"EnteredNumber":180,"ExitedNumber":60,"Type":"EnterOver"}`. `Number`, `EnteredNumber`, `ExitedNumber` sind **Zählerstände**, `Type` ein Schwellwert-/Regelalarm, **keine** einzeln verifizierte Personenpassage pro empfangenem Event. Auf manchen Firmwares optional `AreaID`.
  API-Referenz: https://pdfcoffee.com/dahua-http-api-v2841-pdf-2-pdf-free.html
- Dahua People Counting erfasst Menschenkörper und produziert statistische Ein-/Ausgänge. Hersteller-Angaben zu typischer Zählgenauigkeit sind **keine Garantie für zwei nebeneinander gehende Personen an unserem Treppen-/Straßenbildwinkel**.
  Quelle: https://www.dahuasecurity.com/sa/products/keyTechnologies/742/132

## Prioritätsregeln (in v0.8.33 SHADOW als Diagnose)

1. **Grundstück/Straße:** primär **WORK_LEFT IPC – Straßen-IVS Human Richtung**, sofern live gültig. Kein einzelnes IVS-Ereignis als Kopfzahl.
2. **Haus/Lager:** **JV_LEFT IPC plus WORK_LEFT IPC** als unabhängiger Richtungsnachweis, nicht als Personenabgleich. **JV Terrasse TiOC-Panoramabild** und gegebenenfalls **JV Hof/Garage TiOC-Panoramabild** können, nur wenn der konkrete Weg sichtbar ist, mehrere gemeinsame Menschen als Zusatzhinweis dokumentieren. Sie sind kein zusätzlicher Personenzähler und keine Voraussetzung für den P03-Hauptrichtungsnachweis.
3. **Genaue Personenanzahl:** nur expliziter **kamera-nativer People-Counting-Betrieb**, mit bekanntem Kameramodell, kalibrierter Linie, sauberem Echtzeit-Zählwert und dokumentierten Zweipersonen-Praxistests. Eine **separate, bislang nicht festgestellte** Kamera (idealerweise freie IPC-HFW5442E-ZE mit geeigneter Sicht auf Grundstücksgrenze) könnte Count-Modus nutzen, während die beiden P03-Mastkameras IVS unverändert weiter betreiben.
4. Solange keine live verifizierte Zählkamera installiert/zugeordnet ist: `individualPeopleCount=UNKNOWN`, `liveStreetExitPersonCount=UNKNOWN`, `propertyEmptyMayBeCertified=false`, `alarmMayBeControlled=false`.

## Live-Signal-Hook

Neuer optionaler, **standardmäßig deaktivierter** Link auf eine *bereits existierende* Symcon String-Variable mit rohen `NumberStat` CGI-Daten (`EnableCountTelemetryRead`, `CountTelemetryVariableID`). Der SHADOW liest ausschließlich diese Variable, keine Kamera und kein Fremdmodul. Er zeigt `numberStatEvidence` als **Schwellwert-Zählerstand**, niemals als einzelne Person oder bestätigten Austritt. Ohne bereits existierende, verifizierte Statusvariable: unverändert auf AUS/ID0 lassen. Weder die beiden IPC-Smart-Pläne noch TiOC-Smart-Pläne werden hier geändert.

## Teststrategie und offene Punkte

1. Ohne Konfigurationsänderung bei normalem Gehen: Test mit 1 Person, 2 Personen mit Abstand, 2 nebeneinander, Gegenrichtungen zeitgleich; CGI-/ONVIF-Ereignisse je Kamera samt `ObjectID`/`EventID` protokollieren; zwischen Kameras keine `ObjectID`-Gleichheit voraussetzen.
2. Vor Personenzähl-Betrieb: exakte Firmware-/Smart-Plan-Lesung der zur Zählung vorgesehenen Kamera **nur lesen**, fotografischer Blickwinkel, nutzbare Linie und Nachtbedingungen prüfen. Keine bestehende P03-Mast-IVS verdrängen.
3. Wenn eine separate IPC für People Counting verfügbar ist: dort kontrolliert testen (kein P03-Kamera-Reset), ob echte Differenz **+2** bei parallelem Verlassen und **+2** bei getrenntem Durchgang aus zuverlässigen Rohdaten gebildet werden kann; erneutes Eintreten/Gegenrichtung und Fehlversuche testen. Bei Überlappungs- und Abschattungsproblemen **keine sichere Personenzahl** behaupten.
4. People Counting liefert dennoch keine Identität. Für Carina/Johannes bleiben Identitätsquellen (separates WLAN-Projekt) und eine wirklich vollständig geprüfte Austrittsabdeckung erforderlich, bevor irgendeine Freigabe für tatsächliche Anwesenheitsbuchungen diskutiert werden könnte.

## Wichtig

Diese Version verifiziert **Dokumentationsfähigkeit und vorsichtige Quellen-Priorität**, nicht die tatsächliche Zuverlässigkeit des parallelen Zwei-Personen-Zählens vor Ort. **Keine Kameraeinstellung** wird ausgelesen oder verändert, um den Smart Plan zu verifizieren. Es gibt keine Fernsteuerung und keine automatisch zugewiesene Zählkamera.
