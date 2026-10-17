# Anwesenheit / SHADOW – verbindliche Quellen und Entscheidungsregeln

Stand: 17.10.2026, ab v0.8.67 Build 148. Dieses Dokument verhindert erneutes Raten bereits geprüfter Kamerarichtungen und unnötige Wiederholung von Tests.

## Bestand und Objekt-IDs

- IP-Symcon 9.0, SHADOW-Hauptinstanz **#32969**, P03-Hauptinstanz **#53879**, einziger kopierbarer Bericht **#40890** (Testergebnis an ChatGPT).
- Johannes Heim-WLAN: bool **#19980** und letzter echter MAC-Kontakt **#26497**.
- Carina Heim-WLAN: bool **#38430** und letzter echter MAC-Kontakt **#10760**.
- Getrennte WLAN-Skripte nicht verändern. Pollintervall ca. 300 s, TRUE-Haltefrist bis 660 s. Eine positive, für Kachel und gemeinsamen Anwesenheitshinweis verwertbare MAC-Bestätigung gilt nur 300 s. Das Timing führt bei Standby/Jitter zur zeitweisen Anzeige „NICHT BESTÄTIGT“, niemals zu bestätigter Abwesenheit.
- Johannes EgiGeoZone Smartphone-Zone „Zuhause“ **#41869**, Zeitstempel **#28055**; Carina iOS Geofency Zone „Zuhause“ **#39169**, Zeitstempel **#45666**. Der Radius umschließt **Haus und Firma**: IN bedeutet **nicht** Hauseingang; OUT bedeutet nicht sicher Grundstücksverlassen. Ein altes Zone=IN ist kein aktueller Heim-Standort.
- P03-Mastkameras: JV_LEFT Observer **#57215**, WORK_LEFT Observer **#27938**. Jeweils Treppen-IVS **RuleID 4, Human, Start** zählt als Einzelbeobachtung; Stop, SMD und generische Motion nicht. Reihenfolge **JV_LEFT → WORK_LEFT = HOME_TO_LAGER** und **WORK_LEFT → JV_LEFT = LAGER_TO_HOME**. WORK_LEFT RuleID 5 ist Straßen-IVS-Rohkontext, nicht Haus-Grenzübertritt und nicht automatisch ein Fahrzeug.
- Terrasse/TiOC und die 15 LCN-GUS-Melder sind zusätzliche, teils anonyme Hinweise, keine Personenidentifikation. Büro–Flur–Gäste-WC–Flur–Büro kann als anonymes Bewegungsmuster erkannt werden, ebenso Küche–OG-Flur–Ankleide. Zwei gleichzeitig anwesende Menschen lassen GUS-Sequenzen nicht eindeutig zuordnen.
- P03-Modus **TEST_AND_PRODUCTION_OFF** bedeutet, dass keine *neuen offiziellen verifizierten* P03-Übergänge gebucht werden, obwohl die Kameraobserver LIVE sein können. Frühere archivierte/verifizierte Ereignisse bleiben als vergangene Ereignisse sichtbar. SHADOW schaltet P03 nie selbst ein.

## Zustandslogik – bewusst unterschiedlich

1. **Aktuelles Handy im Heim-WLAN:** geprüfte Quelle, Bool TRUE, echter MAC-Kontakt höchstens 300 s alt. Die Person wird dadurch nicht physiologisch/geografisch zweifelsfrei lokalisiert.
2. **WLAN-Lücke:** keine neue MAC-Bestätigung für mehr als 300 s; unabhängig vom gespeicherten Bool kein aktueller Heimnachweis. Das ist keine Abfahrt. Kontakt nach >660 s ist ein *Netzwerk-Wiederkontakt*, keine Ankunft.
3. **P03-Richtung:** nur neuer verifizierter Zweimast-Beweis mit zulässigem Zeitstempel und korrekter Route. Ein einzelner P03-Übergang bleibt **anonym** und wird keinem oder zwei Bewohnern als persönliche Heimkehr zugeschrieben.
4. **Handy-Geofence-OUT:** nur mit valider Quelle und tatsächlich **neuem Ereigniszeitpunkt seit dem SHADOW-Start**. Ohne frisches Heimkontakt-Indiz kein persönlicher Abreisekandidat.
5. **Handy-Rückkehrhinweis:** bereits beobachtetes individuelles OUT → später individuelles IN → danach **neuer** validierter Heim-MAC-Kontakt innerhalb 30 min. Das belegt bestenfalls ein zurückgekehrtes Smartphone. Keine Kameraidentifizierung, keine automatische Personenzählung.
6. **Verifiziertes Firmen-WLAN:** nur bei echter, aktueller *personenbezogener SSID-Quelle*; ein bloßer Heim-WLAN-Ausschaltzustand reicht nicht. Bei gleichzeitig widersprüchlichen Quellen wird ein Konflikt gemeldet.
7. **Auto:** echtes, als Fahrzeug qualifiziertes Ereignis mit verifizierter Quelle und Richtung plus passende getrennte persönliche Smartphone-Evidenz wäre nötig. **Der Diagnosebericht vom 17.10. zeigte 0 zugeordnete Fahrzeug-Grenzsignale.** WORK_LEFT Street RuleID5 Human, Tor AUF/ZU oder allgemeine Human-Zähler dürfen nicht als Auto gelten.
8. **Haus leer, Alarm:** trotz aller obigen positiven Hinweise ohne gesicherte Personenzahl und Grundstücks-Grenzprüfung nicht zertifizierbar. Keine Außenmodule schreiben, keinen Alarm scharf stellen oder Geräte schalten.

## Gesicherter Praxisfall 17.10.2026

Der Benutzer bestätigte: **Nur Johannes** ging Büro → Flur → Wohnzimmer → Terrasse → Treppe → Lagerplatz → über Straße zur Werkstatt → gleicher Weg zurück (kurz Küche) → Büro. **Carina blieb währenddessen im Haus**. P03 erfasste verifizierte und rohe anonyme Ereignisse. Beide Telefone hatten danach erneut Heim-WLAN-MAC-Kontakte. Die alte Bewertung in v0.8.63 erklärte fälschlich **beide** als „wahrscheinlich zurückgekehrt“.

**Pflichtregression:** Kein anonymer P03-IN-Zeitpunkt darf beiden oder einem einzelnen Bewohner ohne eigene persönliche OUT/IN/MAC-Folge als persönliche Heimkehr zugeschrieben werden. Ein langes WLAN-Funkloch sowie ein neu bestätigter MAC-Kontakt sind ebenfalls keine Fahrt oder Rückkehr. Nicht erneut denselben Geh- oder Fahrtest verlangen.

## Technische Ausführung

- `libs/JVResidentEventFusion.php` ist rein funktional, konsumiert nur verifizierte lokale Berichte und gibt `report/next` zurück.
- Speicher: bestehende IP-Symcon **Instanzattribute**, nicht neue Symcon-Variablen. Das Ledger ist auf 24 h und max. 48 geprüfte P03-Beweisschlüssel beschränkt, plus Highwater gegen Replay; sichtbare Kachel zeigt **nur letztes** belegtes Handy-Ereignis.
- Erste Beobachtung und Wiederaufnahme nach mehr als 3600 s ohne SHADOW-Auswertung: **nur Baseline**, keine rückwirkend erfundene persönliche Rückkehr oder neue P03-Passage.
- Ausgaben: unveränderte bisherige sieben SHADOW-Variablen, `EvidenceJSON.residentEventFusion`, bestehende eine HTML-Kachel und ein kopierbarer `TestReport`.
- `tests/JVResidentEventFusionTest.php` simuliert den Praxisfall, Wiederholungen, Quelle/WLAN-Konflikte, Geo-Sequenz, Standby und längeren SHADOW-Neustart. GitHub PHP-Workflow für Syntax und Regressionen vorhanden.

## Offene Datenqualifikation, keine Aufforderung zu unnötigen Tests

Eine eindeutige Carina/Johannes-Zuordnung beim Auto ist derzeit nicht nachweisbar, weil konkrete verifizierte Fahrzeugereignisse und persönliche aktuelle WORK-SSID-Quellen im SHADOW-Datenmodell fehlen. Bestehende modul-/netzwerkfremde Konfigurationen nicht verändern. Neue Kamera- oder Netzwerktests nur, wenn ein konkreter neuer Quellennachweis aus bereits vorhandenen Daten nicht möglich ist.


## v0.8.68 – anonymer Hin-/Rückweg und Fahrzeugkontext

- **Neue Quelle**: `JVRouteEpisodeLedger::evaluate` erhält nur die streng verifizierten, seit letztem Durchlauf **neuen** Zweimast-P03-Ereignisse aus `JVResidentEventFusion`. Keine Roh-IVS-STOP-/Motion-/SMD-Ereignisse als vollständiger Übergang.
- `HOME_TO_LAGER` öffnet einen anonymen Hinweg. Nur bei genau **einem** offenen OUT-Kandidaten und einem späteren `LAGER_TO_HOME` innerhalb 12 h wird eine anonyme Folge mit `outAt`, `inAt`, Dauer dokumentiert. Das beweist weder die Gleichheit der Personen in beiden Kameradurchläufen noch deren Anzahl.
- Mehrere offene OUT-Kandidaten vor einem IN: `MULTIPLE_OUT_CANNOT_PAIR_UNIQUE_RETURN`, **keine** erzwungene Zuordnung. Ein isoliertes IN: `IN_WITHOUT_OBSERVED_OUT`.
- P03 `TEST_AND_PRODUCTION_OFF` mit frischer Diagnose **unterbricht** einen offenen Weg; ein neuer IN nach Wiederaufnahme darf nicht mit dem früheren OUT gepaart werden. Nach mehr als 3600 s ohne SHADOW-Durchlauf ebenfalls Baseline/Reset.
- Die bereits vorhandenen 15 LCN-GUS-Ereignisse (Büro/Flur/Küche), P03-Straßenregel 5 (Human), registrierte Tor- und Fahrzeugvariablen sind nur **zeitlicher Kontext**. Selbst eine aktive Fahrzeugvariable neben der P03-Passage hat zunächst weder Fahrzeugrichtung, Personidentität noch gesicherte Fahrweise. Bei `vehicleSignals=0` lautet der ehrliche Befund `NO_VERIFIED_VEHICLE_SIGNAL_CONFIGURED`.
- Speicherung über **ein weiteres persistentes Instanzattribut** `PersistRouteEpisodeLedger`, keine neue Symcon-Variable, Kamera- oder Netzwerkkonfiguration. Ergebnis liegt in `EvidenceJSON.anonymousRouteEpisodes`, in der bestehenden HTML-Kachel und dem einzigen kopierbaren Testbericht.
- Reproduzierbare Simulation: `tests/JVRouteEpisodeLedgerTest.php` im GitHub-Workflow `Presence PHP regressions` (Hin-/Rückweg, parallele Personen, fehlende/aktive Fahrzeugquelle, 60-minütiger SHADOW-Ausfall, P03 AUS dazwischen, falsche Quelle/Zeiten). Diese Tests benötigen **keine reale erneute Fuß- oder Autofahrt**.

Die Unterscheidung „Carina fährt mit Auto heim“ bleibt für die technische Freigabe offen, solange keine verifizierten Vehicle-Ereignisse und keine zuverlässige persönliche Zuordnung vorliegen. Die Kamera- und Smartphone-Quellskripte bleiben unverändert.


## v0.8.69 – Quellenübergreifende Handy-/Weg-Korrelation (nur lesen)

Die Klasse `JVResidentJourneyEvidence` nutzt **keine neue Kamera- oder WLAN-Quelle**, sondern ausschließlich `JVResidentEventFusion`, `JVRouteEpisodeLedger`, die verifizierten persönlichen SSID-Hinweise und `JVBoundaryEvidence`. Eine Person wird dadurch **nicht** in Video identifiziert.

- `PHONE_RETURN_AFTER_OUT_IN_AND_HOME_MAC`: ein **persönliches Smartphone** hat nach individuell beobachtetem OUT und IN wieder einen bestätigten Heim-MAC innerhalb von höchstens 20 Minuten nach dem *anonymen* P03-IN. Das ist nur eine zeitlich passende Smartphonefolge.
- `HOME_MAC_BEFORE_AND_WORK_SSID_AFTER_OUT`: für **dasselbe Smartphone** lag ein bestätigter Heim-MAC innerhalb von 15 Minuten **vor** dem anonymen P03-OUT und ein aktueller, persönlich verifizierter Firmen-WLAN-SSID-Nachweis höchstens 15 Minuten **danach** vor. Ein Home-MAC nach OUT, veraltete Firmen-SSID, `Privat-Veit-EXT` oder ohne eigene gültige Quelle werden abgewiesen.
- Ohne passende persönliche Sequenz bleibt der Korridor anonym. Mit genau einer Sequenz darf nur das **Handy als zeitlich passend** erscheinen. Mit zwei konkurrierenden Handys wird **keine** Identität zugeordnet. Kein Ergebnis erlaubt eine Aussage „Johannes fuhr Auto“, „Carina wurde von der Kamera erkannt“ oder „Grundstück ist leer“.
- Eine positive Fahrzeugvariable, rohes WORK_LEFT-RuleID5-Human und ein AUF/ZU-Signal sind jeweils nur Kontext. Solange keine real belegte, korrekt zugeordnete Fahrzeugklasse **mit Richtung und eindeutigem Verkehrskontext** in vorhandenen Quellen existiert, zeigt das Modul `NO_VEHICLE_SOURCE_MAPPED` oder `VEHICLE_SOURCE_ACTIVE_NEAR_ANONYMOUS_ROUTE_UNQUALIFIED`, niemals ein Fahr-/Fußwegzertifikat.
- Die neue Reifematrix `JVPresenceEvidenceReadiness` sammelt P03-Modus, Maststreamgesundheit, 15 GUS-Quellen, den aktuellen Status **jeder** persönlichen Firmen-SSID und die fehlende qualifizierte Fahrzeugrichtung in `nextEvidencePriority`. P03 bewusst AUS ist eine Betriebsart und **kein Kameraverbindungsfehler**.
- Die alte `JVResidentDepartureInference` darf in SHADOW nur noch ein tatsächlich im persistenten, einzelnen Handy-OUT-Ledger beobachtetes Geofence-OUT auswerten. Ein altes OUT-Boolean/Snapshot, beschädigtes Ledger oder eine reine WLAN-Erfassungslücke kann keine neue wahrscheinliche Abfahrt auslösen.
- Ausgaben bleiben: bestehende Kachel und `TestReport #40890`, plus `EvidenceJSON.residentJourneyCorrelation` und `EvidenceJSON.presenceEvidenceReadiness`. Keine weiteren Instanzvariablen oder Änderungen an P03, LCN, Alarm, Netzwerk, Geofency, EgiGeoZone oder Schiebetor.
