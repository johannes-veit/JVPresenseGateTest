# Identität und Abwesenheit ohne neue Hardware – SHADOW-Entwurf 2026-10-13

## Status / harte Freigabegrenze

- Neue reine PHP-Datei `libs/JVIdentityHypothesisEngine.php`, **kein installierbares Symcon-Modul**.
- Weder P03 noch P05, Kameras, Aussenlicht, Alarmanlage, LCN oder PresenceCore werden verändert.
- **Keine Anwesenheitsbuchungen oder Alarmfreigaben**: Ausgaben `personPresenceMayBeChanged=false`,
  `propertyEmptyMayBePublished=false`. Zertifikate im Bericht sind nur simulierte Hypothesen.
- Das laufende P03/P05 TEST/PRODUKTION-Gate bleibt zu. Ein realer P03-Gehtest mit Zählern,
  IVS-Richtung und der bestätigten dritten Kamera muss vor der Freigabe bestanden sein.
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
Nächster Schritt nach P03-Gehtest: P03/P05 Eventnormalisierung mit überprüften
Zeitstempeln, Zählgenauigkeit bei Gruppen, erfassten Eingängen/Ausgängen,
aktualisierbarem PersonLedger und tatsächlicher Coverage-Map; anschließend
Zertifikate erst nach kompletter Replay-/Fault-Injection-Regression freigeben.
