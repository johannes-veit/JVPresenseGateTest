# JV Presence Gate Test v0.1.5

Separates IP-Symcon-Testmodul für die erste reale Presence-Grenze **P05 HOME_CORE ↔ OUTSIDE am Schiebetor**.

## Ziel

Nach Installation muss nichts programmiert werden. Das Modul:

1. findet automatisch die bestehende `AussenlichtAutomatik2`-Instanz **JV Hof Garage**,
2. übernimmt Host/Port/Benutzer/Passwort zur Laufzeit, ohne die Zugangsdaten in diesem Modul zu speichern oder anzuzeigen,
3. baut einen **eigenen** Dahua-Eventstream auf – das Außenlichtmodul wird nicht verändert,
4. liest die vorhandene IVS-Konfiguration,
5. legt, falls nötig, die Regel `P05_HOME_STREET` als `CrossLineDetection` an,
6. verwendet eine auf das gelieferte Bild `JV Hof Garage` vorbereitete Tripwire-Geometrie,
7. prüft, ob die bestehende `SmartMotionDetect`-Personenerkennung dabei aktiv bleibt,
8. zeichnet vier kontrollierte Grenzübertritte auf und ermittelt automatisch die Dahua-Richtung.

## Morgen: Bedienung

Nach Installation nur:

1. Instanz **JV Presence Gate Test** öffnen.
2. **Test vorbereiten & starten** drücken.
3. Warten bis Variable `Ergebnis / Nächster Schritt` mit **BEREIT** beginnt.
4. Dann normal durch das Schiebetor:
   - OUT
   - IN
   - OUT
   - IN
5. Nach jedem erkannten Durchgang zeigt das Modul den nächsten Schritt an.
6. Nach dem vierten Durchgang den Inhalt von `Testprotokoll` an ChatGPT schicken.

## Schutzmaßnahmen

- Das bestehende Außenlichtmodul wird **nicht** aktualisiert oder verändert.
- Es wird kein vorhandener IVS-Regelplatz überschrieben. Ist kein freier Regelplatz vorhanden, bricht das Modul ab.
- Wird erkannt, dass die Aktivierung von IVS die vorhandene SmartMotion-Personenerkennung abschaltet, wird die Testregel deaktiviert und der vom Modul geänderte globale IVS-Modus zurückgesetzt.
- Ein bereits vorhandener fremder AI-Smart-Plan wird nicht überschrieben.
- Zugangsdaten werden nicht in Diagnosevariablen oder das Testprotokoll geschrieben.

## Vorbereitete Tripwire

Dahua-Normkoordinaten `0..8191`:

- A = `(4400, 3000)`
- B = `(7600, 4300)`
- Direction = `Both`

Die Linie wurde anhand des bereitgestellten unveränderten Nachtbildes der Kamera `JV Hof Garage` so gewählt, dass der Zufahrts-/Torweg auf der Grundstücksseite gekreuzt wird. Die Werte können im erweiterten Bereich angepasst werden, falls der reale erste Test zeigt, dass die Linie versetzt werden muss.

## Voraussetzungen

- IP-Symcon 9.0
- bestehende `AussenlichtAutomatik2`-Instanz `JV Hof Garage`
- Dahua-Kamera erreichbar
- `curl` in IP-Symcon (im vorhandenen SymBox-Backup bereits von anderen installierten Modulen genutzt)


## Hinweis für IPC-PDW3849-A180-AS-PV / Taurus-Web5

Diese Firmware kann vorhandene IVS-Regeln per CGI lesen und deren Events streamen, lehnt aber das Anlegen einer neuen CrossLineDetection über den alten `configManager.cgi`-Schreibweg ab. Deshalb wird einmalig im Kamera-Webinterface eine Tripwire angelegt. Danach erkennt das Modul sie automatisch und der eigentliche OUT/IN-Test bleibt vollständig automatisch.


## RPC2-Diagnose

Wenn noch keine Tripwire vorhanden ist, führt v0.1.5 automatisch eine nur-lesende Web5/RPC2-Diagnose aus. Sie liest die aktuelle und die Default-`VideoAnalyseRule`-Tabelle aus der Kamera. Daraus kann der nächste Stand den sicheren automatischen Writer für genau diese Firmware ableiten. Bei der Diagnose wird nichts geschrieben.
