<?php

/*
 * Prüft, ob die laufende PHP-Binary alle PHP-Erweiterungen hat, die composer
 * ohne --dev braucht: ext-* aus "require" der composer.json und aus den
 * Produktiv-Paketen der composer.lock. Erweiterungen, die ein Paket per
 * "provide"/"replace" ersetzt (z. B. symfony/polyfill-*), zählen nicht.
 *
 * Aufruf: php scripts/php-check-extensions.php [composer.json] [weitere-ext ...]
 * Ausgabe: Namen der fehlenden Erweiterungen (Leerzeichen-getrennt)
 * Exit-Code: 0 = alles da, 1 = etwas fehlt
 *
 * Bewusst ohne PHP-8-Syntax, damit es auch unter PHP 7.3 läuft.
 */

$file = 'composer.json';
$extra = [];
foreach (array_slice($argv, 1) as $i => $arg) {
    if ($i === 0 && substr($arg, -5) === '.json') {
        $file = $arg;
    } else {
        $extra[] = strtolower($arg);
    }
}

$json = json_decode((string) @file_get_contents($file), true);
$lock = json_decode((string) @file_get_contents(dirname($file) . '/composer.lock'), true);

$required = [];
$provided = [];

$collect = function (array $requires) use (&$required) {
    foreach (array_keys($requires) as $name) {
        if (strncmp($name, 'ext-', 4) === 0) {
            $required[strtolower(substr($name, 4))] = true;
        }
    }
};

if (is_array($json) && isset($json['require']) && is_array($json['require'])) {
    $collect($json['require']);
}
if (is_array($lock) && isset($lock['packages']) && is_array($lock['packages'])) {
    foreach ($lock['packages'] as $package) {
        if (isset($package['require']) && is_array($package['require'])) {
            $collect($package['require']);
        }
        foreach (['provide', 'replace'] as $key) {
            if (isset($package[$key]) && is_array($package[$key])) {
                foreach (array_keys($package[$key]) as $name) {
                    if (strncmp($name, 'ext-', 4) === 0) {
                        $provided[strtolower(substr($name, 4))] = true;
                    }
                }
            }
        }
    }
}
foreach ($extra as $name) {
    $required[$name] = true;
}

$missing = [];
foreach (array_keys($required) as $name) {
    if (isset($provided[$name]) && !in_array($name, $extra, true)) {
        continue;
    }
    // Composer-Namen: Leerzeichen -> Bindestrich (z. B. "zend-opcache")
    if (!extension_loaded($name) && !extension_loaded(str_replace('-', ' ', $name))) {
        $missing[] = $name;
    }
}

sort($missing);
echo implode(' ', $missing), "\n";
exit($missing === [] ? 0 : 1);
