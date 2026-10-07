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
