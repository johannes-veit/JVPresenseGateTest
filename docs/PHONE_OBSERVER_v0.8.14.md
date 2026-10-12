# Smartphone-/WLAN-Beobachtung – v0.8.14

## Installation

1. Das Repo auf **v0.8.14 Build 95** aktualisieren.
2. In IP-Symcon unter **Instanz hinzufügen** das Modul `JV Presence Phone Observer` anlegen.
3. Personenprofile `johannes` / `carina` werden beim ersten Anlegen erzeugt.
4. Geräte `johannes_phone` (Xiaomi 13T) und `carina_phone` (iPhone) sind vorbereitete Profile.
5. Ohne verfügbare Symcon-Quellvariablen bleiben die Eingänge auf **0**. Das Modul zeigt `PHONE_UNKNOWN` und nimmt **keine Anwesenheitsbuchungen** vor.

**Wichtig:** Diese Version liest keinen UniFi-AP selbst aus. Ein UniFi-Controller ist NICHT vorausgesetzt; wir müssen zuerst ermitteln, wie die vorhandenen APs verwaltet werden, welche der im korrigierten Netzwerkschaubild (29.08.2026) als 192.168.107.x gekennzeichneten AP-Management-IP-Adressen von der SymBox 192.168.103.59 aus erreichbar ist und ob ein nur-lesender Clientstatus via lokaler AP-Schnittstelle bereitgestellt werden kann. Keine neuen Firewallregeln oder Routeränderungen durch das Modul.

## Eingangsvariablen

Pro Smartphone optional:

- `WiFiVarID` (Boolean): **true** genau dann, wenn das Telefon aktuell an einem zugehörigen WLAN-AP *assoziiert* ist, **nicht** wenn ein IP-Ping erfolgreich war. Der Quellenadapter muss auch im unveränderten Zustand mindestens innerhalb von `WiFiFreshSeconds` aktiv aktualisieren. Default **180 s**. Kann die Quelle dies nicht, ist der Status nach 180 s absichtlich unbekannt.
- `GeoVarID` (String): `PROPERTY` / `OUTSIDE` / `UNKNOWN`, aus einem unabhängigen, später zu konfigurierenden Geofence-Ereignisadapter; ebenfalls mit Frischeprüfung (Default **900 s**). Keine Google-/Apple-Standardfreigabe wird als vorhandene Symcon-API unterstellt.
- `MAC` (optional): Bei privaten WLAN-Adressen die vom jeweiligen SSID-Profil tatsächlich verwendete MAC; eine neue WLAN-Verbindung oder ein Gerätewechsel kann die Adresse ändern. **Kein Personennachweis.**

Nicht vorhandene, falsch typisierte, nicht regelmäßig aktualisierte oder widersprüchliche Variablen werden nicht durch Pings oder Namensannahmen ersetzt.

## Smartphone ersetzen

1. Die vorhandene Gerätezeile deaktivieren (`Enabled=false`).
2. Neue Zeile mit neuer Geräte-ID und gleichem festen `PersonID` anlegen.
3. Optional neue WLAN- und Standortquellvariablen sowie WLAN-MAC zuordnen.
4. **Übernehmen** und `Smartphone-Nachweise jetzt prüfen` drücken.

Personen-ID, deren übrige PresenceCore-Historie sowie P03/P05-Konfiguration bleiben unverändert. Eine deaktivierte alte Gerätezeile darf dieselben Quellen/MAC wie das neue aktive Gerät haben. Doppelte *aktive* Quellen/MACs und doppelte Geräte-IDs werden gesperrt.

## Sicherheitsvertrag

- WLAN-CONNECTED/GPS-PROPERTY => nur `PHONE_PROPERTY_INDICATION` (Telefonstandort).
- WLAN-NOT_CONNECTED => `PHONE_UNKNOWN`, nie Person-AWAY.
- GPS-OUTSIDE => `PHONE_OUTSIDE_INDICATION`, nie Person-AWAY.
- Gleicher Telefonstandort von WLAN und GPS widersprüchlich => `PHONE_CONFLICT`.
- Zwei Geräte derselben Person mit unterschiedlichen Telefonstandorten => `PHONE_CONFLICT`.
- Kein Source-Heartbeat/alter Wert => `STALE` und `PHONE_UNKNOWN`.
- JSON enthält `personLocation=UNKNOWN`, `personPresenceMayBeChanged=false` und `propertyEmptyMayBeCertified=false`.
- Keine direkte Verbindung zu einem WLAN-AP, kein GET/POST/SSH, keine Kamerakonfig-/P03-Änderung, kein Zugriff auf bestehende Anwesenheitszertifikate.

## Ausgänge und Tests

Nur zwei Symcon-String-Variablen:
- `Status`: kompakte Übersicht über Johannes und Carina.
- `EvidenceJSON`: maschinenlesbare Version-1-Telefonbeobachtungen, später für den PresenceCore.

Button `Sicherheits-Simulation` testet die Regeln ohne Fremdmodule zu beeinflussen. Automatische GitHub-PHP-Tests prüfen zusätzlich Device-Swap, WLAN-Schlafmodus, widersprüchliche GPS-Daten, falsche Variabletypen und die Modulregistrierung.

## Noch benötigte Netzwerkdaten

Für die nächste Ausbaustufe die **exakte Management-IP (letztes Oktett) eines vorhandenen UniFi-APs** und – falls bekannt – den AP-Typ nennen. Auf keinen Fall Zugangsdaten ins Chat-Protokoll schreiben. Nach bestätigter VLAN-Erreichbarkeit können wir einen **lesenden** Clientstatus-Adapter hinzufügen. Die bisherige Kamera-Logik ist davon vollständig unabhängig.

## Ressourcenregeln ab v0.8.15

- **Beliebig viele Hausbewohner** in der vorhandenen `People`-Liste (`+`): Name + stabile `PersonID`, kein Smartphone notwendig. Das Gerätefeld verwendet dieselbe PersonID; eine Person kann mehrere Geräte besitzen. Testdeckel: 32 Hausbewohner simuliert, nicht hardcodiert.
- **Konstant zwei Symcon-Variablen pro Modulinstanz**, nicht pro Hausbewohner oder Gerät. Auch 30 zusätzliche Bewohner erzeugen keine weiteren Variablen.
- Der vorhandene **60-Sekunden-Timer liest ausschließlich schon vorhandene lokale Symcon-Variablen**. Er erzeugt keinen WLAN- oder Kamera-Netzwerk-Traffic und keinen AP-/Controller-Poll.
- Die beiden Ergebnisvariablen werden **nur noch geschrieben, wenn ihr semantischer Zustand sich ändert** (einschließlich eines frischen/abgelaufenen Quellsignals und Konfigurationsfehler). Gleichbleibende Zustände erzeugen auch über 100 wiederholte Abfragen keine Variablen-Updates.
- `EvidenceJSON.time` bezeichnet bei diesen optimierten Änderungsbenachrichtigungen **den Zeitpunkt der letzten Zustandsänderung**, nicht den Zeitpunkt des jüngsten lokalen 60-Sekunden-Timerlaufs. Ein unveränderter Zeitstempel **ist ausdrücklich kein Kommunikationsausfall**.
- Die kompakte Statusvariable zeigt bis zu fünf Profile direkt; der Rest steht vollständig in `EvidenceJSON`.
- Das P03/P05-Kameramodul bleibt unverändert; keine zusätzlichen Kamerasockets, CGI-Dumps, RTSP-Streams oder Hintergrundabfragen.
