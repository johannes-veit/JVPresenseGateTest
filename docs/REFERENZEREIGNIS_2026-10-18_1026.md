# Praxisreferenz 18.10.2026, 10:26–10:27 (Europe/Berlin)

## Vom Bewohner bestätigter tatsächlicher Ablauf – Ground Truth

| Zeitpunkt | Person | Beobachteter Ablauf |
|---|---|---|
| 10:26 | Johannes und Carina | Beide verlassen gleichzeitig das Haus |
| 10:27 | Carina | Fährt mit ihrem grauen **Seat Leon** im Auto weg |
| 10:27 | Johannes | Geht zu Fuß zur Firma |

Diese Angaben stammen vom Bewohner und sind **Referenz für den späteren Vergleich mit dem kopierten SHADOW-TestReport #40890**. Sie sind **keine automatisch vom Modul erkannten Kamera-, Fahrzeug- oder Identitätsnachweise**.

## Erwartung an den nächsten SHADOW-Abgleich

- **P03 (zwei Mastkameras)**: Falls beide auf der Haus-/Lagerplatz-Treppe erfasst wurden, kann es zeitnahe *anonyme* Human-/IVS-Ereignisse oder eine mehrdeutige Mehrpersonen-Passage geben. Kameraüberwachungslücken oder Unterschiede im Reiseweg können einzelne Ereignisse verhindern. Die Meldung `TEST_AND_PRODUCTION_OFF` reicht nicht als Live-Fahrt-/Wegbeweis.
- **LCN GUS**: Mögliche zeitnahe anonyme Räume-/Flurbewegungen vor dem Verlassen; keine sichere Unterscheidung Johannes/Carina.
- **Smartphones**: Letzte bestätigte Haus-WLAN-MACs, beobachtete persönliche Geofence-OUT/IN-Wechsel und aktuelle SSIDs prüfen. Ein breiter Geofence umfasst Haus und Firma; SSID-Standby, Zeitstempelalter und ausgetauschte Symcon-Quellen beachten. Keine stille Abwesenheitszertifizierung.
- **Carinas Fahrzeug**: Das bekannte Modell/Farbe (**grauer Seat Leon**) ist ein *Bewohner-Referenzmerkmal*, nicht die Ausgabe eines Dahua-Vehicle-Klassifikators. Um die Fahrt einem Videoereignis zuzuordnen, müssen die wirklich angeschlossenen Kameras ein qualifiziertes Fahrzeugereignis mit Zeit und **Richtung** liefern. Modell/Farbe allein sind kein eindeutiger Fahrzeugbeweis; Straßen-`Human`, NumberStat-Schwelle und Torstatus ebenfalls nicht.
- **Johannes zu Fuß**: Gegebenenfalls Wegfolge über die verifizierte Haus-/Lagerplatz-Treppe und aktuelle persönliche Firmen-SSID prüfen. Das Fehlen eines zugeordneten Fahrzeugereignisses allein beweist keinen Fußweg.
- **Mehrpersonenfall**: Gleichzeitig anonyme OUTs und ein späteres IN werden nicht willkürlich einer Person zugewiesen. `ambiguousOutstanding` und `lastAmbiguousAt` prüfen.

## Was beim Vergleich geliefert werden soll

Bei Eingang des nächsten Protokolls: je Person die **tatsächlich beobachteten** Zeitstempel nach Quelle, Differenz zur Referenzzeit, mögliche fehlende oder veraltete Signale, falsche persönliche An-/Abreisebehauptungen und die offene Fahrzeug-/Fußwegklassifikation nennen. Den Ground-Truth-Verlauf nicht in die Live-Beweismotorik einspeisen und keine künstlichen Beweisereignisse erzeugen.

**Sicherheitsgrenze:** Keine Person wurde durch Kamera identifiziert; kein Fahrzeugtyp/Farbe in der aktuellen SHADOW-Quelle verifiziert; `propertyEmptyCertified=false`, `mayArmAlarm=false`.
