# WLAN-Anbindung an vorhandene UniFi-Access-Points – v0.8.16

## Stand und Ausführungsgrenze

Die vorhandenen UniFi-APs (laut Netzwerkschaubild Büro, A-Raum, Werkstatt, Garage, JV Keller/EG/OG) sind als mögliche Datenquellen bekannt. Ihr Management liegt laut Schaubild im Bereich `192.168.107.x`; SymBox `192.168.103.59`. Die **genauen Management-IP-Adressen der APs, die Erreichbarkeit von VLAN 103 und die AP-Firmware sind nicht bestätigt**. Es gibt laut Nutzer keine bekannte UniFi-Zentrale.

Diese Änderung stellt einen **fertigen, lokal ausführbaren Snapshot-Ingest und MAC-Matcher**, aber **noch keinen fertig konfigurierten AP-Transport** bereit. Das Modul versucht ausdrücklich weder SSH noch HTTP, SNMP, Ping oder Netzwerk-Scans. Es startet keine weiteren Symcon-Sockets und legt keine neuen Symcon-Variablen an.

Ein späterer, autorisierter, *lesender* lokaler AP-Adapter kann eine frische `mca-dump`-Antwort vom AP übergeben. Das ist eine von UniFi-Community-Nutzern dokumentierte Quelle für `vap_table[].sta_table[].mac`, **nicht** eine garantiert auf allen Firmwareständen vorhandene oder offizielle API.

## APs konfigurieren

1. Im `JV Presence Phone Observer` unter `UniFi AP-Quellen` die gewünschten APs mit stabilen IDs anlegen, z. B. `buero`, `werkstatt`, `garage`. Die AP-ID ist nur ein selbstgewählter Name, **keine erfundene IP**.
2. Für jedes Smartphone bei `MAC` die tatsächlich am relevanten WLAN-Profil verwendete MAC eintragen. Android/iOS können private, aber innerhalb eines WLAN-Profils stabile Adressen verwenden und diese später wechseln.
3. Ist irgendwann ein lesender AP-Transport bestätigt und eingerichtet, übergibt dieser ein **vollständiges und frisches** `mca-dump`-JSON über die Modul-Funktion `JVPHONE_ImportUniFiAPSnapshot(<InstanzID>,'buero',<JSON-String>)`. Nie ein ungeprüftes altes Cache-JSON als neuen Poll einspielen.
4. Wiederholung nur mit moderater Rate, nach AP und Datenbedarf, **vorläufiges Ziel höchstens alle 120–180 Sekunden pro AP**, später ggf. bedarfsgesteuert. Das Modul selbst erzeugt **0 AP-Netzwerkabfragen**.

## Format

Das JSON muss ein **Objekt** mit kompletter `vap_table` enthalten. Jeder VAP muss eine `sta_table` besitzen. Beispielsweise:

```json
{"vap_table":[{"sta_table":[{"mac":"92:aa:bb:cc:dd:01"}]}]}
```

Diese MAC ist ausschließlich ein synthetisches Testbeispiel, keine echte Geräteadresse. Sonstige Geräte in `sta_table` werden bei der Verarbeitung sofort verworfen; im Symcon-Modulbuffer verbleiben nur **bereits registrierte Geräte-IDs** und Zeitpunkt/Erfolg je AP, keine unbekannten WLAN-Clients.

Fehlt die Tabelle, ist die Antwort unvollständig oder größer als 256 KiB, wird der AP-Snapshot verworfen. Der zuletzt gespeicherte Zustand dieses APs wird **ungültig**; andere unabhängige APs bleiben verfügbar. Nicht beobachtete Smartphones sind weiterhin **UNKNOWN**, niemals automatisch abwesend.

Jedes komplette AP-Snapshot wird spätestens nach `APSnapshotFreshSeconds` (Standard 180 s) ungültig. Weder fehlende AP-Verbindung, ausgeschaltetes Telefon noch fehlende Stationseinträge erlauben eine Personen-AWAY-Buchung. Positive Beobachtungen sind nur **Telefon am WLAN**, nicht **Bewohner im Haus**.

## Ressourcenregeln

- Beliebig erweiterbare Personen- und Geräteliste.
- **Konstant zwei Symcon-Variablen** für das gesamte Modul; keine Variable je AP, Smartphone oder Bewohner.
- Die bereits vorhandene 60-Sekunden-Prüfung liest nur lokale Werte und AP-Buffer.
- Ausgaben nur bei Änderungen. Keine Kamera-/Video-/Dahua-CGI-Zugriffe.
- Ein eintreffender AP-Snapshot wird ein einziges Mal lokal ausgewertet. Identische Ergebnisse führen zu keinen neuen Statusvariablen-Schreibvorgängen.
- P03 und P05 behalten die physische Personengrenzen-Erkennung und werden nicht verändert.

## Was für den tatsächlichen Anschluss noch gebraucht wird

1. Von einem UniFi-AP **exakte IP-Adresse**, Geräte-Modell/Firmware und Verwaltungsart (Standalone-/UniFi-Network-Adoption).
2. Nur lesender Zugriff von der SymBox oder einer bereits im Netz verfügbaren Instanz ohne neue Zugangsdaten in öffentlichem GitHub. Firewallfreigaben müssen von der zuständigen IT geprüft werden.
3. Ein einziger kontrollierter Original-Snapshot des APs zur Prüfung der **tatsächlichen** Firmware-Feldstruktur, bevor ein Leser automatisiert wird.

Bis dahin bleiben die neuen AP-Quellen korrekt auf `PHONE_UNKNOWN`. Weder eine Verbindung noch eine vollständige Integration wird behauptet.

## v0.8.17 – Null-Traffic-Inventur für neun AP-Positionen

Die Zeichnungen vom 29.08.2026 zeigen neun vorhandene AP-Positionen: Büro, A-Raum, UniFi Flex/Lagerplatz, zweimal Werkstatt, Garage JV, JV Keller, JV EG und JV OG. Die AP-Beschriftungen lauten im Detail **192.168.107.x** bzw. für Garage JV **192.168.103.x**. Die echten Endadressen sind **nicht** in den verfügbaren Zeichnungen enthalten. Kameras, NVR, SymBox und einige Switches sind im Gegensatz dazu vollständig adressiert.

**Neuer Button** im `JV Presence Phone Observer`:
`UniFi-AP-Inventur aus vorhandenen Symcon-Objekten (NUR LOKAL LESEN)`.

- Er liest ausschließlich lokale Objektbezeichnungen `IPS_GetName` und bei passenden Namen die gespeicherte Konfiguration `IPS_GetConfiguration` zum Zweck der Anzeige echter, bereits lokal dokumentierter AP-IP-Kandidaten.
- Es wird ein streng begrenzter Satz unkritischer Top-Level-Felder `Host`, `Hostname`, `IPAddress`, `IP`, `Address`, `DeviceIP` akzeptiert, jeweils nur wenn der Wert eine **gültige reine IPv4-Adresse** ist. Zugangsdaten, Tokens, URIs, private Schlüssel und restliche Konfigurationen werden **nie** ausgegeben.
- Kein Netzwerkscan, kein Ping, kein SSH, kein HTTP, keine Firewallabfrage; insbesondere **keine AP-, Switch-, WLAN-, VLAN- oder Kameraänderungen**.
- Der Bericht steht nach dem Button-Klick im neu geöffneten Instanz-Konfigurationsformular (Label aus internem Puffer), **nicht** in einer neuen Symcon-Variable.
- Kandidaten aus lokalen Symcon-Objekten werden als unbestätigt markiert. Nicht belegte AP-Adressen bleiben **unbekannt**. Falls kein passendes Objekt existiert, ist das ein korrektes Ergebnis und kein Anlass für einen Blindscan.
- Unverändert: maximal zwei Ausgabewerte im Modul, kein neues Netzwerksocket, P03/P05 unverändert.

Zur Nachprüfung nach Modul-Update die Telefon-Observer-Instanz öffnen, neuen Button drücken und **danach die Instanzkonfiguration neu öffnen**. Das lokale Ergebnis kann dann ohne geheime Daten in den Chat kopiert werden. Die Diagnose bewirkt nur ein einmaliges Auslesen bestehender Symcon-Metadaten. Ein AP-Transport wird dadurch noch nicht behauptet.
