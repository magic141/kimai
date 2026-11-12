#!/usr/bin/env bash
# Plesk: Git -> „Zusätzliche Bereitstellungsaktionen":  bash scripts/post-deploy.sh
#
# Aktualisiert eine BESTEHENDE Kimai-Installation (Datenbank vorhanden):
# composer install -> kimai:update (Migrationen, Cache, Assets).
# Für eine Neuinstallation einmalig stattdessen `bin/console kimai:install -n`
# als geplante Aufgabe ausführen.
#
# Optionale Werte in der .env (nur auf dem Server, nicht eingecheckt):
#   PHP_BIN=/opt/plesk/php/8.1/bin/php     # sonst: höchste Plesk-PHP-Version, die zur composer.json passt
#   COMPOSER_PHAR=/pfad/zu/composer.phar   # sonst: ./composer.phar bzw. Plesk-Composer
#   DEPLOY_NOTIFY_EMAIL=admin@example.org  # Mail bei Abbruch
#
# Jeder Schritt wird zusätzlich in var/log/post-deploy.log protokolliert
# (Plesk zeigt die Ausgabe der Bereitstellungsaktion oft nicht an).
# Eine Sperre (var/deploy.lock) verhindert zwei gleichzeitige Läufe.
# Ohne Composer auf dem Server: ein fertig hochgeladenes vendor/ wird verwendet.
#
# Minimale Umgebung: kein sicherer PATH/HOME im Deploy-Kontext, daher nur
# bash-Builtins und absolute Pfade.
set -euo pipefail
cd "${0%/*}/.."

PROJ="${PWD##*/}"

# ── Plausibilität: richtiger Ordner? ──────────────────────────────────────────
if [ "$PROJ" = "public" ]; then
  echo "X Das Skript liegt in public/. Der Bereitstellungspfad in Plesk muss der Anwendungsstamm sein (Elternordner von public/)."
  exit 1
fi
if [ ! -f bin/console ]; then
  echo "X bin/console fehlt in $PWD – falscher Ordner oder unvollständige Bereitstellung."
  exit 1
fi

# ── Log und Sperre ────────────────────────────────────────────────────────────
mkdir -p var/log
LOG="$PWD/var/log/post-deploy.log"
log() {
  local ts
  ts="$(printf '%(%Y-%m-%d %H:%M:%S)T' -1 2>/dev/null)" || ts="-"
  printf '%s\n' "$*"
  printf '%s %s\n' "$ts" "$*" >> "$LOG"
}
# Führt einen Befehl aus und schreibt seine Ausgabe in die Konsole und ins Log.
run_logged() {
  "$@" 2>&1 | while IFS= read -r _l || [ -n "$_l" ]; do
    printf '%s\n' "$_l"
    printf '%s\n' "$_l" >> "$LOG"
  done
}

LOCK="$PWD/var/deploy.lock"
if ! mkdir "$LOCK" 2>/dev/null; then
  _pid=""
  if [ -f "$LOCK/pid" ]; then read -r _pid < "$LOCK/pid" || true; fi
  if [ -n "$_pid" ] && kill -0 "$_pid" 2>/dev/null; then
    log "X Ein anderer Deploy läuft bereits (PID $_pid) – Abbruch. Erst dessen Ende abwarten."
    exit 1
  fi
  log "! Verwaiste Sperre eines beendeten Laufs entfernt."
  rm -rf "$LOCK"
  mkdir "$LOCK"
fi
printf '%s\n' "$$" > "$LOCK/pid"
trap 'rm -rf "$LOCK"' EXIT
log "==> Deploy gestartet in $PWD (PID $$)"

# ── .env-Wert lesen (ohne Coreutils) ──────────────────────────────────────────
env_value() {
  local key=$1 line val=""
  [ -f .env ] || return 0
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      "$key"=*)
        val="${line#"$key"=}"
        val="${val%\"}"; val="${val#\"}"
        break ;;
    esac
  done < .env
  printf '%s' "$val"
}

# ── Fehler-Benachrichtigung ───────────────────────────────────────────────────
DEPLOY_NOTIFY_EMAIL="$(env_value DEPLOY_NOTIFY_EMAIL)"
notify_failure() {
  local msg="Deployment von ${PROJ} fehlgeschlagen (Zeile $1). Siehe Plesk -> Git -> Deployment-Log."
  log "X $msg Details: $LOG"
  if [ -n "$DEPLOY_NOTIFY_EMAIL" ] && [ -x /usr/sbin/sendmail ]; then
    printf 'To: %s\nSubject: Deploy fehlgeschlagen: %s\n\n%s\n' \
      "$DEPLOY_NOTIFY_EMAIL" "$PROJ" "$msg" | /usr/sbin/sendmail -t 2>/dev/null || true
  fi
}
trap 'notify_failure $LINENO' ERR

# ── PHP auflösen ──────────────────────────────────────────────────────────────
# 1) PHP_BIN aus der .env (manuelle Übersteuerung, wird nur auf Erweiterungen geprüft)
# 2) sonst: höchste installierte Plesk-PHP-Version, die zum "php"-Constraint der
#    composer.json passt UND alle nötigen Erweiterungen hat (die Kommandozeilen-PHP
#    von Plesk hat pro Version eigene Erweiterungen, unabhängig vom Webserver).
EXTRA_EXT=""
case "$(env_value DATABASE_URL)" in
  mysql:*|mariadb:*) EXTRA_EXT="pdo_mysql" ;;
esac

PHP="$(env_value PHP_BIN)"
_cand_ver=""   # höchste zur composer.json passende Version, der Erweiterungen fehlen
_cand_missing=""
_seen=""       # gefundene PHP-Binaries (für die Fehlermeldung)
_any=""
PHP_PATTERNS="${PHP_GLOB:-/opt/plesk/php/*/bin/php /usr/local/php*/bin/php /opt/php*/bin/php /opt/alt/php*/usr/bin/php /opt/remi/php*/root/usr/bin/php /usr/bin/php /usr/bin/php[0-9]* /usr/local/bin/php /usr/local/bin/php[0-9]*}"
_path_php="$(command -v php 2>/dev/null || true)"

if [ -n "$PHP" ]; then
  if [ ! -x "$PHP" ]; then
    log "X PHP_BIN ist nicht ausführbar: $PHP"
    exit 1
  fi
  if ! _cand_missing="$("$PHP" scripts/php-check-extensions.php composer.json $EXTRA_EXT)"; then
    _cand_ver="$PHP"
    PHP=""
  fi
else
  _best=""
  for _p in $PHP_PATTERNS $_path_php; do
    [ -x "$_p" ] || continue
    _v="$("$_p" -r 'echo PHP_VERSION;' 2>/dev/null)" || continue
    case "$_v" in [0-9]*.[0-9]*.*) ;; *) continue ;; esac   # php-fpm, php-cgi u. ä. überspringen
    _seen="$_seen $_p($_v)"
    [ -n "$_any" ] || _any="$_p"
    "$_p" scripts/php-matches-composer.php composer.json || continue
    if _m="$("$_p" scripts/php-check-extensions.php composer.json $EXTRA_EXT)"; then
      # die höchste passende, vollständige Version gewinnt
      if [ -z "$PHP" ] || "$_p" -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' -- "$_v" "$_best"; then
        PHP="$_p"; _best="$_v"
      fi
    else
      _cand_ver="$_p"; _cand_missing="$_m"
    fi
  done
fi

if [ -z "$PHP" ]; then
  if [ -n "$_cand_ver" ]; then
    log "X PHP $_cand_ver passt zur composer.json, es fehlen aber Erweiterungen: $_cand_missing"
    log "  Erweiterungen für diese PHP-Version installieren/aktivieren lassen oder eine andere Version bereitstellen."
  else
    _need=""
    if [ -n "$_any" ]; then _need="$("$_any" scripts/php-matches-composer.php composer.json --print 2>/dev/null || true)"; fi
    log "X Kein passendes PHP gefunden. composer.json verlangt: ${_need:-unbekannt}."
    log "  Gefundene PHP-Binaries:${_seen:- keine}"
    log "  Gesucht in: $PHP_PATTERNS${_path_php:+ (PATH: $_path_php)}"
    log "  Pfad der gewünschten PHP-Binary als PHP_BIN in die .env eintragen."
  fi
  exit 1
fi
log "==> PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;')), Erweiterungen vollständig"

# ── Composer auflösen ─────────────────────────────────────────────────────────
COMPOSER="$(env_value COMPOSER_PHAR)"
if [ -z "$COMPOSER" ]; then
  for _c in "$PWD/composer.phar" \
            /usr/local/psa/var/modules/composer/composer.phar \
            /usr/lib/plesk-9.0/composer.phar; do
    if [ -f "$_c" ]; then COMPOSER="$_c"; break; fi
  done
fi

if [ ! -f .env ]; then
  log "X .env fehlt im Anwendungsstamm. Einmalig anlegen (DATABASE_URL, APP_SECRET, APP_ENV=prod), siehe .env.dist."
  exit 1
fi

if [ -n "$COMPOSER" ] && [ -f "$COMPOSER" ]; then
  log "==> Composer: $COMPOSER"
  # HOME ist im Deploy-Kontext oft / oder leer -> Composer-Verzeichnisse im Projekt ablegen.
  export COMPOSER_HOME="$PWD/var/cache/composer-home"
  export COMPOSER_CACHE_DIR="$PWD/var/cache/composer-cache"
  export COMPOSER_ALLOW_SUPERUSER=1
  mkdir -p var/cache
  log "==> Abhängigkeiten installieren (composer install)"
  run_logged "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress
elif [ -f vendor/autoload.php ]; then
  log "! Kein Composer gefunden – das vorhandene vendor/ wird verwendet."
else
  log "X Weder composer.phar noch vendor/ vorhanden. COMPOSER_PHAR in .env setzen, Plesk-Composer-Erweiterung installieren oder vendor/ hochladen."
  exit 1
fi

log "==> Assets installieren"
run_logged "$PHP" bin/console assets:install public --env=prod

log "==> Kimai aktualisieren (Migrationen, Cache)"
run_logged "$PHP" bin/console kimai:update -n

log "OK: Deployment abgeschlossen."
