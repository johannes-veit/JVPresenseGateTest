# JV Phone WiFi Bridge – Android / iPhone / Symcon 9.0

**Separater, optionaler und manueller WebHook-Empfänger.** Keine Änderung an P03, LCN, UniFi, Firmen-Firewall, EgiGeoZone, Geofency oder bestehenden WLAN-Skripten. SHADOW v0.8.51 hat bereits die Opt-in-Auswertung für `Intern-Veit` und `Privat-Veit`; diese Bridge versorgt die noch fehlenden beiden personalisierten `WifiSSID`-String-Quellen.

## Symcon einmalig

1. Im Objektbaum außerhalb fremder Module ein neues **PHP-Skript** `JV Smartphone WLAN Webhook` anlegen.
2. **Den gesamten Inhalt von `JVPhoneWiFiWebhook.php`** in dieses Skript einfügen, speichern und **einmal manuell ausführen**.
3. Das Skript legt genau zwei neue String-Werte `WifiSSID` unter den Kategorien `Johannes Smartphone SSID Quelle` und `Carina Smartphone SSID Quelle` sowie zwei verborgene persönliche Token an. Wiederholte Ausführung ist idempotent und wechselt keine Token. Vier IDs notieren: jeweils **Eltern-ID und WifiSSID-ID**. Geheim-Token **niemals** in Chats, Screenshots oder Repositories veröffentlichen.
4. In **Kerninstanzen → WebHook Control** **einen** neuen Hook `/hook/jvphonewifi` hinzufügen und auf **das neue PHP-Skript** zeigen lassen. Bereits vorhandene `/hook/egigeozone` und `/hook/geofency` nicht ändern. Bei einem gleichnamigen vorhandenen Hook nicht überschreiben.
5. In **JV Presence Identity SHADOW #32969** (v0.8.51) im Abschnitt WORK die erzeugten vier IDs bei `JohannesSSIDVariableID`, `JohannesSSIDParentID`, `CarinaSSIDVariableID`, `CarinaSSIDParentID` hinterlegen. `EnableWorkWiFiHints` **erst nach einem erfolgreichen Endgerätetest** aktivieren, dann übernehmen.

## HTTPS-Nachrichten

Die URL über **deine eigene, bereits funktionierende Symcon-Connect-IP-Magic-Adresse** lautet:

`https://DEINE-ADRESSE.ipmagic.de/hook/jvphonewifi`

Keine neue Firewallregel und keine neuen Ports. **HTTP POST**, `Content-Type: application/json`. Payloads (Token lokal vom Symcon-Skript ablesen):

```json
{"person":"johannes","ssid":"Intern-Veit","token":"<GEHEIMER_JOHANNES_TOKEN>"}
```

```json
{"person":"carina","ssid":"Privat-Veit","token":"<GEHEIMER_CARINA_TOKEN>"}
```

Das Feld `ssid` muss aus dem **tatsächlich aktuell verbundenen WLAN** des jeweiligen Telefons kommen; nicht ein fest angenommener Netzwerkname! Beim Trennen `"ssid":""`. Erwartete Antwort bei gültiger authentifizierter Nachricht: `{"ok":true,"result":"ACCEPTED"}`.

## Johannes: Xiaomi 13T – Automate (gratis, keine 7-Tage-Testversion)

[Automate](https://llamalab.com/automate/) von LlamaLab ist kostenlos für Flows mit maximal 30 gleichzeitig laufenden Blöcken: [Herstellerinformationen](https://llamalab.com/automate/doc/premium.html). Der Flow benötigt deutlich weniger.

Flow-Schema mit `Flow beginning`, `Wi-Fi network connected`, `HTTP request`, `Delay`:

1. `Wi-Fi network connected`: **Proceed = Immediately**, Netzwerkname/Netzwerkadresse frei lassen, Ausgabe `Network name` in Variable `ssid`. Bei YES tatsächliches `ssid` senden; bei NO `ssid=""` setzen.
2. `HTTP request`: URL oben, Methode POST, Content-Type `application/json`, Body-Dictionary mit `person="johannes"`, `ssid=Variable ssid` und **persönlichem Token**. Antwortstatus 200 prüfen.
3. `Delay 600` Sekunden → zum WLAN-Prüfblock zurück. Der SHADOW-WORK-Status wird nach **900 Sekunden ohne neue Meldung veraltet**; deshalb ist eine einmalige Verbindungsautomation nicht ausreichend.
4. Android-Standortfreigabe kann für das Auslesen der SSID notwendig sein; Hintergrundausführung/Akkuoptimierung auf Xiaomi prüfen. WLAN nur lesen, niemals mit Automate automatisch ein-/ausschalten.

Referenzen: [WLAN lesen](https://llamalab.com/automate/doc/block/wifi_network_connected.html) und [HTTPS-POST](https://llamalab.com/automate/doc/block/http_request.html).

## Carina: iPhone – Kurzbefehle ohne Zusatz-App

In der App **Kurzbefehle → Automation → WLAN** eine Automation beim Verbinden mit den Firmen-WLANs und der HOME-SSID anlegen. Aktion **Netzwerkdetails abrufen** (WLAN-Netzwerkname) → **Inhalt von URL abrufen**, Methode POST, Body-Typ JSON mit `person="carina"`, `ssid` aus *Netzwerkdetails* und Carinas geheimem Token. Automatische Ausführung aktivieren. Die SSID beim Senden **live abfragen**, nicht hart codieren.

**Wesentliche Einschränkung:** Der iPhone-WLAN-Trigger liefert primär Verbindungsereignisse, **keine automatisch fortlaufende 10-Minuten-Heartbeat-Meldung**. Deshalb wird dieser WORK-SSID-Nachweis nach 15 Minuten ohne neue Aktualisierung als **nicht mehr aktuell** bewertet. Dies ist beabsichtigter Schutz vor falscher dauerhaft angezeigter WORK-Anwesenheit. Für eine wirklich ganztägig bestätigte Carina-WORK-Anzeige brauchen wir eine ergänzende, zuverlässig wiederkehrende iPhone-/Controller-Datenquelle.

[Apple WLAN-Automationen](https://support.apple.com/de-de/guide/shortcuts/apde31e9638b/ios).

## Datenregeln / Sicherheit

- `Intern-Veit` und `Privat-Veit` = WORK-Smartphone-Kontext (VLAN 2); `Privat-Veit-EXT` reicht zum Wohnbereich und ist **NICHT** eindeutig WORK; `Veit-FilzenerWeg`/`filzenerweg` = HOME.
- Der alte 150-m-Geofence umfasst Firma und Zuhause und kann beide nicht unterscheiden.
- Das Symcon-Empfangsskript speichert **nur die aktuelle SSID** pro Telefon, keine GPS-Daten, BSSID, Passwörter oder Token im SHADOW-JSON. Zwei persönliche Zufalls-Token schützen die HTTPS-POST-Aufrufe.
- WORK-Hinweise von Telefonen beweisen **nicht** automatisch, dass niemand im Haus ist. Die positiven unabhängigen 15 LCN-GUS/P03-Hauseintrittsnachweise bleiben bestehen. Keine automatischen HOME/AWAY- oder Alarmaktionen.
