# Identität und Abwesenheit ohne neue Hardware – SHADOW-Entwurf 2026-10-13

## Status / harte Freigabegrenze

- Neue reine PHP-Datei `libs/JVIdentityHypothesisEngine.php`, **kein installierbares Symcon-Modul**.
- Weder P03 noch P05, Kameras, Aussenlicht, Alarmanlage, LCN oder PresenceCore werden verändert.
- **Keine Anwesenheitsbuchungen oder Alarmfreigaben**: Ausgaben `personPresenceMayBeChanged=false`,
  `propertyEmptyMayBePublished=false`. Zertifikate im Bericht sind nur simulierte Hypothesen.
- Der bereits **am 12.10.2026 erfolgreich abgeschlossene** P03-Vierfach-Gehtest (2× OUT, 2× IN sowie 4 Straßenrichtungen) ist archiviert und wird **nicht wiederholt**. Dies belegt noch keine Zählung gleichzeitig gehender Personen und keine identifizierten Bewohner. Produktionsfreigaben bleiben gesperrt, bis die gesamte Eingangs-/Ausgangsabdeckung und Gruppenzählung sicher sind.
- Keine zusätzliche Hardware, keine BLE-Tags, Gesichtserkennung oder Cloud-Abhängigkeit.

## Warum Hypothesen und keine Punktesummen?

Wenn Johannes und Carina nachweislich beide HOME sind und ein bestätigter
HOME->WORK-Übertritt mit **exakt einer Person** erfolgt, gibt es zwei zulässige Welten:
1. Johannes WORK, Carina HOME.
2. Johannes HOME, Carina WORK.

Der Kern behält **beide**. Er meldet HOME=1 und WORK=1, aber für beide Personen
`zone=UNKNOWN` mit den jeweils möglichen Zonen. Das vermeidet falsche Namensbuchungen.

Wenn beide vollständig nachgewiesen das Grundstück verlassen, können beide AWAY
sein, obwohl beide Telefone noch im Haus liegen. Dieser Schluss erfordert:
- **zuvor positiv bestätigte vollständige Personeninventur** (`baselineCertified=true`);
- alle relevanten Wege durch bestätigte physische Übertritte abgedeckt;
- zuverlässige Personenzahl bei Gruppen (kein Ereignis zählt bloß die Bewegung einer Gruppe);
- unabhängige Prüfung der Erfassungsabdeckung `coverage.home`, `coverage.house`,
  `coverage.property`, die im produktiven Adapter **nicht** einfach pauschal true gesetzt werden darf;
- keine widersprüchlichen/unvollständigen Ereignisse oder unbekannten verbliebenen Besucher.

## Räumliche Trennung

- `HOME`: Innenbereich des Hauses (HOME_CORE/HOUSE). Ein gesondertes Coverage-Flag
  ist für das gesamte HOUSE nötig; solange räumlich nicht separat aufgelöst, beide
  Zertifikate auf dieselben HOME-Bewohner, aber jeweils eigene Coverage-Gates bezogen.
- `WORK`: Lagerplatz/Firma (innerhalb des Grundstücks).
- `PROPERTY_OTHER`: Hof, Garten, Terrasse, Wege auf dem Grundstück;
  **nicht** automatisch AWAY.
- `AWAY`: nachgewiesener Übertritt über die **äußere Grundstücksgrenze**.
- `UNKNOWN`: Personenidentität/Aufenthaltsort nicht eindeutig rekonstruierbar.

P03: JV_LEFT (.95) und WORK_LEFT (.94), IVS-Treppe RuleID 4, HUMAN.
HOME->WORK = JV_LEFT->WORK_LEFT, Gegenrichtung WORK_LEFT->JV_LEFT.
Terrasse ist optionales, begrenztes 15/50-s Backup, kein zusätzlicher Pflichtsensor.
WORK_LEFT-Straße RuleID 5 ist als separater Diagnoseweg zu behandeln; sie darf
nicht automatisch als abschließender Grundstücks-Grenzbeweis für AWAY gelten.
Eine `AWAY`-Buchung muss auf einer nachweislich abgedeckten realen Außengrenze
(z.B. unabhängig validiertem P05) beruhen.

## Evidence-Eingabe

`JVIdentityHypothesisEngine::evaluate($people,$unknown,$coverage,$baselineCertified,$events)`

Beispiel (nur *synthetische* Testdaten, nicht Symcon-Sensor-Konfiguration):

```php
$report = JVIdentityHypothesisEngine::evaluate(
    ['johannes'=>'HOME', 'carina'=>'HOME'],
    ['HOME'=>0, 'WORK'=>0, 'PROPERTY_OTHER'=>0],
    ['home'=>true, 'house'=>true, 'property'=>true], // only after independent audit!
    true, // only from a certified prior inventory; not from quiet sensors!
    [
        [
            'id'=>'P03:sample-001', 'time'=>1790000001, 'type'=>'TRANSFER',
            'from'=>'HOME', 'to'=>'WORK', 'count'=>1,
            'proof'=>[
                'human'=>true, 'boundary'=>true, 'direction'=>true,
                'count'=>true, 'continuity'=>true, 'unique'=>true
            ]
        ]
    ]
);
```

Jedes Proof-Flag muss der späteren EventNormalizer-/Coverage-Prüfung entsprechen.
Ein boolesches Flag im Testbeispiel ist **kein** Nachweis realer Sensorabdeckung.
Unverifizierte, widersprüchliche, verzögerte oder nicht eindeutig deduplizierbare
Events stoppen die Verarbeitung und sperren alle Leerstands-Zertifikate.
Bei größerer Personengruppe stoppt eine Hypothesenobergrenze fail-closed.

Sonderfall Außengrenze: Beim Eintreten von AWAY erzeugt der Kern
**auch die Alternative unbekannter Besucher**. Eine zurückkehrende Person
bekommt ohne individuelle Evidenz nicht automatisch Johannes/Carina zugeordnet.
Ein unbekannter Besucher, der im Haus verbleibt, blockiert Leerstand.

## Vorhandene Sensoren: Nachweisstufen

| Quelle | Darf Personenzone ändern? | Nutzen |
| --- | --- | --- |
| P03 geprüfte HUMAN-Treppen-Übertrittskette | Nur im später zertifizierten PresenceCore-Adapter | HOME/WORK Übergänge |
| Unabhängig geprüfte äußere Grundstücksgrenze | Nur mit vollständig validiertem Zähl-/Richtungs-/Coverage-Beweis | PROPERTY/AWAY Übergänge |
| P03/Terrasse PersonDetected oder reine SMD | Nein | Positive Aktivität/Backup-Korrelation |
| Smartphone-Observer/UniFi/WLAN/GPS | Nein | Telefonindiz, Widerspruch `PHONE_MAY_BE_LEFT_BEHIND` |
| LCN-GUS (u.a. Kamin) | Nein | Positive Bewegung widerruft Leerstandsannahme; Ruhe belegt nichts |
| LCN-Licht, Garagentor, Schiebetor, Fahrzeug | Nein | Ablaufkontrolle, aber nicht Fahrer oder Person |
| Stille über beliebig lange Zeit | Nein | Keine Abwesenheits-Autorität |

**Kamin-GUS:** Uhr-Zeitfenster und bekannte Fehlalarme müssen bereits im
Eingangsadapter gefiltert werden. Ein zweifelhafter ungefilterter Aktivitätsimpuls
sperrt vorsichtshalber ein Leerzertifikat, erzeugt aber keine anwesende Person.

## Tests und nächster Schritt

`php tests/JVIdentityHypothesisEngineTest.php` simuliert u.a.:
- eine von zwei Personen weg; Identität offen, Gruppenzahl trotzdem präzise;
- beide weg, beide Smartphones bleiben zu Hause;
- Werkstatt zählt noch zum Grundstück;
- unbekannte Besucher und Rückkehr von AWAY;
- unvollständiger Proof, Duplikate, vertauschte Eventzeiten, Hypothesenüberlauf;
- Aktivität im leeren Haus, fehlende Coverage und fehlende Ausgangsinventur.

**Produktivschaltung ist ausdrücklich nicht Teil dieser Änderung.**
Nächster Schritt nach abgeschlossener P03-Abnahme: P03/P05 Eventnormalisierung mit überprüften
Zeitstempeln, Zählgenauigkeit bei Gruppen, erfassten Eingängen/Ausgängen,
aktualisierbarem PersonLedger und tatsächlicher Coverage-Map; anschließend
Zertifikate erst nach kompletter Replay-/Fault-Injection-Regression freigeben.

## Fortschritt 13.10.: Vorhandenen P03-Lauftest direkt wiederverwendet

Der am **12.10.2026** bereits vom Nutzer gelieferte P03-Abnahmelog wurde als selektive, exakt wiedergegebene Testreferenz eingebunden. **Keine weitere Feldtestanforderung!**

- Treppe/HOME→LAGER: **2×** `P03 PROOF VERIFIED HOME_TO_LAGER via P03_2CAM_JV_THEN_WORK`.
- Treppe/LAGER→HOME: **2×** `P03 PROOF VERIFIED LAGER_TO_HOME via P03_2CAM_WORK_THEN_JV`.
- Abschluss: `P03 COMMISSIONING COMPLETE: OUT=2, IN=2`.
- Straße WORK_LEFT RuleID5: `RightToLeft = OUT`, `LeftToRight = IN`, mit je zwei Übertritten.
- Die Rohdatei enthielt drei vorhandene Kamera-Konfigurations-Exporte. Diese werden **nicht erneut angefordert**.

Implementierte Komponenten:

1. `libs/JVP03ProofLogReplay.php`: passiver Replay-Parser für bestehende Symcon-Protokolle. Erkennt auch zusammengeklebte `[Datum Uhrzeit]`-Datensätze, exakt gebundene Zweimastbeweise, Straßenrichtung, Dubletten, unvollständige Abschlussmeldungen und widersprüchliche Kamerarichtungen.
2. `JVPresenceIdentityShadow` (neue, **standardmäßig deaktivierte** Symcon-Instanz): liest nur den vorhandenen `Protocol`-Wert der bekannten P03-Hauptinstanz **#53879** nach Prüfung der echten Modul-GUID; optional `EvidenceJSON` einer bereits installierten Smartphone-Observer-Instanz. Kein Kamera-Poll, keine Socket-Erstellung, keine P03-Befehle, keine Fremdvariablen-Beschreibung.
3. `tests/JVP03ProofLogReplayTest.php`: historische Datensätze, Manipulation/Dubletten, Quell-GUID-/Typ-Prüfung, 100 identische Abfragen ohne zusätzliche Variablenschreibvorgänge, sichere Deaktivierung.
4. `tests/JVIdentityHypothesisEngineTest.php`: zusätzlich gesichert, dass eine unbestätigte Ausgangsinventur oder fehlerhafte Ereigniskette **keine namentliche Personenfeststellung** erlaubt.

Die Shadow-Instanz besitzt **genau zwei** Ausgabevariablen (Status/JSON) und maximal einen rein lokalen 60-s-Lesetimer. Eingaben `P03InstanceID` und `PhoneObserverInstanceID` sind auswählbar, letzterer standardmäßig `0` (= ignorieren). Bei `Enabled=false` entstehen keine Quellabfragen. Die Instanz ist als zukünftige optionale Leseergänzung gedacht, **noch nicht automatisch installiert**.

### Was P03 trotz bestandenem Gehtest bewusst noch NICHT beweist

- Ob bei einem einzelnen IVS-Ereignispaar eine oder mehrere **gleichzeitig** gehende Personen vorlagen.
- Ob ein gewisser Durchgang eindeutig Johannes, Carina oder einem Besucher gehört.
- Ob sämtliche Türen, Grundstücksausgänge und Umwege lückenlos beobachtet werden.
- Ob aus HOME→WORK bereits ein Verlassen des **gesamten Grundstücks** folgt. (Nein.)

Deshalb meldet der passive Parser `personCountCertified=false` und `identityCertified=false`. Der neue Shadow-Observer darf niemals ein bestätigtes Leerstandszertifikat oder persönliche AWAY-Buchungen schreiben. Die präzise Anzahl sowie individuelle Namen werden erst durch unabhängig verifizierte Zählung, Personenausgangsinventur und entsprechende Identitätshinweise freigeschaltet. P03-Kamera- oder Geh-Test **nicht** wiederholen.

## Weitere Integration: vorhandene LCN/GUS-, Licht-, Tor- und Fahrzeugsignale (13.10.2026)

Ein neuer **rein lokaler** Normalisierer `libs/JVLocalSensorEvidence.php` interpretiert ausschließlich bestehende **Boolean- oder Integer-Statusvariablen** (Boolean=true bzw. Integer>0 = aktiv). Bis zu 64 frei zugeordnete, stabile Quellen-IDs und Zonen (HOME, WORK, PROPERTY_OTHER) können in der vorhandenen neuen `JVPresenceIdentityShadow`-Instanz konfiguriert werden. Es wird **keine** unbekannte Variable geraten, kein LCN-Aktor angesteuert und keine neue Hardware installiert.

Signalrollen:

- `MOTION` – gewöhnliche vorhandene GUS (Mensch nur möglich, Identität unbekannt)
- `MOTION_KAMIN` – **EG Wohnen Kamin**, Uhr-Fehlalarme jede Stunde im Zeitraum Minute **25 bis einschließlich 35** vollständig ignorieren; außerhalb nur zusammen mit separatem `MOTION_KITCHEN`-Impuls im 60-s-Fenster *stärker* bestätigen
- `MOTION_KITCHEN` – EG Wohnen Küche, Bestätigung für Kamin außerhalb Uhrfilter
- `LIGHT`, `GATE`, `VEHICLE` – aktuelle lokale Schalt-/Statushinweise; weder Fahrer noch Bewohner; `allConfiguredLightsOff` bedeutet **nur die ausgewählten** Lichtquellen

Ein Bewegungsimpuls ist stärker, wenn **zwei unterschiedliche** GUS derselben Zone innerhalb 60 Sekunden reagieren. Der gleiche GUS zweimal, ein alleiniger Kamin-Impuls oder zwei Melder in verschiedenen Zonen liefern **keinen** verifizierten Menschen- oder Identitätsnachweis. Eine positive Aktivitätskombination bleibt in jedem Fall nur `CORROBORATED_MOTION_HINT`; auch dann dürfen keine PresenceCore-Personen oder Leerstands-Zertifikate verändert werden.

Technisch:
- Nutzt `VariableChanged` aus bestehenden IP-Symcon-Statusvariablen, nicht bloß `VariableUpdated`. Es werden nur neue positive Flanken und keine alten anstehenden Signale als Bewegungen gewertet. Neue Sensoren werden auch im laufenden Betrieb zunächst eingemessen.
- Verwendet zusätzlich die dokumentierte Symcon-Meldung **VM_UPDATE = 10603**. Die Shadow-Instanz registriert sie **ausschließlich** für explizit zugeordnete und validierte lokale Variablen und reagiert damit zeitnah auf kurze GUS-Pulse. Subscriptions werden bei Quelländerungen, Modul-Deaktivierung und Service-Neustart abgeglichen. Der 60-Sekunden-Timer ist nur die lesende Reserve.
- Das unveränderte P03-Modul und der Smartphone-Observer sind keine GUS-Meldequellen, sodass Ausgaben keine Endlosschleife auslösen.
- Maximal **zwei** Symcon-String-Ausgabevariablen für das gesamte Identity-Shadow-Modul; die einzelnen Sensoren erzeugen **keine** neue lizenzpflichtige Variable. Interne Ereignishistorie und Beobachterzustand liegen ausschließlich im Buffer.
- Ein Sensorwert kann durch Neustart, falschen Typ oder fehlende Variable unklar sein; dann wird er `UNKNOWN`. Kein Sensorstatus `OFF`, geschlossene Tore oder ruhiger Bewegungsmelder erlaubt die Aussage „alle Personen AWAY“.

Regressionstest `tests/JVLocalSensorEvidenceTest.php` plus die erweiterte `tests/JVP03ProofLogReplayTest.php` prüfen Start-Baseline, Uhr-Minuten-Grenzen, Küchenbestätigung, zwei GUS, verschiedene Zonen, aktive Lichter, Tore/Fahrzeuge, falsche Typen, Duplikate, Quellenwechsel, Update-Subscriptions und wiederholte identische Symcon-Werte.

**Live-Datenquelle:** Die genauen IDs der LCN-GUS-/Licht-/Torausgänge sind noch nicht in dieser Entwicklungsumgebung verfügbar. Deshalb sind die neuen Quellzeilen bei Auslieferung **leer** (fail-closed), bis sie anhand realer existierender IP-Symcon-Objekte eindeutig zugeordnet werden. Die vorhandenen P03-Gehtests und Kamera-Exporte müssen dafür **nicht erneut** bereitgestellt werden.
