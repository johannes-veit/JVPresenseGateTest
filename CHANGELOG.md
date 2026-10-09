# v0.6.12

- Behebt den real beobachteten Zustand, dass beide P03-Mastkamera-Observer dauerhaft `Dahua Eventstream OK = AUS` bleiben, obwohl die IVS-Regeln korrekt angelegt wurden.
- Ursache 1: `ApplyChanges()` stoppte den Restart-Timer, setzte `SocketRestartStage` aber nicht auf 0 zurück. War die Stufe noch 1/2, konnte danach kein neuer Socket-Neustart mehr geplant werden.
- Ursache 2: Der P03-Watchdog hatte im Gegensatz zur bewährten `AussenlichtAutomatik2`-Implementierung keinen Wiederanlauf für „Client Socket aktiv, aber Digest-Handshake erreicht kein HTTP 200“. Dadurch konnte `Streaming=false` dauerhaft hängen bleiben.
- `ApplyChanges()` setzt jetzt Restart-Stufe, LastHttpRequest, LastCameraRx und LastSocketRestart definiert zurück.
- `Reconnect()` verwirft ebenfalls jede alte Restart-Stufe und startet garantiert eine neue Digest-Verbindung.
- Der Watchdog startet nach 12 s ohne erfolgreichen Stream einen frischen Socket neu.
- Der zusätzliche manuelle `IPS_ApplyChanges` auf dem Parent-Socket wurde entfernt; Parent-Konfiguration erfolgt wie bei der funktionierenden Außenlichtautomatik über `RequireParent()` + `GetConfigurationForParent()`.
- CI prüft künftig diese Restart-/Handshake-Verträge statisch.
- IVS-Template, 2-Kamera-Reihenfolge, P05 und Außenlichtautomatik bleiben unverändert.

# v0.6.11

- Behebt den Mastkamera-Auditfehler aus v0.6.10: der native Dahua-Regeltemplate-Abruf verwendete den falschen CGI-Endpunkt `devVideoAnalyse.cgi`.
- Korrekt laut Dahua/Intelbras-HTTP-API: `/cgi-bin/VideoInAnalyse.cgi?action=getTemplateRule&Channel=1&Class=Normal`.
- Der falsche Endpunkt erklärte, warum beide identischen IPC-HFW5442E-ZE gleichzeitig kein natives `CrossRegionDetection`-Template liefern konnten, obwohl Anmeldung, SMD und Geräteabfrage funktionierten.
- Wenn alle Template-Wege trotzdem fehlschlagen, protokolliert P03 jetzt die Ergebnisse von HTTP-`VideoInAnalyse`, RPC2-`getDefault` und Web5-`factory`, statt nur `IVS-Human=FEHLER` auszugeben.
- CI verhindert künftig eine Rückkehr zum falschen `devVideoAnalyse.cgi?action=getTemplateRule`-Pfad.
- 2-Kamera-Logik, Parent-Isolation, P05-Fix und Außenlicht-Unabhängigkeit bleiben unverändert.

# v0.6.10

- Behebt die roten IP-Symcon-Instanzfehler „übergeordnete Instanz fehlerhaft / Schnittstelle geschlossen“ nach v0.6.9.
- P03-Hauptmodul ist jetzt vollständig parentlos. Die zwei internen Mastkamera-Observer besitzen jeweils ihren eigenen Client Socket; das Hauptmodul braucht keine übergeordnete Schnittstelle.
- Beim Update trennt P03 eine noch vorhandene Legacy-Parent-Verbindung aus älteren Versionen automatisch mit `IPS_DisconnectInstance`.
- Die frühere optionale Terrassen-Eventstream-Schnittstelle wurde aus dem P03-Formular entfernt. Terrasse/CrossLine bleibt für die aktuelle 2-Kamera-Logik ohne Bedeutung.
- Behebt gleichzeitig einen durch die Klassenisolation aus v0.6.9 entstandenen P05-Fehler: das Schiebetor-Modul verwendete noch die alten globalen Klassennamen `DahuaDigest` / `DahuaEventParser`. P05 verwendet jetzt ebenfalls die isolierten Repository-Helfer `JVP03DahuaDigest` und `JVP03DahuaEventParser`.
- Neue CI-Prüfungen verhindern künftig erneut eine Parent-Abhängigkeit des P03-Hauptmoduls und verifizieren, dass P05 keine alten Dahua-Helfer mehr aufruft.
- Außenlichtautomatik bleibt unverändert.

# v0.6.9

- P03 vollständig gegen die Außenlichtautomatik isoliert: die P03-Hilfsklassen heißen jetzt `JVP03DahuaDigest` und `JVP03DahuaEventParser`. Dadurch können die gleichnamigen globalen Klassen des Außenlichtmoduls nicht mehr kollidieren oder dessen Eventverarbeitung abbrechen.
- Die bereits beobachtete Folge „Hof Person erkannt bleibt dauerhaft AN“ ist damit als mögliche Klassenkollisions-/Callback-Störung aus P03 konstruktiv ausgeschlossen. Das Außenlichtmodul selbst wird weiterhin nicht verändert.
- Die beiden Lagerplatzkameras verwenden keine handgebaute IVS-Regel mehr. P03 liest zuerst ein natives `CrossRegionDetection`-Template direkt aus der jeweiligen Dahua-Firmware (HTTP getTemplateRule, RPC2 Default oder Web5 getTemplateRule) und übernimmt alle firmware-spezifischen Felder.
- Falls kein sicher auswertbares natives CrossRegion-Template geliefert wird, erfolgt Sicherheitsabbruch ohne neue IVS-Regel.
- Die P03-Mastregel ist Human-only, breitflächig und richtungsneutral. Bei Firmware mit Action-Liste werden `Appear + Cross` aktiviert; bei String-Action wird `Appear` verwendet. `Direction=Both`.
- Dahua-`CfgRuleId`, `RuleID` und `RuleId` werden nicht mehr zusammengeworfen. Zusätzlich wird der Regelname ausgewertet. Exakter Regelname ist maßgeblich; numerische IDs dienen nur als Fallback.
- Die beiden Mastkamera-Observer haben einen monotonen `HumanEventCounter`. Die P03-Beweislogik arbeitet künftig mit diesen Eventzählern, nicht mit dem sichtbaren 5-s-Boolean `Person erkannt`. Dadurch gehen aufeinanderfolgende Treffer nicht verloren, wenn das Boolean noch AN ist.
- JV Terrasse/CrossLine ist für P03 jetzt standardmäßig deaktivierte Zusatzdiagnose. Audit, Lauftest und Produktivbetrieb benötigen nur JV_LEFT und WORK_LEFT.
- Beim ersten Audit/Test werden ausschließlich von älteren P03-Versionen gespeicherte Terrassen-Teständerungen zurückgerollt; danach wird die Terrasse im normalen Mastkamera-Ablauf nicht mehr konfiguriert.
- Der alte RuleID-vs-Index-Fehler der Terrassen-Diagnose wurde ebenfalls gehärtet; Name/mehrere Dahua-ID-Felder werden berücksichtigt.
- Erweiterte CI-Verträge prüfen Klassenisolation, natives Dahua-Template, getrennte Rule-IDs, Eventcounter-basierte 2-Kamera-Logik und verhindern erneute Abhängigkeiten zur Außenlichtautomatik.
- 2-Kamera-Richtungslogik bleibt: `JV_LEFT→WORK_LEFT = HOME→LAGER`, `WORK_LEFT→JV_LEFT = LAGER→HOME`.

# v0.6.8

- Gründliche Dahua-IVS-Korrektur für die beiden Lagerplatzkameras.
- Die P03-`CrossRegionDetection`-Regel enthielt bisher keinen `Config.Action`-Eintrag. Dahua erwartet für Intrusion/CrossRegion eine Aktion wie `Cross`, `Appear` oder `Inside`; reale Dahua-Regeln verwenden typischerweise `Action[0]=Cross`, `Action[1]=Appear` plus `Direction=Enter`.
- P03 erzeugt die Human-Regel jetzt mit `Action=[Cross,Appear]`, `Direction=Enter`, `MinDuration=1`, `Sensitivity=10`, `TrackDuration=30`, `MinTargets=1`, `MaxTargets=100`, `ReportInterval=1` und Human-`AccuracySnap`.
- Die Detektionsregion ist jetzt ein sauberes 4-Punkt-Polygon ohne redundant wiederholten Startpunkt.
- Der Audit akzeptiert eine Human-IVS-Regel nur noch, wenn `Action` und `Direction` korrekt zurückgelesen wurden. Damit ist das bisherige falsche `IVS-Human=OK` bei einer funktional unvollständigen Regel ausgeschlossen.
- Dahua-Ereignisse mit `CfgRuleId` / `CfgRuleID` werden jetzt zusätzlich zu `RuleID` / `RuleId` ausgewertet.
- Auditprotokoll zeigt zusätzlich `IVS-Actions` und `IVS-Direction`.
- Regressionstests prüfen fehlende/fehlerhafte Actions, Direction, Polygonform und `CfgRuleId`-Parsing.
- 2-Kamera-Sequenzlogik bleibt unverändert: `JV_LEFT→WORK_LEFT` = HOME→LAGER, `WORK_LEFT→JV_LEFT` = LAGER→HOME.
- Außenlichtautomatik bleibt vollständig unabhängig und unverändert.

# v0.6.7

- Fehler in den beiden P03-Lagerplatz-Observern behoben: Dahua-`event.RuleID` wurde fälschlich gegen den `VideoAnalyseRule`-Tabellenindex verglichen.
- Korrekt ist: `RuleID` muss gegen das echte Dahua-Regelfeld `Id` geprüft werden. Index und Id werden jetzt getrennt gespeichert und protokolliert.
- Beispiel aus dem bereits bekannten Dahua-Verhalten: Tabellenindex 3 kann zur Regel-`Id` 0 gehören; ein Event mit `RuleID=0` darf deshalb nicht als fremde Regel verworfen werden.
- P03-Audit zeigt künftig für beide Mastkameras `IVS-RuleIndex` und `IVS-RuleID` separat.
- Die internen P03-Observer erhalten zusätzlich die Diagnosevariable `Letztes CrossRegion-Ereignis roh` und Debug-Ausgabe für jedes empfangene CrossRegion-Ereignis.
- Regressionstest stellt sicher, dass Tabellenindex und Dahua-RuleID nicht mehr verwechselt werden.
- 2-Kamera-Sequenzlogik aus v0.6.6 bleibt unverändert: JV_LEFT→WORK_LEFT=HOME→LAGER, umgekehrt=LAGER→HOME.
- Außenlichtautomatik bleibt vollständig unabhängig und unverändert.

# v0.6.6

- P03-Grundlogik geändert: der eigentliche HOME↔LAGER-Grenzübertritt wird jetzt ausschließlich aus der zeitlichen Reihenfolge der beiden Lagerplatzkameras bestimmt.
- `JV_LEFT -> WORK_LEFT` innerhalb des konfigurierten Zeitfensters = `HOME_TO_LAGER`; `WORK_LEFT -> JV_LEFT` = `LAGER_TO_HOME`.
- Nur eine Kamera, dieselbe Kamera mehrfach, gleichzeitige Erkennung, zu großer Zeitabstand oder eine überlappende Gegenrichtung erzeugen keinen Übergang.
- JV Terrasse und ihre CrossLine bleiben nur Zusatzdiagnose/Bestätigung und dürfen keine der beiden Mastkameras ersetzen.
- Die Produktfreigabe verlangt jetzt die beiden laufenden P03-Mastkamera-Eventstreams; der Terrassen-Eventstream ist dafür nicht mehr Voraussetzung.
- Die P03-Aux-Observer behandeln jedes passende Event der dedizierten `CrossRegionDetection`-Regel als Human-Beweis, weil die Kamera-Regel selbst bereits `ObjectTypes=Human` filtert. Ein fehlendes `Human`-Feld im Event-Payload führt daher nicht mehr zu einem falschen AUS.
- `Person erkannt` bleibt nach einem Treffer 5 Sekunden sichtbar; ein sofort folgendes STOP-Ereignis löscht den Puls nicht mehr vorzeitig.
- Interne Simulation und Regressionstests wurden auf die neue 2-Kamera-Sequenzlogik umgestellt, inklusive Zeitfenstergrenzen, gleichzeitiger Erkennung, Wiederverwendung, schneller Gegenrichtung und CrossLine-nur-als-Zusatz.
- Außenlichtautomatik bleibt vollständig unabhängig und unverändert.
- Keine Änderung an P05 oder der P03-CrossLine-Geometrie.

# v0.6.5

- P03 vollständig von `AussenlichtAutomatik2` entkoppelt. Das Presence-Modul ruft keine ALA2-Funktion mehr auf, verwendet keine ALA2-Modul-GUID und liest keine ALA2-Instanz als Laufzeitquelle.
- Neue interne Modulinstanz `JV Presence P03 Aux Observer` für JV links und Werkstatt links. Jeder Observer besitzt einen eigenen Dahua-`codes=[All]`-Eventstream und liefert ausschließlich die P03-Variable `Person erkannt`.
- JV Terrasse wird ebenfalls direkt im P03-Modul konfiguriert: Host, HTTP-Port, Dahua-Benutzername und Passwort stehen nun in der P03-Konfiguration.
- Die in v0.6.4 eingeführten P03-eigenen Human-IVS-Regeln (`CrossRegionDetection`, `ObjectTypes=Human`) bleiben bestehen, werden aber nur noch von den P03-eigenen Observern ausgewertet.
- Der v0.6.4-Fatalfehler `Cannot redeclare class DahuaDigest` ist konstruktiv ausgeschlossen, weil P03 keine generierten ALA2-Wrapper mehr aufruft.
- Bestehende Außenlicht-Instanzen werden nicht geändert, neu konfiguriert, neu gestartet oder gelöscht.
- CI sperrt jede erneute direkte Abhängigkeit zu `AussenlichtAutomatik2` und prüft den neuen Aux-Observer sowie Human-`CrossRegionDetection`-Parsing.
- Keine Änderung an P05, der P03-Grenzgeometrie oder der Proof-Engine.

# v0.6.4

- P03-Zusatzkameras `JV_LEFT` und `WORK_LEFT` verwenden jetzt eine eigene P03-IVS-Human-Regel statt auf `SmartMotionHuman`/SMD zu vertrauen.
- Für beide IPC-HFW5442E-ZE wird eine P03-eigene `CrossRegionDetection` mit `ObjectTypes=Human` und nahezu vollflächiger Region angelegt; die bestehenden `AussenlichtAutomatik2`-Instanzen werten deren echte Human-IVS-Events über `PersonDetected` aus.
- SMD bleibt nur Diagnose/Fallback und wird vom P03-Audit nicht mehr als Beweis für funktionierende Human-Events akzeptiert.
- Fremde IVS-Regeln werden nicht verändert. Vor Änderungen werden vollständige `VideoAnalyseRule`-/`VideoAnalyseGlobal`-Tabellen gesichert; Readback, No-Op-Write und Rollback sind fail-closed.
- Falls für IVS nötig, wird auf den Zusatzkameras nur `Scene.Type=Normal` aktiviert; ein bereits aktiver anderer AI-Smart-Plan führt zum Sicherheitsabbruch.
- Alle Alarm-/Record-/Snapshot-/Mail-/Voice-/HTTP-Nebenwirkungen der P03-Human-Regeln werden deaktiviert.
- Nach IVS-Änderungen wird der jeweilige `AussenlichtAutomatik2`-Eventstream neu aufgebaut.
- Beim expliziten P03-Cleanup/Deaktivieren werden die von P03 erzeugten Zusatzkamera-Regeln und Smart-Plan-Änderungen aus den gesicherten Originaltabellen zurückgesetzt.
- Neue Regressionstests prüfen Aufbau, Human-Filter, Region und deaktivierte Nebenwirkungen der Zusatzkamera-IVS-Regel.
- Keine Änderung an P05, der P03-Grenzgeometrie oder der eigentlichen Proof-Engine.

# v0.6.3

- Behebt den IP-Symcon-Ladefehler des neuen Moduls: Klassenname jetzt exakt `JVPresenceP03MultiCamera` passend zu `JV Presence P03 Multi Camera`.
- Neuer eindeutiger Prefix `JVP03MC` für das Mehrkamera-Modul; der alte P03-Test behält `JVP03` und kollidiert nicht mehr.
- Alle Timer- und Formular-Callbacks des Mehrkamera-Moduls auf `JVP03MC_*` umgestellt.
- CI prüft künftig automatisch Klassenname ↔ module.json sowie doppelte Modul-Prefixe.

# v0.6.2

- P03-Grundtest und Simulation erweitert: zusätzliche Zeitfenster-Grenzfälle, fehlendes Dahua-Direction-Feld, Auswahl des grenznächsten Human-Ereignisses und 3-Kamera-Grenzwerte werden regressionsgeprüft.
- Der reine P03-Gesamtaudit löscht bereits verifizierte Feldläufe und gelernte Richtungen nicht mehr; nur flüchtige Event-/Proof-Puffer werden für den Audit geleert.
- Produktivfreigabe verlangt jetzt ausdrücklich einen laufenden Eventstream der JV-Terrasse, da dessen Human-Signal Bestandteil des Mehrkamera-Beweises bzw. Fallbacks ist.
- Produktivstatus behandelt den zulässigen 3-Kamera-Fallback korrekt als produktiv, auch wenn die CrossLine-Richtung noch nicht stabil gelernt ist.
- Dahua-SMD-Readback nach automatischer Konfiguration akzeptiert zusätzlich die Firmware-Variante ObjectTypes[0]=Human.
- Wenn MotionDetect explizit aktiviert werden musste, ist danach ein eindeutiger TRUE-Readback Pflicht; ein unbekannter/fehlender Wert führt fail-closed zum Abbruch.
- CI prüft nun zusätzlich die bestehende GateTestLogic sowie die gehärteten P03-Modulverträge.
- Keine Änderung an P05 und keine Änderung an der P03-Grenzgeometrie.

# v0.2.6

- Nacht-/Konfigurations-Audit hinzugefügt: neuer Button `Kamera-Konfiguration prüfen (kein Lauftest)`.
- Verifiziert die Dahua-Konfiguration ohne Grenzübertritt über drei unabhängige Wege: dokumentierte CGI-API, RPC2-Readback und Web5-`devVideoAnalyse.getCaps`.
- Nutzt für die aktive IVS-Szene ausschließlich den von Dahua dokumentierten Befehl `VideoAnalyseGlobal[0].Scene.Type=Normal`; `Scene.TypeList` wird nicht mehr zur Aktivierung verwendet.
- Scene.Type wird direkt nach dem Schreiben per CGI rückgelesen; bei Abweichung Sicherheitsabbruch und Best-Effort-Rollback.
- Behebt die Restore-Logik: eine vom Modul gesetzte Scene.Type wird vor einem neuen Lauf zuerst auf den ursprünglichen Wert zurückgesetzt, bevor ein neuer Test aufgebaut wird.
- P05-Audit prüft: Name/Typ/Enable/Direction/Class/Human, vollständige 5-Punkt-Linie über Schiebetor + Personentür, SMD bleibt aktiv, RPC2-Regel stimmt, keine Alarm-/Sirenen-/Record-/Snapshot-Nebenwirkung, Web5-Caps unterstützen Normal/CrossLine/Human und mindestens 5 Linienpunkte.
- `TriggerPosition` bleibt aus der CrossLine-Regel entfernt, weil die Kamera-Caps ausdrücklich `TriggerPosition=false` melden.
- Die fehlgeschlagene `getTemplateRule`-Abfrage ist nicht mehr Voraussetzung für die Freigabe; die Kamera-Caps und dokumentierten Config-Readbacks sind hierfür maßgeblich.
- Der normale Lauftest bleibt erhalten und wird künftig erst nach erfolgreichem Konfigurations-Audit freigegeben.

# v0.2.5

- Ursache des weiter fehlenden CrossLine-Events weiter eingegrenzt: `Scene.TypeList=[Normal]` wird zwar gespeichert, aktiviert aber nicht die laufende IVS-Szene.
- Stellt die Dahua-IVS-Aktivierung auf `VideoAnalyseGlobal[0].Scene.Type=Normal` um. Genau dieses Feld wird von funktionierenden Dahua-IVS-Implementierungen gesetzt.
- Vorhandene fremde `Scene.Type`-Werte werden nicht überschrieben; die komplette Global-Tabelle bleibt gesichert und rollback-fähig.
- Rückleseprüfung prüft nun explizit `Scene.Type=Normal`.
- Entfernt `TriggerPosition=[Center]` aus P05, weil die Kamera-Caps für `CrossLineDetection` ausdrücklich `TriggerPosition=false` melden.
- Erweiterte P05-Polylinie über Schiebetor und Personentür bleibt unverändert erhalten.

# v0.2.4

- Auswertung des Web5-Protokolls: Zähler bleibt 0, weil weiterhin kein `CrossLineDetection`-Event von der Kamera kommt; Counter/Variable sind nicht die Ursache.
- Kamera-Caps bestätigen `CrossLineDetection` in Szene `Normal` sowie Objektarten `Human` und `Vehicle`.
- Die bisherigen `getTemplateRule`-Objektanfragen wurden mit Dahua-Fehler `-267976701` abgewiesen.
- Ergänzt deshalb eine vollständig lesende Template-Abfrage mit `rule="CrossLineDetection"` sowie `Type`-Only-Fallback, um die exakte Regelvorlage dieser Firmware zu erhalten.
- Keine zusätzliche Kameraänderung durch diese Diagnose.

# v0.2.3

- Erweitert P05 gemäß der neuesten roten Markierung im Kamerabild nach links über die zusätzliche Personentür.
- P05 ist jetzt eine echte Dahua-Polylinie mit fünf Stützpunkten statt nur einer Zweipunkt-Geraden.
- Verwendete IVS-Koordinaten: `[[4858,925],[4922,936],[4959,1138],[5189,1529],[5887,2406]]`.
- Rückleseprüfung verifiziert nun die komplette Polylinie, nicht nur Anfang und Ende.
- Die Geometrie wird sowohl beim RPC2-Writer als auch bei der Web5-IVS-Diagnose identisch verwendet.

# v0.2.2

- Keine weiteren Laufversuche, bevor die echte moderne Dahua-Web5-IVS-API ausgewertet ist.
- Nach P05 + Normal-Smart-Plan wird ausschließlich lesend `devVideoAnalyse.factory.instance` auf Kanal 0 aufgerufen.
- Anschließend werden über das zurückgegebene Analyse-Objekt `devVideoAnalyse.getCaps` und mehrere `devVideoAnalyse.getTemplateRule`-Varianten für `CrossLineDetection` abgefragt.
- Damit sehen wir direkt aus der Kamera, welche Regeln/Objekttypen die Normal-Szene unterstützt und welche exakte CrossLine-Template-Struktur diese Firmware erwartet.
- Es erfolgt über `devVideoAnalyse` keinerlei Schreibzugriff.

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
