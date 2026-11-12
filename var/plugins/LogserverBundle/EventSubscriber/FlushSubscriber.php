<?php

declare(strict_types=1);

namespace KimaiPlugin\LogserverBundle\EventSubscriber;

use KimaiPlugin\LogserverBundle\Client\LogSinkInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sendet die gesammelten Einträge nach der Antwort (kernel.terminate) bzw. nach einem Konsolenbefehl.
 */
final class FlushSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LogSinkInterface $log)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['flush', -1000],
            ConsoleEvents::TERMINATE => ['flush', -1000],
        ];
    }

    public function flush(): void
    {
        $this->log->flush();
    }
}
