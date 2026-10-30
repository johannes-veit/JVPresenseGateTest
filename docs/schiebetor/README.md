# Schiebetor GPS – selbstinstallierende VISU-Bruecke v1.9 (SHADOW-Modul 0.8.125)

**Ziel:** das bestehende Xiaomi/Automate-GPS-Projekt fuer die Tor-Bedienseite fertig anbinden, ohne GPS-Empfangs- oder Tor-Aktionsskripte zu veraendern. **Tor wird niemals automatisch geschaltet.**

## Nach dem Modul-Update

Beim `ApplyChanges` der bekannten SHADOW-Instanz **#32969** wird `JVSchiebetorAutoInstaller::install()` ausgefuehrt. Die Funktion erstellt keine neue Modulinstanz und keine neuen Timer oder GPS-Ereignisse. Das Modul bleibt aktiv, auch wenn die getrennte Schiebetor-Einrichtung scheitert.

Die Installationsroutine prueft vor Schreibzugriff:
- #29252 GPS-Kategorie, #30894 Entscheider, #56236 Kachel-Visualisierung, #21149 Tor-Kategorie;
- **#30894 muss Version 1.6.2-SHADOW enthalten** (andernfalls nur Status `NICHT_BEREIT`, keinerlei Aenderung);
- vorhandenes Bridge-Skript #43393 muss ein bekanntes Original der Schiebetor-Visu-Bruecke oder eine bereits verwaltete Fassung sein;
- WebHook-Control muss eindeutig und `/hook/schiebetor-kachel` frei oder bereits dem eigenen Skript zugeordnet sein.

Nach Erfolg:
1. Bestehendes Skript #43393 wird *nur falls erforderlich* gesichert und durch die neueste VISU-Bruecke ersetzt. Fehlt es, wird eine neue Bridge unter #29252 erstellt.
2. **Eine** ausgeblendete Stringvariable `Schiebetor Automate Schluessel (privat)` mit Ident `JVSchiebetorVisuToken` wird unter #29252 angelegt. 64-stelliger zufaelliger Hex-Schluessel entsteht ausschliesslich lokal auf der SymBox; die GitHub-Dateien enthalten keinen geheimen Schluessel. Auf Updates bleibt der Schluessel erhalten.
3. Genau der neue `/hook/schiebetor-kachel` wird dem Bridge-Skript zugeordnet. Fremde/mehrdeutige Hooks werden nicht ueberschrieben. **Der vorhandene `/hook/schiebetor-gps` bleibt unveraendert.**
4. Im Konfigurationsformular von SHADOW #32969: Abschnitt **Schiebetor GPS – automatische Visualisierung** zeigt Ergebnis, Bridge-ID und Token-Variablen-ID. Button **Schiebetor-Installation erneut pruefen** wiederholt dieselbe sichere Routine.

Bei unerkannter Bearbeitung von #43393 meldet das Modul `KONFLIKT` statt zu ueberschreiben. Das alte Bridge-Skript wird vor Ersatz lokal unter `user/schiebetor/bridge_backup_...` gesichert.

## Einmalig auf dem Xiaomi 13T: importierbaren Komplett-Flow starten

Hilux-Bluetooth-Gatekeeper, funktionierenden GPS-POST und 5-s-Loop **nicht aendern**. Seit 0.8.125 kann der vorhandene GPS-Schluessel des Original-Automate-Flows direkt fuer den VISU-Handshake genutzt werden. **Kein Schluessel-Abtippen. Nicht in Chat, Screenshots oder GitHub kopieren.**

Bei einer importierten Komplett-FLOW-Datei sind diese Schritte bereits konfiguriert. Zur technischen Nachvollziehbarkeit: Nach erfolgreichem GPS-POST in Automate:

1. **HTTP request** GET, URL `https://<BISHERIGE_CONNECT_ADRESSE>/hook/schiebetor-kachel?action=poll`, Header `X-Schiebetor-Key: <lokaler Schluessel>`, Status in `torStatus`, Antworttext in `torAntwort`, Timeout 5 s.
2. **Expression true:** `torStatus = 200 && torAntwort = "OPEN"`. Bei NO zur bestehenden GPS-Pause zurueck.
3. **App start**: Action `android.intent.action.VIEW`, Data URI `symconvisus://<BISHERIGE_CONNECT_ADRESSE>/#56236`, Package/Activity leer. Dieses Scheme ist fuer die neue Kachel-App vorgesehen. Voraussetzung: Symcon Visualization installiert und mit der Visu bereits angemeldet.
4. **Delay:** 2 Sekunden.
5. **HTTP request** POST ohne Body an `https://<BISHERIGE_CONNECT_ADRESSE>/hook/schiebetor-kachel?action=show`, denselben Header `X-Schiebetor-Key` verwenden. Bei `SHOWN` hat Symcon die Tor-Kategorie #21149 zur Anzeige angewiesen. Danach in den vorhandenen GPS-Loop.

Antworten: `WAIT` (keine neue gueltige Anfahrt), `OPEN` (jetzt App starten), `SHOWN` (VISU-Aufruf erfolgt), `DENIED` (Schluessel), `VISU_ERROR` / `ACK_ERROR` (Serverproblem).

## Wichtige Grenzen

- Android darf aus dem Hintergrund bei gesperrtem Bildschirm das Oeffnen verhindern. Akkuoptimierung und Hintergrundstart fuer Automate konfigurieren, soweit das Telefon dies anbietet. Keine Displaysperre umgehen.
- `VISU_OpenObject(56236,21149,'')` schaltet **alle derzeit offenen Clients** dieser Visualisierung zur Tor-Kategorie, nicht nur das Xiaomi; die Symcon-API unterstuetzt die Tokenliste noch nicht.
- Der Entscheider v1.6.2 ist mit 333 Offline-Simulationen geprueft, nicht mit beschrifteten realen Filzener-Weg- und Parallelstrassenfahrten. Eine Anzeige kann deshalb zu frueh, zu spaet oder auch ausbleiben. Die Motorbedienung ist jederzeit ausschliesslich manuell.
- Der Installer erreicht die SymBox **nicht von GitHub oder ChatGPT aus**. Die Aktualisierung/ApplyChanges auf der laufenden SymBox ist weiterhin noetig.
- Die gespeicherte private Variable ist die **einzige** neue Symcon-Variable. Es werden keine LCN-, Dahua-, Anwesenheits- oder Motorbefehle ausgefuehrt.
