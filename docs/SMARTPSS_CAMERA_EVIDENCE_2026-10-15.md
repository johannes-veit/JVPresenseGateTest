# Verifizierter Dahua-Bestand – SmartPSS-Screenshots 15.10.2026

## Quelle und Vertrauensstufen

Vom Anwender gelieferte SmartPSS-Liste zeigt **10/10 Online** im SmartPSS-Client: **1 VTO4202F**, **7 IPC-HFW5442E-ZE**, **2 IPC-PDW3849-A180-AS-PV (TiOC Duo)**. Diese Kamera-Modellinformation stammt aus den SmartPSS-Spalten `Name`, `Device Model`, `Online Status`, **nicht aus einem Kamera-CGI-Zugriff der SymBox**.

Die sechs zusätzlich gelieferten Einzelbilder `TCP/IP …` belegen für folgende Zuordnung den **tatsächlichen Kamera-IP-Dialog** (nicht nur ältere Projektlisten):

| Name | Modell aus SmartPSS | IP direkt aus TCP/IP-Dialog | P03-Rolle |
|---|---|---|---|
| Lagerplatz JV links | IPC-HFW5442E-ZE | 192.168.107.95 | P03 Mast JV_LEFT – geschützt |
| Lagerplatz JV rechts | IPC-HFW5442E-ZE | 192.168.107.96 | Weitere IPC |
| Lagerplatz Werkstatt links | IPC-HFW5442E-ZE | 192.168.107.94 | P03 Mast WORK_LEFT – geschützt |
| Lagerplatz Werkstatt rechts | IPC-HFW5442E-ZE | 192.168.107.99 | Weitere IPC |
| JV Terrasse | IPC-PDW3849-A180-AS-PV | 192.168.107.110 | TiOC optionales Panorama |
| JV Hof Garage | IPC-PDW3849-A180-AS-PV | 192.168.107.111 | TiOC optionales Panorama |
| Farbcontainer | IPC-HFW5442E-ZE | **nicht im gezeigten TCP/IP-Dialog bestätigt** (bisher .92) | Zusatz-IPC |
| Torhalle Straße | IPC-HFW5442E-ZE | **nicht im gezeigten TCP/IP-Dialog bestätigt** (bisher .98) | Prioritärer Zähl-Kandidat, wenn Straßenweg sichtbar |
| neben Torhalle Einfahrt | IPC-HFW5442E-ZE | **nicht im gezeigten TCP/IP-Dialog bestätigt** (bisher .97) | Zweiter Zähl-Kandidat, wenn Weg sichtbar |
| Büro (VTO) | VTO4202F | nicht geprüft | **Keine IPC-Personenzählquelle** |

**Wichtig:** Die SmartPSS-Anzeige „Online“ beweist nur die Verbindung aus SmartPSS, nicht die Erreichbarkeit des SymBox VLAN 103 zu Kamera VLAN 107 und schon gar nicht die Funktionsfähigkeit des P03-Eventstreams. P03 bleibt laut letztem `EvidenceJSON` mit `BLOCKIERT – Mastkamera-Stream abgebrochen` stehen.

## Hardwarefähigkeit versus tatsächlich verifizierte Funktion

Das Dahua-Datenblatt für **IPC-HFW5442E-ZE** nennt *Tripwire People Counting*, *People Counting in Area* und *Queue Management*. Das ältere Modell-Datenblatt nennt drei **umschaltbare** Smart-Ressourcen IVS / Face / People Counting. Nicht automatisch umschalten: Die beiden Mastkameras brauchen ihre bestehende IVS für P03.

Offizielle Quelle: https://www.dahuasecurity.com/de/products/All-Products/Network-Cameras/WizMind-8-Series/5-Series/4MP/IPC-HFW5442E-ZE

Die neun IPC-Geräte gehören zu zwei Modellen; aber **kein einziges der gelieferten Bilder zeigt den derzeit aktiven Smart-Plan, eine funktionierende People-Counting-Linie oder zwei individuelle ObjectID/echte +2-Counter-Änderungen beim gemeinsamen Gehen**. Daher bleibt `exactPeopleCount=UNKNOWN`.

## Empfohlene Prüf-Reihenfolge (keine automatische Aktivierung)

1. **Torhalle Straße** (bisherige IP-Hypothese .98): höchste Relevanz für Grundstück/Straße, **falls** beide nebeneinander gehende Personen an der passenden Linie vollständig sichtbar sind. Modell aus SmartPSS bestätigt, exakte IP und Smart-Plan noch offen.
2. **neben Torhalle Einfahrt** (historische IP-Hypothese .97): möglicher zweiter unabhängiger Blick auf Torzufahrt/Gehweg. Auch hier zuerst IP/Ansicht/Smart-Plan prüfen.
3. **Lagerplatz JV rechts** (.96) und **Werkstatt rechts** (.99): Modell und IP bestätigt. Nur geeignet, wenn ihr Blickfeld die zu verifizierende Weggrenze erfasst; kein Beleg dafür im reinen Geräte-Screenshot.
4. **Farbcontainer** (historisch .92): Modell bestätigt, IP noch zu prüfen; aufgrund Ortslage kein automatisch angenommener Straßengrenznachweis.
5. **JV_LEFT .95 / WORK_LEFT .94:** bestehende P03-IVS-Funktionen erhalten. **TiOC Terrasse .110 / Hof .111:** Panorama-/IVS-Ergänzung, keine im Datenblatt genannte native Personenzählung.

Eine identische Person kann in mehreren Kameras erscheinen. Deshalb dürfen zwei IVS-/SMD-Alarme niemals als zwei Personen gezählt werden. Verlässlicher Zwei-Personen-Nachweis erfordert erfolgreiches Zählverfahren, richtige Richtung, Sicht auf die tatsächliche Grundstücksgrenze, zeitliche Korrelation und Testläufe zu zweit in Abständen/nebeneinander bei Tag und Nacht. Ein Zählwert beweist **keine Identität**.

## Bekannte Symcon-Zuordnungskollision

Die Symcon-Konfiguration für die zusätzliche Kamera `192.168.107.99` enthält einen Instanznamen **„P03 – Lagerplatz Werkstatt links“**, während SmartPSS **physisch .99 = Lagerplatz Werkstatt rechts** zeigt. Das ist ein **Widerspruch im Instanznamen**, kein Beweis, dass das Gerät im SmartPSS falsch konfiguriert ist. Die Shadow-Inventur zeigt `roleNameConflictNeedsReview=true`. Keine automatische Umbenennung oder Re-Verknüpfung.

## Unveränderte Grenzen

Keine Kamera-IP-/Netzwerkkonfiguration, kein CGI-Schreibbefehl, kein Smart-Plan, keine IVS-Filter, kein P03-Observer, keine LCN-/Alarm-/Schiebetor-Funktion wird durch die Inventur verändert. Vorliegende Symcon-Kamera-Zählquellen fehlen weiterhin; die neue Revision verbessert **nur belegte Metadaten und Prüf-Prioritäten**.
