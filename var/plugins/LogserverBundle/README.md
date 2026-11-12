# LogserverBundle (Kimai-Plugin)

Schickt Anmeldungen und Einstellungsänderungen an den Logserver-Collector (`lsvc.shallowwater.de`), damit sie in Grafana zusammen mit den Logs der anderen Dienste durchsuchbar sind. Kimai selbst führt nur den Zeitpunkt der **letzten** Anmeldung, aber keinen Verlauf.

## Was gemeldet wird

| Typ (`type`) | Ereignis | Level |
|---|---|---|
| `auth` | Anmeldung über die Weboberfläche (Passwort, Magic-Link, SAML, Remember-Me) | info |
| `auth` | Fehlgeschlagene Anmeldung (Web und API), auch bei Sperre durch zu viele Versuche | warn |
| `auth` | Abmeldung | info |
| `auth` | 2FA erfolgreich / fehlgeschlagen | info / warn |
| `auth` | Benutzerwechsel durch einen Administrator | warn |
| `settings` | Systemeinstellung angelegt, geändert, entfernt | info |
| `settings` | Berechtigung einer Rolle geändert, Rolle angelegt/gelöscht | info |
| `settings` | Benutzer angelegt, gelöscht, Rollen, Aktivierung, 2FA, Benutzername, E-Mail, Passwort oder API-Token geändert | info |
| `settings` | Benutzereinstellung (Profil) geändert | info |

Erfolgreiche API-Anmeldungen werden nicht gemeldet, die API löst sie bei jedem Aufruf aus. Bei aktivem 2FA erscheint „Anmeldung erfolgreich“ nach dem Passwort und danach „2FA erfolgreich“.

Jeder Eintrag enthält im Feld `meta` u. a. `user`, `ip`, `path`, `user_agent` bzw. bei Änderungen `by` (wer), `source` (`web` oder `cli`) und `ip`.

## Was nicht übertragen wird

Werte von Systemeinstellungen und Benutzereinstellungen (können Zugangsdaten enthalten, z. B. Mail-Zugang), Passwörter und Hashes, E-Mail-Adressen und Token. Bei diesen Feldern wird nur gemeldet, **dass** sie geändert wurden. Die Werte der Felder `roles`, `enabled`, `totpEnabled` und `systemAccount` werden mit altem und neuem Wert übertragen.

IP-Adressen und Benutzernamen (auch bei fehlgeschlagenen Anmeldungen, so wie getippt) sind personenbezogene Daten. Der Collector hält sie nur kurz vor (`RETENTION_DAYS`), die Langzeit-Retention liegt in Loki.

## Installation

1. Ordner `LogserverBundle` nach `var/plugins/` des Kimai-Anwendungsstamms legen (der Ordnername darf keine Versionsnummer enthalten).
2. In der `.env` des Anwendungsstamms ergänzen:

```
LOGSERVER_URL=https://lsvc.shallowwater.de
LOGSERVER_PROJECT=kimai
LOGSERVER_LEVEL=info
```

   `LOGSERVER_URL` muss HTTPS verwenden (Ausnahme: `localhost`). Ohne `LOGSERVER_URL` bleibt das Plugin stumm. `LOGSERVER_PROJECT` darf nur `a-z`, `0-9`, `_` und `-` enthalten.
3. Cache neu aufbauen (`bin/console kimai:update -n` bzw. der normale Deploy) und die Seite einmal aufrufen.
4. **Freigabe im Logserver:** Beim ersten Versand registriert sich das Plugin (`POST /api/register`). In Grafana → Warnungen (Projekt `log-collector`, Typ `registration`) erscheint „Neue Service-Registrierungsanfrage: kimai“ mit den Feldern `approve` und `deny`. Den `approve`-Link einmal öffnen. Spätestens eine Minute und einen Seitenaufruf danach holt Kimai den Key ab.

Einträge, die vor der Freigabe entstehen, gehen verloren.

## Funktionsweise

- Einträge werden während der Anfrage gesammelt und nach der Antwort (`kernel.terminate`) bzw. nach einem Konsolenbefehl gesendet. Zeitlimit: 2 Sekunden je Anfrage. Ist der Collector nicht erreichbar, verfallen die Einträge, Kimai läuft unbeeinträchtigt weiter.
- Der API-Key und der Zustand der Registrierung liegen in `var/data/logserver-plugin.json` (Rechte 0600). Die Datei überlebt Deployments. Wird der Key ungültig, registriert sich das Plugin neu, höchstens alle 10 Minuten.
- Wurde die Registrierung abgelehnt, sendet das Plugin nichts mehr. Datei löschen und im Collector die Anfrage entfernen, um es erneut zu versuchen.
- Einstellungsänderungen werden über Doctrine (`onFlush`/`postFlush`) erkannt, also unabhängig davon, ob sie über die Oberfläche, die API oder die Konsole entstehen. Gemeldet wird erst nach erfolgreichem Schreiben.
- Die IP stammt aus `Request::getClientIp()`. Hinter einem Reverse-Proxy (Plesk/nginx) muss in Kimai `TRUSTED_PROXIES` gesetzt sein, sonst steht dort die Adresse des Proxys.
- Ein Heartbeat entfällt, da Kimai als PHP-Anwendung keinen dauerhaften Prozess hat.

## Fehlersuche

| Symptom | Ursache |
|---|---|
| Keine Einträge im Logserver | `LOGSERVER_URL` fehlt oder nutzt `http://`, Registrierung noch nicht freigegeben, Level zu hoch, Cache nach dem Einspielen nicht neu aufgebaut |
| Registrierung erscheint nicht in Grafana | `var/data/` nicht schreibbar (dann wird bei jeder Anfrage neu registriert) oder Zugang zum Collector blockiert |
| Alle IPs sind `127.0.0.1` | `TRUSTED_PROXIES` in der `.env` fehlt |
| Nach Neuaufbau des Collectors keine Einträge | Der alte Key wird mit 401 abgewiesen, das Plugin registriert sich daraufhin neu, die neue Anfrage muss freigegeben werden |

## Tests

Im Anwendungsstamm (mit Entwicklungsabhängigkeiten, `composer install` ohne `--no-dev`):

```
vendor/bin/phpunit -c var/plugins/LogserverBundle/phpunit.xml.dist
```
