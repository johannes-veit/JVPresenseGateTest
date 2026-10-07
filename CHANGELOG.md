# v0.2.1

- Die Runtime-Diagnose zeigt `VideoAnalyseGlobal.Scene.TypeList=[]`: Es ist kein IVS-Smart-Plan aktiv, obwohl Normal-Modul und P05-CrossLine-Regel vorhanden sind.
- Aktiviert deshalb kontrolliert den Dahua-IVS-Smart-Plan über RPC2 mit `Scene.TypeList=["Normal"]`.
- Vor der Änderung wird die komplette `VideoAnalyseGlobal`-Tabelle gesichert und ein NO-OP-Write verifiziert.
- Fremde bereits aktive Smart-Pläne werden nicht überschrieben; dann erfolgt Sicherheitsabbruch.
- Nach dem Write wird `TypeList` zurückgelesen und `SmartMotionDetect` kontrolliert. Bei Konflikt oder Abweichung erfolgt automatischer Rollback.
- Vor einem neuen Test und über `Testkonfiguration entfernen` wird die ursprüngliche `VideoAnalyseGlobal`-Tabelle exakt wiederhergestellt.
- Nach Smart-Plan-/P05-Änderungen wird der Dahua-Eventstream erneut aufgebaut.

# v0.2.0

- Realtest zeigt: `SmartMotionHuman` und `VideoMotion` kommen korrekt an, `CrossLineDetection` jedoch nicht. Damit sind Eventstream, Zugang und Personenerkennung nachweislich funktionsfähig; offen ist nur der IVS-Runtime-/Smart-Plan-Zustand.
- Nach dem automatischen Anlegen von P05 werden nun vor weiteren Laufversuchen ausschließlich lesend `VideoAnalyseGlobal`, `VideoAnalyseModule` und `VideoAnalyseRule` jeweils als CURRENT/DEFAULT über RPC2 ausgelesen.
- Der Test stoppt danach bewusst mit `IVS-DIAGNOSE ERFASST`; erneutes Durchlaufen ist bis zur Auswertung nicht nötig.

# v0.1.9

- Behebt den nächsten Realtest-Befund: Der Dahua-Eventstream war bereits verbunden, bevor P05 per RPC2 angelegt wurde.
- Nach einer neu erzeugten P05-Regel wird der `eventManager.cgi?codes=[All]`-Stream jetzt zwingend neu aufgebaut, damit die Kamera die neue `CrossLineDetection` in das laufende Event-Abo übernimmt.
- Während des Tests werden relevante Nicht-IVS-Ereignisse (`SmartMotionHuman`, `VideoMotion`, `SmartMotionVehicle`, `CrossRegionDetection`) als Diagnose protokolliert. Damit lässt sich unterscheiden, ob der Eventstream funktioniert, aber nur der IVS-Smart-Plan nicht feuert.

# v0.1.8

- Behebt zwei beim ersten realen P05-Lauf gefundene Fehler.
- P05 verwendet nun intern fest die kalibrierte Linie aus dem Nutzerbild: A(4993,1342) → B(5923,2294); alte persistierte Property-Werte können die Geometrie nicht mehr verfälschen.
- Dahua `eventManager`-Feld `index` ist der Video-Kanalindex (JV Hof Garage: 0), nicht der Index der `VideoAnalyseRule` (P05: 3). CrossLine-Ereignisse werden daher nicht mehr fälschlich verworfen.
- Eine vom Modul erzeugte P05-Regel aus einem vorherigen Lauf wird vor einem neuen Test automatisch auf die gesicherte Originaltabelle zurückgesetzt und anschließend sauber neu angelegt.

# v0.1.7

- Nutzt den bestätigten Dahua-Web5/RPC2-Zugriff zum automatischen Anlegen von `P05_HOME_STREET`.
- Vor dem echten Schreiben wird die aktuelle `VideoAnalyseRule`-Tabelle vollständig gesichert.
- Ein semantisch identischer NO-OP-Write prüft zuerst, ob die Firmware vollständige RPC2-Tabellenwrites akzeptiert.
- Erst danach wird eine neue `CrossLineDetection` mit der aus dem Nutzerbild abgeleiteten Linie A(4993,1342) → B(5923,2294), `Direction=Both` und `ObjectTypes=[Human]` angefügt.
- Alarm-, Sirenen-, Mail-, Aufzeichnungs- und Snapshot-Verknüpfungen sind für die Testregel deaktiviert.
- Nach dem Schreiben werden neue Regel, Linienkoordinaten, Richtung, Human-Filter sowie die drei vorhandenen Regeln rückgelesen und geprüft.
- Bei jeder Abweichung erfolgt automatischer Rollback auf die gesicherte Originaltabelle.
- Die bestehende SmartMotion-Personenerkennung wird nach dem Write nochmals kontrolliert; bei Konflikt ebenfalls Rollback.
- `Testkonfiguration entfernen` stellt bei einer vom Modul erzeugten Regel die komplette ursprüngliche `VideoAnalyseRule`-Tabelle per RPC2 wieder her.

# v0.1.6

- Korrigiert den Dahua-Web5/RPC2-Zweistufenlogin.
- Die erste Login-Challenge darf bei Dahua absichtlich `HTTP 200 + result=false` liefern; entscheidend sind `session`, `realm` und `random`.
- Challenge-Details werden ohne Zugangsdaten im Testprotokoll protokolliert.
- Danach werden aktuelle/default `VideoAnalyseRule` weiterhin ausschließlich lesend abgefragt.

# v0.1.5

- Fügt eine ausschließlich lesende Dahua-Web5/RPC2-Diagnose hinzu.
- Verwendet die automatisch übernommenen Zugangsdaten der JV-Hof-Garage-Instanz.
- Liest `VideoAnalyseRule` und `VideoAnalyseGlobal` über RPC2 sowie die Default-Tabelle von `VideoAnalyseRule`.
- Verändert bei diesem Schritt keine Kameraeinstellung.
- Die aus dem Nutzerbild abgeleitete P05-Geometrie ist bereits hinterlegt: A≈(4993,1342), B≈(5923,2294).

# v0.1.4

- Für IPC-PDW3849-A180-AS-PV / Taurus-Web5 wird keine neue IVS-Regel mehr per Legacy-CGI erzwungen.
- Vorhandene CrossLineDetection wird automatisch erkannt und für den Test wiederverwendet.
- Falls noch keine Tripwire existiert, bleibt die Kamera unverändert und das Modul fordert genau eine manuelle IVS-Tripwire an.
- Danach genügt erneut „Test vorbereiten & starten“; die Richtung OUT/IN wird weiterhin automatisch aus vier Durchgängen gelernt.
- CrossLine-Events anderer Regelindizes werden beim Test ignoriert, soweit die Firmware einen Index liefert.

# v0.1.3

- Erkennt ältere vs. moderne Dahua-IVS-Konfigurationswege.
- Wenn das direkte Anlegen einer neuen VideoAnalyseRule abgelehnt wird, startet automatisch eine ausschließlich lesende Kompatibilitätsanalyse.
- Protokolliert VideoAnalyseGlobal, getSceneList, devVideoAnalyse getCaps, getTemplateRule, Softwareversion und Gerätetyp.
- Nach einer abgelehnten Regelanlage werden keine weiteren Kameraeinstellungen geschrieben.

# v0.1.2

- Dahua-IVS-Regel wird jetzt schrittweise geschrieben: Name/Typ → Geometrie/Richtung → Aktivierung.
- Jeder Schritt wird sofort aus der Kamera zurückgelesen und verifiziert.
- Kameraantwort (HTTP + Body) und vorhandene IVS-Regeln werden im Testprotokoll protokolliert.
- Ein leeres oder abweichendes setConfig-Response-Body führt nicht mehr zu einer falschen Diagnose, sofern die Änderung real übernommen wurde.
- Neue Regel wird bevorzugt hinter dem höchsten vorhandenen Regelindex angelegt, statt blind eine Lücke zu verwenden.

# v0.1.1

- Auto-Erkennung wird bei jedem Klick auf „Test vorbereiten & starten“ erneut ausgeführt.
- Zusätzlicher Fallback findet JV Hof Garage auch per Instanzname bzw. Kamera-IP 192.168.107.111.
- Konfigurationsformular zeigt jetzt die automatisch erkannte Quellinstanz und den aktuellen Teststatus direkt an.
- Klarstellung: das Auswahlfeld ist nur eine manuelle Übersteuerung und bleibt bei funktionierender Automatik absichtlich leer.
- Statusanzeige wird nach Aktionen automatisch aktualisiert.

# Changelog

## 0.1.0

- eigenständiges Gate-Testmodul ohne Änderung des Außenlichtmoduls
- automatische Erkennung von `JV Hof Garage`
- eigene Digest-Eventstream-Verbindung
- IVS-Konfigurationsprüfung über `configManager.cgi`
- reversible Smart-Plan-Schutzlogik
- automatische P05-Tripwire `P05_HOME_STREET`
- Ereignisdeduplizierung über EventID
- automatische Testfolge OUT / IN / OUT / IN
- automatische Direction-Zuordnung nach vier konsistenten Grenzübertritten
