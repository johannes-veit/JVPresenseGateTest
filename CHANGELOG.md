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
