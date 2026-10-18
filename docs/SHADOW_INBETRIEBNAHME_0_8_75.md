# SHADOW v0.8.75 – Einmalige Inbetriebnahme und Abnahme nach Entwicklung

## Geltungsbereich

- Bereits laufende IP-Symcon-9-SymBox, **bestehende** Instanz `JVPresenceIdentityShadow #32969`
- Bestehende P03-Instanz `#53879`; bestehende Ausgabestringvariable `Testergebnis an ChatGPT #40890`
- Nur lesen, **kein** Schreiben in P03, LCN, Alarm, Schiebetor, Kameras, WLAN-Johannes, WLAN-Carina oder Geofency
- Weiterhin genau **sieben SHADOW-Ausgabevariablen**; keine Anlage von Sensor-/Aktorobjekten

## Vor der Aktualisierung

Alle alten Zwischenversionen v0.8.70–0.8.74 koennen uebersprungen werden: direkt auf **v0.8.75 Build 156** aktualisieren. Bestehende Modulinstanz beibehalten; **nicht** loeschen oder neu erstellen. Die bisherigen Beobachtungseinstellungen und persistierten SHADOW-Attribute beibehalten. Weder Geofency-/EgiGeoZone-IDs noch Kamera-/P03-Einstellungen vorsorglich aendern. Bei einem Modulstatus 201 nach dem Update zuerst den Testbericht lesen, **keine** gespeicherten `Persist...`-Attribute manuell loeschen: die Update-Schutzpruefung haelt einen Beschaedigungsbefund fest.

## Einmalige Abnahme aus genau einer Variable

Nach dem Update `#40890` einmal vollstaendig kopieren:

1. `Schema 1 | SHADOW v0.8.75 Build 156`
2. `UPGRADE-SPEICHERPRUEFUNG`: `EXISTING_STATE_VALIDATED` oder `FIRST_RUN_BASELINE` ohne kritischen Fehler; alte Ereignisse und P03-Warteschlange nicht ueberschrieben
3. `SELBSTPRUEFUNG`: `CONSISTENT` oder konkrete `REVIEW_REQUIRED`-Codes
4. `QUELLENBINDUNG` je Smartphone: Heim-WLAN-/Geofence-Objekte geprueft; kein `SOURCE_IDENTITY_COLLISION`, kein fehlgedeuteter aktueller MAC-Kontakt
5. `P03 PROTOKOLL-KONTINUITAET`: bei P03 bewusst AUS keine neuen Zwei-Mast-Beweise erwarten. `LIVE_RX` der Observer ist **nicht** gleich Test-/Produktionsfreigabe
6. `MEILENSTEIN QUELLENQUALIFIKATION`: nennt vorhandene und ausstehende Quellen, insbesondere genaue Firmen-SSID und vorhandenen Fahrzeugkontakt
7. `ENDPRUEFUNG HAUS` / `ENDPRUEFUNG AUTO/FUSSWEG`: erklaert positive Hinweise sowie weiterhin fehlende physische Identitaets-, Fahrzeugrichtungs- und Haus-leer-Beweise
8. `ABWESENHEIT-AKTIONEN / ALARMFREIGABE: NEIN`

Bei einem Text oberhalb 13 kB muessen trotz Kuerzung die Schlussbeweise und die Alarm-Sperrzeile erhalten bleiben.

## Nur falls die automatische Diagnose noch etwas verlangt

- **Johannes / Carina Firmen-WLAN:** Ein aktueller, **persoenlicher** direkt vom jeweiligen Smartphone gemeldeter SSID-String mit gueltigem Updatezeitpunkt ist erforderlich. Kein WLAN-Ping/Unifi-AP-Name/Geofence ersetzt diese personenbezogene Quelle. Die bestehenden SSIDs `Intern-Veit`/`Privat-Veit` werden nur nach exakt verifiziertem Geraet und Zeitfenster beruecksichtigt; `Privat-Veit-EXT` ist wegen ueberlappender Funkbereiche mehrdeutig. Keine Netzwerk-/Firewall-/Controller-Eingriffe.
- **Auto-/Fussweg:** Die bisherigen P03-Zweimastkameras liefern anonymes Human-OUT/IN. Vor einem Fahrzeug-/Fahrerbefund fehlt ein verifizierter, zeitlich richtiger Fahrzeugklassifizierungs-/Richtungssensor und ein unabhaengiger Personenbezug. Eine aktive Fahrzeug-Boolean, ein Schiebetor und P03-Straßen-Human/NumberStat belegen keine individuelle Autofahrt.
- **Haus leer:** Die 15 LCN-GUS melden Bewegung, nicht Personenzahl und nicht alle moeglichen Gaeste. `WLAN=false` oder WLAN-Pingstille bestaetigt weder Personenabwesenheit noch ein leeres Grundstueck. **Keine** Freigabe der Alarmanlage durch das SHADOW-Modul.

## Fertigstellungskriterien

**Code-Seite:** gesamte GitHub-PHP-Regressionssuite, P03-CI, Update-Preflight-/Simulations-/Kuerzungstests erfolgreich, sieben Ausgabevariablen, keine Schreibzugriffe auf Fremdmodule.

**SymBox-Seite (noch offen):** erste reale Installation auf derselben Instanz, beobachteter 60-s-Timer plus aktualisierte GUS-Meldungen, verifizierte Smartphone-Updates und Kontrolle der einzigen Kopiervariable. Das ist keine Bitte um einen neuen Geh-/Fahrtest vor der Installation. Erst aus konkreten verbleibenden Diagnoseluecken ergibt sich, ob gezieltes Nachtesten fachlich notwendig ist.
