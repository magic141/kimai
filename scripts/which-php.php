<?php

/*
 * Gibt aus, welche PHP-Binary dieses Skript ausführt (Pfad, Version, php.ini)
 * und ob die für Kimai nötigen Erweiterungen geladen sind.
 *
 * Plesk: Geplante Aufgabe -> "PHP-Skript ausführen" -> scripts/which-php.php.
 * Die Zeile "PHP_BIN=" kann direkt in die .env übernommen werden.
 *
 * Bewusst ohne PHP-8-Syntax (läuft ab PHP 7.3).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

echo 'PHP_BIN=', PHP_BINARY, "\n";
echo 'Version:   ', PHP_VERSION, "\n";
echo 'php.ini:   ', (php_ini_loaded_file() ?: '(keine)'), "\n";
echo 'Weitere:   ', (php_ini_scanned_files() ? str_replace("\n", ' ', trim((string) php_ini_scanned_files())) : '(keine)'), "\n\n";

foreach (['intl', 'xsl', 'gd', 'zip', 'mbstring', 'pdo_mysql', 'xml', 'dom', 'simplexml', 'json', 'tokenizer', 'iconv', 'ctype'] as $ext) {
    printf("%-10s %s\n", $ext, extension_loaded($ext) ? 'ok' : 'FEHLT');
}
