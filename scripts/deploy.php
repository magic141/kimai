<?php

/*
 * Deploy-Schritte für Plesk ohne Shell: composer install, assets:install,
 * kimai:update. Läuft mit der PHP-Binary, die dieses Skript startet, also mit
 * der PHP-Version der Domain.
 *
 * Plesk: Geplante Aufgabe -> "PHP-Skript ausführen"
 *   Skript:     scripts/deploy.php   (Pfad relativ zum Anwendungsstamm)
 *   Argumente:  keine
 * Jeder Schritt wird in var/log/post-deploy.log protokolliert.
 *
 * Automatik nach einem Git-Pull (die Git-Zusatzaktion hat in Plesk kein PHP):
 *   scripts/post-deploy.sh legt var/deploy.requested an. Eine geplante Aufgabe
 *   "PHP-Skript ausführen" mit Argument --if-requested (z. B. alle 5 Minuten)
 *   führt den Deploy aus, sobald der Marker da ist, und bleibt sonst stumm.
 *
 * Optional in der .env (nur auf dem Server):
 *   COMPOSER_PHAR=/pfad/zu/composer.phar   # sonst ./composer.phar bzw. Plesk-Composer
 *
 * Ohne Composer auf dem Server wird ein hochgeladenes vendor/ verwendet.
 * Bewusst ohne PHP-8-Syntax (läuft ab PHP 7.3).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
chdir($root);

$ifRequested = in_array('--if-requested', array_slice($argv, 1), true);
$requestFile = $root . '/var/deploy.requested';
if ($ifRequested && !is_file($requestFile)) {
    exit(0); // nichts angefordert: still beenden
}

$logFile = $root . '/var/log/post-deploy.log';
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0775, true);
}

function logmsg($message)
{
    global $logFile;
    echo $message, "\n";
    @file_put_contents($logFile, date('Y-m-d H:i:s') . ' ' . $message . "\n", FILE_APPEND);
}

function failDeploy($message)
{
    logmsg('X ' . $message);
    exit(1);
}

/** Führt einen Befehl aus, schreibt dessen Ausgabe zeilenweise in Konsole und Log. */
function runStep(array $args, array $extraEnv = [])
{
    global $root;
    $parts = [];
    foreach ($args as $arg) {
        $parts[] = escapeshellarg($arg);
    }
    $env = array_merge(getenv(), $extraEnv);
    $process = @proc_open(implode(' ', $parts) . ' 2>&1', [1 => ['pipe', 'w']], $pipes, $root, $env);
    if (!is_resource($process)) {
        failDeploy('proc_open ist nicht verfügbar (disable_functions?). Die PHP-Funktion muss für die Domain erlaubt sein.');
    }
    while (($line = fgets($pipes[1])) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line !== '') {
            logmsg($line);
        }
    }
    fclose($pipes[1]);

    return proc_close($process);
}

/** Wert aus der .env lesen (ohne Anführungszeichen). */
function envValue($key)
{
    global $root;
    $file = $root . '/.env';
    if (!is_file($file)) {
        return '';
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if (strncmp($line, $key . '=', strlen($key) + 1) === 0) {
            return trim(substr($line, strlen($key) + 1), " \t\"'");
        }
    }

    return '';
}

// ── Plausibilität ───────────────────────────────────────────────────────────
if (basename($root) === 'public') {
    failDeploy('Das Skript liegt in public/. Der Bereitstellungspfad in Plesk muss der Anwendungsstamm sein.');
}
if (!is_file($root . '/bin/console')) {
    failDeploy('bin/console fehlt in ' . $root . ' – falscher Ordner oder unvollständige Bereitstellung.');
}
if (!is_file($root . '/.env')) {
    failDeploy('.env fehlt im Anwendungsstamm (DATABASE_URL, APP_SECRET, APP_ENV=prod, siehe .env.dist).');
}

// ── Sperre (wird beim Prozessende automatisch freigegeben) ──────────────────
$lock = fopen($root . '/var/deploy.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if ($ifRequested) {
        exit(0); // läuft schon; der Marker bleibt und wird beim nächsten Takt geprüft
    }
    failDeploy('Ein anderer Deploy läuft bereits – Abbruch. Erst dessen Ende abwarten.');
}
if ($ifRequested) {
    @unlink($requestFile); // Marker verbrauchen; schlägt der Lauf fehl, ist ein manueller Start nötig
    logmsg('==> Angeforderter Deploy (Marker var/deploy.requested)');
}

logmsg('==> Deploy gestartet in ' . $root);
logmsg('==> PHP: ' . PHP_BINARY . ' (' . PHP_VERSION . ')');

// ── PHP-Version und Erweiterungen laut composer.json ────────────────────────
$extraExt = [];
if (preg_match('/^(mysql|mariadb):/', envValue('DATABASE_URL'))) {
    $extraExt[] = 'pdo_mysql';
}
if (runStep([PHP_BINARY, $root . '/scripts/php-matches-composer.php', $root . '/composer.json']) !== 0) {
    runStep([PHP_BINARY, $root . '/scripts/php-matches-composer.php', $root . '/composer.json', '--print']);
    failDeploy('Diese PHP-Version passt nicht zur composer.json (Constraint siehe oben). PHP-Version der Domain in Plesk ändern.');
}
$extCmd = array_merge([PHP_BINARY, $root . '/scripts/php-check-extensions.php', $root . '/composer.json'], $extraExt);
if (runStep($extCmd) !== 0) {
    failDeploy('Es fehlen PHP-Erweiterungen (Liste oben). In Plesk für die PHP-Version der Domain aktivieren.');
}

// ── Composer ────────────────────────────────────────────────────────────────
$composer = envValue('COMPOSER_PHAR');
if ($composer === '') {
    foreach ([$root . '/composer.phar', '/usr/local/psa/var/modules/composer/composer.phar', '/usr/lib/plesk-9.0/composer.phar'] as $candidate) {
        if (is_file($candidate)) {
            $composer = $candidate;
            break;
        }
    }
}

$memory = ['-d', 'memory_limit=1G'];
if ($composer !== '' && is_file($composer)) {
    logmsg('==> Composer: ' . $composer);
    @mkdir($root . '/var/cache', 0775, true);
    $env = [
        'COMPOSER_HOME' => $root . '/var/cache/composer-home',
        'COMPOSER_CACHE_DIR' => $root . '/var/cache/composer-cache',
        'COMPOSER_ALLOW_SUPERUSER' => '1',
    ];
    // Ohne Composer-Skripte: cache:clear und assets:install laufen unten mit dem erhöhten Speicherlimit
    // (Kindprozesse von Composer erben das -d memory_limit nicht und scheitern mit 128 MB).
    logmsg('==> Abhängigkeiten installieren (composer install)');
    $cmd = array_merge([PHP_BINARY], $memory, [$composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress', '--no-scripts']);
    if (runStep($cmd, $env) !== 0) {
        failDeploy('composer install ist fehlgeschlagen (Ausgabe oben).');
    }
} elseif (is_file($root . '/vendor/autoload.php')) {
    logmsg('! Kein Composer gefunden – das vorhandene vendor/ wird verwendet.');
} else {
    failDeploy('Weder composer.phar noch vendor/ vorhanden. vendor/ hochladen oder COMPOSER_PHAR in der .env setzen.');
}

// ── Kimai ───────────────────────────────────────────────────────────────────
logmsg('==> Assets installieren');
if (runStep(array_merge([PHP_BINARY], $memory, [$root . '/bin/console', 'assets:install', 'public', '--env=prod'])) !== 0) {
    failDeploy('assets:install ist fehlgeschlagen (Ausgabe oben).');
}

logmsg('==> Kimai aktualisieren (Migrationen, Cache)');
if (runStep(array_merge([PHP_BINARY], $memory, [$root . '/bin/console', 'kimai:update', '-n'])) !== 0) {
    failDeploy('kimai:update ist fehlgeschlagen (Ausgabe oben). Datenbank-Backup einspielen, falls Migrationen teilweise liefen.');
}

logmsg('OK: Deployment abgeschlossen.');
