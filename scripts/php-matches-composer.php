<?php

/*
 * Exit-Code 0, wenn die laufende PHP-Version zum "php"-Constraint in der
 * composer.json passt, sonst 1. Wird von scripts/deploy.php benutzt, um die
 * passende Plesk-PHP-Version zu wählen.
 *
 * Aufruf: php scripts/php-matches-composer.php [composer.json] [--print]
 *   --print  gibt den Constraint aus (für Fehlermeldungen), Exit-Code 0
 *
 * Bewusst ohne PHP-8-Syntax, damit es auch unter PHP 7.3 läuft.
 */

$file = 'composer.json';
$print = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--print') {
        $print = true;
    } else {
        $file = $arg;
    }
}

$json = json_decode((string) @file_get_contents($file), true);
if (!is_array($json) || !isset($json['require']['php'])) {
    exit(0); // kein Constraint -> alles akzeptieren
}
$constraint = (string) $json['require']['php'];

if ($print) {
    echo $constraint, "\n";
    exit(0);
}

function termMatches($term, $version)
{
    if ($term === '*') {
        return true;
    }
    if (!preg_match('/^(>=|<=|>|<|\^|~|=)?v?(\d+(?:\.\d+){0,2})(\.\*)?$/', $term, $m)) {
        return false; // unbekanntes Format -> nicht passend
    }
    $op = $m[1];
    $num = $m[2];
    $wildcard = isset($m[3]) && $m[3] !== '';
    $segments = explode('.', $num);

    if ($wildcard || $op === '' || $op === '=') {
        // Präfix-Vergleich: 8.1.* bzw. 8.1 trifft 8.1.x
        $have = array_slice(explode('.', $version), 0, count($segments));

        return $have === $segments;
    }
    switch ($op) {
        case '>=':
            return version_compare($version, $num, '>=');
        case '>':
            return version_compare($version, $num, '>');
        case '<=':
            return version_compare($version, $num, '<=');
        case '<':
            return version_compare($version, $num, '<');
        case '^':
            return version_compare($version, $num, '>=')
                && version_compare($version, ((int) $segments[0] + 1) . '.0.0', '<');
        case '~':
            $upper = count($segments) >= 3
                ? $segments[0] . '.' . ((int) $segments[1] + 1) . '.0'
                : ((int) $segments[0] + 1) . '.0.0';

            return version_compare($version, $num, '>=') && version_compare($version, $upper, '<');
    }

    return false;
}

foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) as $alternative) {
    $ok = true;
    foreach (preg_split('/[\s,]+/', trim($alternative)) as $term) {
        if ($term !== '' && !termMatches($term, PHP_VERSION)) {
            $ok = false;
            break;
        }
    }
    if ($ok) {
        exit(0);
    }
}
exit(1);
