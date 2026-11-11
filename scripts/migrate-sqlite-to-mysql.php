<?php

/*
 * Kopiert die Daten einer Kimai-SQLite-Datenbank (kimai2_*-Tabellen) in eine
 * MySQL/MariaDB-Datenbank, deren Schema Kimai bereits angelegt hat
 * (bin/console kimai:install -n, gleiche Kimai-Version wie die Daten!).
 *
 * Aufruf (Plesk: Geplante Aufgabe -> "PHP-Skript ausführen"):
 *   Skript:     scripts/migrate-sqlite-to-mysql.php
 *   Argumente:  var/data/kimai.sqlite [--env=.env] [--truncate] [--dry-run]
 *
 *   --env=PFAD   .env mit der MySQL-DATABASE_URL (Standard: .env im Projektstamm)
 *   --truncate   Zieltabellen vorher leeren (sonst: REPLACE INTO, vorhandene IDs werden überschrieben)
 *   --dry-run    nur zählen, nichts schreiben
 *
 * Ziel-Zugangsdaten kommen aus DATABASE_URL der .env; das Skript parst sie
 * selbst und gibt bei einer unbrauchbaren URL eine konkrete Meldung aus.
 * Die SQLite-Datei wird nur lesend geöffnet.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const SKIP_TABLES = ['doctrine_migration_versions', 'sqlite_sequence'];

function starts_with(string $haystack, string $needle): bool
{
    return strncmp($haystack, $needle, strlen($needle)) === 0;
}

function fail(string $message)
{
    fwrite(STDERR, "FEHLER: $message\n");
    exit(1);
}

function ident(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        fail("Unzulässiger Bezeichner: $name");
    }

    return '`' . $name . '`';
}

// ── Argumente ───────────────────────────────────────────────────────────────
$sqlitePath = null;
$envPath = dirname(__DIR__) . '/.env';
$truncate = false;
$dryRun = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--truncate') {
        $truncate = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (starts_with($arg, '--env=')) {
        $envPath = substr($arg, 6);
    } elseif (starts_with($arg, '--')) {
        fail("Unbekannte Option: $arg");
    } else {
        $sqlitePath = $arg;
    }
}

if ($sqlitePath === null) {
    fail('SQLite-Datei fehlt. Beispiel: scripts/migrate-sqlite-to-mysql.php var/data/kimai.sqlite');
}
if (!starts_with($sqlitePath, '/')) {
    $sqlitePath = dirname(__DIR__) . '/' . $sqlitePath;
}
if (!is_file($sqlitePath) || !is_readable($sqlitePath)) {
    fail("SQLite-Datei nicht lesbar: $sqlitePath");
}
foreach (['pdo_sqlite', 'pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        fail("PHP-Erweiterung $ext fehlt (Plesk -> PHP-Einstellungen).");
    }
}

// ── Ziel-Zugangsdaten aus .env ──────────────────────────────────────────────
if (!is_file($envPath) || !is_readable($envPath)) {
    fail("Env-Datei nicht lesbar: $envPath");
}
$url = null;
foreach (file($envPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $line = trim($line);
    if (starts_with($line, 'DATABASE_URL=')) {
        $url = trim(substr($line, strlen('DATABASE_URL=')), " \t\"'");
    }
}
if ($url === null || $url === '') {
    fail("DATABASE_URL nicht in $envPath gefunden.");
}
$parts = parse_url($url);
if (substr_count($url, '@') > 1 || substr_count(explode('?', $url, 2)[0], '#') > 0) {
    fail('DATABASE_URL enthält unkodierte Sonderzeichen (@ oder #) im Passwort/Benutzer. URL-kodieren: @ -> %40, : -> %3A, / -> %2F, # -> %23, % -> %25.');
}
if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
    fail('DATABASE_URL ist nicht parsbar. Sonderzeichen im Passwort/Benutzer URL-kodieren (@ -> %40, : -> %3A, / -> %2F, # -> %23, % -> %25).');
}
if (!in_array($parts['scheme'], ['mysql', 'mariadb'], true)) {
    fail("DATABASE_URL zeigt nicht auf MySQL/MariaDB (Schema: {$parts['scheme']}://).");
}
parse_str($parts['query'] ?? '', $query);
$dbName = rawurldecode(ltrim($parts['path'], '/'));
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $parts['host'],
    $parts['port'] ?? 3306,
    $dbName,
    preg_match('/^[a-z0-9]+$/i', (string) ($query['charset'] ?? '')) ? $query['charset'] : 'utf8mb4'
);

try {
    $src = new PDO('sqlite:' . $sqlitePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $src->exec('PRAGMA query_only = ON');
    $dst = new PDO($dsn, rawurldecode($parts['user'] ?? ''), rawurldecode($parts['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fail('Verbindung fehlgeschlagen: ' . $e->getMessage());
}

echo "Quelle: $sqlitePath\n";
echo "Ziel:   {$parts['host']}/$dbName" . ($dryRun ? '  [DRY-RUN]' : '') . ($truncate ? '  [TRUNCATE]' : '') . "\n\n";

// ── Tabellen ermitteln ──────────────────────────────────────────────────────
$srcTables = $src->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);
$dstTables = $dst->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

$copy = [];
foreach ($srcTables as $table) {
    if (in_array($table, SKIP_TABLES, true)) {
        continue;
    }
    if (!in_array($table, $dstTables, true)) {
        echo "WARNUNG: Tabelle $table fehlt im Ziel – übersprungen (Kimai-Version/kimai:install prüfen).\n";
        continue;
    }
    $copy[] = $table;
}
if ($copy === []) {
    fail('Keine gemeinsamen Tabellen gefunden. Wurde im Ziel zuerst "kimai:install -n" ausgeführt?');
}

// ── Kopieren ────────────────────────────────────────────────────────────────
$errors = 0;
$report = [];

if (!$dryRun) {
    $dst->exec('SET FOREIGN_KEY_CHECKS = 0');
}

foreach ($copy as $table) {
    $srcCols = array_column($src->query('PRAGMA table_info(' . ident($table) . ')')->fetchAll(), 'name');
    $dstCols = array_column($dst->query('SHOW COLUMNS FROM ' . ident($table))->fetchAll(), 'Field');
    $cols = array_values(array_intersect($srcCols, $dstCols));

    $onlySrc = array_diff($srcCols, $dstCols);
    if ($onlySrc !== []) {
        echo "WARNUNG: $table – Spalten nur in der Quelle (werden nicht übernommen): " . implode(', ', $onlySrc) . "\n";
    }
    if ($cols === []) {
        echo "WARNUNG: $table – keine gemeinsamen Spalten, übersprungen.\n";
        continue;
    }

    $total = (int) $src->query('SELECT COUNT(*) FROM ' . ident($table))->fetchColumn();
    $written = 0;

    if (!$dryRun) {
        try {
            $dst->beginTransaction();
            if ($truncate) {
                $dst->exec('DELETE FROM ' . ident($table));
            }
            $colList = implode(', ', array_map('ident', $cols));
            $stmt = $dst->prepare(sprintf(
                'REPLACE INTO %s (%s) VALUES (%s)',
                ident($table),
                $colList,
                implode(', ', array_fill(0, count($cols), '?'))
            ));
            $rows = $src->query('SELECT ' . $colList . ' FROM ' . ident($table));
            foreach ($rows as $row) {
                $stmt->execute(array_values($row));
                $written++;
            }
            $dst->commit();
        } catch (PDOException $e) {
            if ($dst->inTransaction()) {
                $dst->rollBack();
            }
            $errors++;
            echo "FEHLER in $table: " . $e->getMessage() . "\n";
            $written = 0;
        }
    }

    $inDst = (int) $dst->query('SELECT COUNT(*) FROM ' . ident($table))->fetchColumn();
    $report[] = [$table, $total, $dryRun ? '-' : $written, $inDst];
}

if (!$dryRun) {
    $dst->exec('SET FOREIGN_KEY_CHECKS = 1');
}

// ── Bericht ─────────────────────────────────────────────────────────────────
printf("%-40s %8s %8s %8s\n", 'Tabelle', 'Quelle', 'Kopiert', 'Ziel');
foreach ($report as [$table, $total, $written, $inDst]) {
    $flag = (!$dryRun && $total !== $inDst) ? '  <-- Abweichung' : '';
    printf("%-40s %8d %8s %8d%s\n", $table, $total, (string) $written, $inDst, $flag);
}

echo $errors === 0 ? "\nOK: Migration abgeschlossen.\n" : "\nFERTIG MIT $errors FEHLER(N) – siehe oben.\n";
exit($errors === 0 ? 0 : 1);
