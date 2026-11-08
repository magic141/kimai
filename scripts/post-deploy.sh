#!/usr/bin/env bash
# Plesk: Git -> „Zusätzliche Bereitstellungsaktionen":  bash scripts/post-deploy.sh
#
# Aktualisiert eine BESTEHENDE Kimai-Installation (Datenbank vorhanden):
# composer install -> kimai:update (Migrationen, Cache, Assets).
# Für eine Neuinstallation einmalig stattdessen `bin/console kimai:install -n`
# als geplante Aufgabe ausführen.
#
# Optionale Werte in der .env (nur auf dem Server, nicht eingecheckt):
#   PHP_BIN=/opt/plesk/php/8.3/bin/php     # sonst: höchste installierte Plesk-PHP-Version
#   COMPOSER_PHAR=/pfad/zu/composer.phar   # sonst: ./composer.phar bzw. Plesk-Composer
#   DEPLOY_NOTIFY_EMAIL=admin@example.org  # Mail bei Abbruch
#
# Minimale Umgebung: kein sicherer PATH/HOME im Deploy-Kontext, daher nur
# bash-Builtins und absolute Pfade.
set -euo pipefail
cd "${0%/*}/.."

PROJ="${PWD##*/}"

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
  echo "X $msg"
  if [ -n "$DEPLOY_NOTIFY_EMAIL" ] && [ -x /usr/sbin/sendmail ]; then
    printf 'To: %s\nSubject: Deploy fehlgeschlagen: %s\n\n%s\n' \
      "$DEPLOY_NOTIFY_EMAIL" "$PROJ" "$msg" | /usr/sbin/sendmail -t 2>/dev/null || true
  fi
}
trap 'notify_failure $LINENO' ERR

# ── PHP auflösen ──────────────────────────────────────────────────────────────
PHP="$(env_value PHP_BIN)"
if [ -z "$PHP" ]; then
  for _p in /opt/plesk/php/*/bin/php; do
    [ -x "$_p" ] && PHP="$_p"   # Glob ist aufsteigend sortiert -> höchste Version gewinnt
  done
fi
if [ -z "$PHP" ] || [ ! -x "$PHP" ]; then
  echo "X Kein PHP gefunden. PHP_BIN in .env setzen (z. B. /opt/plesk/php/8.3/bin/php)."
  exit 1
fi
echo "==> PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"

# ── Composer auflösen ─────────────────────────────────────────────────────────
COMPOSER="$(env_value COMPOSER_PHAR)"
if [ -z "$COMPOSER" ]; then
  for _c in "$PWD/composer.phar" \
            /usr/local/psa/var/modules/composer/composer.phar \
            /usr/lib/plesk-9.0/composer.phar; do
    if [ -f "$_c" ]; then COMPOSER="$_c"; break; fi
  done
fi
if [ -z "$COMPOSER" ] || [ ! -f "$COMPOSER" ]; then
  echo "X Kein composer.phar gefunden. COMPOSER_PHAR in .env setzen oder Plesk-Composer-Erweiterung installieren."
  exit 1
fi
echo "==> Composer: $COMPOSER"

# HOME ist im Deploy-Kontext oft / oder leer -> Composer-Verzeichnisse im Projekt ablegen.
mkdir -p var/cache
export COMPOSER_HOME="$PWD/var/cache/composer-home"
export COMPOSER_CACHE_DIR="$PWD/var/cache/composer-cache"
export COMPOSER_ALLOW_SUPERUSER=1

if [ ! -f .env ]; then
  echo "X .env fehlt. Einmalig anlegen (DATABASE_URL, APP_SECRET, APP_ENV=prod), siehe .env.dist."
  exit 1
fi

echo "==> Abhängigkeiten installieren"
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "==> Kimai aktualisieren (Migrationen, Cache, Assets)"
"$PHP" bin/console kimai:update -n

echo "==> Passenger/PHP-Neustart nicht nötig; OPcache läuft nach Cache-Neuaufbau neu an"
echo "OK: Deployment abgeschlossen."
