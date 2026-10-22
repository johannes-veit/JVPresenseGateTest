# JV Phone WiFi Bridge – Android / iPhone / Symcon 9.0

**Separater, optionaler und einmalig manuell startbarer WebHook-Empfänger mit automatischer konfliktgeschützter Hook-Zuordnung.** Keine Änderung an P03, LCN, UniFi, Firmen-Firewall, EgiGeoZone, Geofency oder bestehenden WLAN-Skripten. SHADOW v0.8.111 wertet persönlich verifizierte SSID-Quellen automatisch und einheitlich aus für `Intern-Veit` und `Privat-Veit`; diese Bridge versorgt die noch fehlenden beiden personalisierten `WifiSSID`-String-Quellen.

## Symcon einmalig

1. Im Objektbaum außerhalb fremder Module ein neues **PHP-Skript** `JV Smartphone WLAN Webhook` anlegen.
2. **Den gesamten Inhalt von `JVPhoneWiFiWebhook.php`** in dieses Skript einfügen, speichern und **einmal manuell ausführen**.
3. Das Skript legt genau zwei neue String-Werte `WifiSSID` unter den Kategorien `Johannes Smartphone SSID Quelle` und `Carina Smartphone SSID Quelle` sowie zwei verborgene persönliche Token an. Wiederholte Ausführung ist idempotent und wechselt keine Token. Die IDs werden in der neuen SHADOW-Version automatisch erkannt, **kein manuelles Verknüpfen erforderlich**. Geheim-Token **niemals** in Chats, Screenshots oder Repositories veröffentlichen.
4. Der Installer prüft **WebHook Control** und registriert `/hook/jvphonewifi` automatisch für genau dieses Skript, sofern der Pfad frei ist. Kontrolle in der lokalen Skript-Ausgabe: `WEBHOOK: AUTOMATISCH_EINGERICHTET` oder `BEREITS_KORREKT`. Nur bei `WEBHOOK: MANUELL_NOETIG` den neuen Pfad unter **Kerninstanzen → WebHook Control** manuell diesem Skript zuordnen. Vorhandene `/hook/egigeozone`, `/hook/geofency` und Schiebetor-Hooks dürfen nicht verändert werden. Zeigt der gewünschte Pfad schon auf ein anderes Skript, wird die Installation **vor dem Anlegen neuer Objekte verweigert**.
5. In **JV Presence Identity SHADOW #32969** (v0.8.111) sind die vorhandenen Variablen automatisch nach spätestens fünf Minuten im gleichen Herkunftsfilter sichtbar. Die vier alten manuellen Zuordnungsfelder und der manuelle Schalter `EnableWorkWiFiHints` werden für **diesen Bridge-Weg nicht mehr benötigt**. Sie bleiben optional für abweichende, bewusst manuell verknüpfte Quellen. **Wichtig:** Ein Hook ohne echte, wiederholte Meldungen vom Smartphone beweist kein WORK.

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
3. `Delay 240` Sekunden → zum WLAN-Prüfblock zurück. Die konkrete Bewohner-Kachel bewertet persönliche WORK-SSID-Hinweise nur in den ersten **300 Sekunden** als aktuell; die rohe Quellenprüfung verwirft Werte spätestens nach 900 Sekunden. Einmalige Verbindungsautomationen reichen deshalb nicht für kontinuierliche WORK-Anzeige.
4. Android-Standortfreigabe kann für das Auslesen der SSID notwendig sein; Hintergrundausführung/Akkuoptimierung auf Xiaomi prüfen. WLAN nur lesen, niemals mit Automate automatisch ein-/ausschalten.

Referenzen: [WLAN lesen](https://llamalab.com/automate/doc/block/wifi_network_connected.html) und [HTTPS-POST](https://llamalab.com/automate/doc/block/http_request.html).

## Carina: iPhone – Kurzbefehle ohne Zusatz-App

In der App **Kurzbefehle → Automation → WLAN** eine Automation beim Verbinden mit den Firmen-WLANs und der HOME-SSID anlegen. Aktion **Netzwerkdetails abrufen** (WLAN-Netzwerkname) → **Inhalt von URL abrufen**, Methode POST, Body-Typ JSON mit `person="carina"`, `ssid` aus *Netzwerkdetails* und Carinas geheimem Token. Automatische Ausführung aktivieren. Die SSID beim Senden **live abfragen**, nicht hart codieren.

**Wesentliche Einschränkung:** Der iPhone-WLAN-Trigger liefert primär Verbindungsereignisse, **keine automatisch fortlaufende 10-Minuten-Heartbeat-Meldung**. Deshalb ist die **aktuelle Bewohner-Kachel** nach 5 Minuten ohne neues Signal wieder unbestätigt, und spätestens nach 15 Minuten ist auch der rohe SSID-Quellenwert veraltet. Dies ist beabsichtigter Schutz vor falscher dauerhaft angezeigter WORK-Anwesenheit. Für eine wirklich ganztägig bestätigte Carina-WORK-Anzeige brauchen wir eine ergänzende, zuverlässig wiederkehrende iPhone-/Controller-Datenquelle.

[Apple WLAN-Automationen](https://support.apple.com/de-de/guide/shortcuts/apde31e9638b/ios).

## Datenregeln / Sicherheit

- `Intern-Veit` und `Privat-Veit` = WORK-Smartphone-Kontext (VLAN 2); `Privat-Veit-EXT` reicht zum Wohnbereich und ist **NICHT** eindeutig WORK; `Veit-FilzenerWeg`/`filzenerweg` = HOME.
- Der alte 150-m-Geofence umfasst Firma und Zuhause und kann beide nicht unterscheiden.
- Das Symcon-Empfangsskript speichert **nur die aktuelle SSID** pro Telefon, keine GPS-Daten, BSSID, Passwörter oder Token im SHADOW-JSON. Zwei persönliche Zufalls-Token schützen die HTTPS-POST-Aufrufe.
- WORK-Hinweise von Telefonen beweisen **nicht** automatisch, dass niemand im Haus ist. Die positiven unabhängigen 15 LCN-GUS/P03-Hauseintrittsnachweise bleiben bestehen. Keine automatischen HOME/AWAY- oder Alarmaktionen.

## Neu in v0.8.111 Build 192 – ohne zusätzliche SHADOW-Variablen

- Die schon vorhandene `WifiSSID`-Variable im nativen Geofency-iPhone-Gerät darf weitergelesen werden, wenn sie existiert und aktuell ist. EgiGeoZone Android sendet im normalen Geofence-Hook keine persönliche WLAN-SSID; daher dafür die obige getrennte Android-Meldung verwenden.
- Kommen für dieselbe Person ein nativer und ein dedizierter Bridge-SSID-Wert vor, wird **die dedizierte, token-authentifizierte Bridge** als eigene Quellklasse bevorzugt. Doppelte Brücken-IDs bleiben gesperrt, nicht geraten. Nur persönlich gebundene SSID-Variablen werden akzeptiert, niemals AP-, Router- oder Shelly-Netzwerknamen.
- Die verifizierte SSID gilt in **allen** SHADOW-Anwesenheits- und Diagnosezweigen einheitlich; die ursprüngliche, nur manuell freigegebene Diagnose ist als `workWiFi` separat verfügbar. Das von der Kachel tatsächlich ausgewertete Ergebnis heißt `effectiveWorkWiFi`.
- Eine unveränderte automatische Quelleninventur wird nach Prüfung im flüchtigen Arbeitsspeicher gehalten. Sie verursacht nicht mehr alle fünf Minuten einen dauerhaften Speicher-Schreibzugriff. Es entstehen weder neue SHADOW-Variablen noch neue persistente Attribute.
- Frühere reale P03-Richtungsnachweise werden **nicht** zurückgesetzt und erfordern für dieses WORK-SSID-Update **keinen erneuten Gehtest**. Aus dem letzten Betriebsbericht ergibt sich trotzdem: P03 #53879 meldet weiterhin TEST/PRODUKTION AUS und liefert daher keine *neuen* bestätigten Übergänge. Diese unabhängige Freigabe wird nicht durch das lesende SHADOW-Modul umgangen.

## Vollautomatisierung – Grenzen des Zugriffs

Die bisherige GitHub-Entwicklung kann **nicht** direkt auf deine private SymBox oder die Benutzeroberflächen von Xiaomi/Carina-iPhone zugreifen. Die Einrichtung des vorstehenden PHP-Skripts bleibt eine **einmalige bestätigte lokale Benutzeraktion**. Ein erfolgreich registrierter Hook ist noch KEINE aktive SSID-Messung: die Android-App braucht ihre WLAN-Leserechte und einen regelmäßigen HTTP-POST-Flow; die iPhone-Kurzbefehle benötigen Apples Automationsfreigabe. Keine Zugangsdaten, Telefontokens oder dein persönlicher IP-Magic-Link werden in GitHub hinterlegt. Bei einem Symcon-Hook-Empfang entscheidet ausdrücklich `$_IPS['SENDER']==='WebHook'` statt eines erratenen HTTP-Headers, sodass ein späterer URL-Aufruf niemals die lokale Installer-Ausgabe mit Geheimtokens abruft.
