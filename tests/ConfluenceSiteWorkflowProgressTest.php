<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteWorkflow;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ConfluenceSiteWorkflowProgressTest extends TestCase
{
    /**
     * EN: A new preparation/linking phase must not reuse a previous phase's percentage.
     * Completed means the entire workflow; a failure retains the last committed percentage.
     *
     * HR: Nova priprema/povezivanje ne smije koristiti postotak prethodnog koraka.
     * Dovršeno znači cijeli postupak; greška zadržava zadnji potvrđeni postotak.
     */
    public function testOnlyMeasuredStepsExposePercentages(): void
    {
        $reflection = new ReflectionClass(ConfluenceSiteWorkflow::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('progress');
        foreach (['identities', 'calendar_prepare', 'content_prepare', 'links'] as $phase) {
            self::assertNull($method->invoke($service, ['phase' => $phase, 'current' => ['progress' => 100]])['progress']);
        }
        self::assertSame(26, $method->invoke($service, ['phase' => 'calendars', 'current' => ['progress' => 26]])['progress']);
        self::assertSame(43, $method->invoke($service, ['phase' => 'content', 'current' => ['progress' => 43]])['progress']);
        self::assertSame(26, $method->invoke($service, ['phase' => 'failed', 'failed_step' => 'calendars', 'current' => ['progress' => 26]])['progress']);
        self::assertSame(100, $method->invoke($service, ['phase' => 'completed'])['progress']);
        self::assertFalse($method->invoke($service, ['phase' => 'failed'])['completed']);
    }
}
