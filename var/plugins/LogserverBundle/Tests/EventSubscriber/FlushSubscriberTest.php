<?php

declare(strict_types=1);

namespace KimaiPlugin\LogserverBundle\Tests\EventSubscriber;

use KimaiPlugin\LogserverBundle\EventSubscriber\FlushSubscriber;
use KimaiPlugin\LogserverBundle\Tests\RecordingSink;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\KernelEvents;

final class FlushSubscriberTest extends TestCase
{
    public function testFlushesOnTerminate(): void
    {
        $sink = new RecordingSink();
        $subscriber = new FlushSubscriber($sink);
        $subscriber->flush();

        self::assertSame(1, $sink->flushes);
    }

    public function testRunsLateOnKernelAndConsoleTerminate(): void
    {
        $events = FlushSubscriber::getSubscribedEvents();

        self::assertSame(['flush', -1000], $events[KernelEvents::TERMINATE]);
        self::assertSame(['flush', -1000], $events[ConsoleEvents::TERMINATE]);
    }
}
