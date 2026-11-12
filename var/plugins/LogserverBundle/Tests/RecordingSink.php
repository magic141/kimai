<?php

declare(strict_types=1);

namespace KimaiPlugin\LogserverBundle\Tests;

use KimaiPlugin\LogserverBundle\Client\LogSinkInterface;

final class RecordingSink implements LogSinkInterface
{
    /** @var list<array{level: string, message: string, meta: array<string, mixed>, type: string}> */
    public array $entries = [];
    public int $flushes = 0;

    public function log(string $level, string $message, array $meta = [], string $type = 'log'): void
    {
        $this->entries[] = ['level' => $level, 'message' => $message, 'meta' => $meta, 'type' => $type];
    }

    public function flush(): void
    {
        ++$this->flushes;
    }
}
