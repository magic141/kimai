<?php

declare(strict_types=1);

namespace KimaiPlugin\LogserverBundle\Client;

interface LogSinkInterface
{
    /**
     * Merkt einen Eintrag zum Senden vor. Wirft nie.
     *
     * @param array<string, mixed> $meta flache Schlüssel/Wert-Paare
     */
    public function log(string $level, string $message, array $meta = [], string $type = 'log'): void;

    /**
     * Sendet alle vorgemerkten Einträge. Wirft nie.
     */
    public function flush(): void;
}
