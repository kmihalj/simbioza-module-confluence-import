<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportService;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ConfluenceImportConfig::class)]
#[CoversClass(ConfluenceImportService::class)]
final class ConfluenceImportMemoryTest extends TestCase
{
    /** HR: Veliki uvoz dobiva vlastiti limit, a test vraća prethodno PHP stanje. EN: A large import gets its own limit, and the test restores PHP state. */
    public function testImportRaisesOnlyItsCurrentPhpRequestLimit(): void
    {
        $previous = ini_get('memory_limit');
        self::assertIsString($previous);
        $config = new Config(new Helper(), [], dirname(__DIR__));
        $importConfig = new ConfluenceImportConfig($config, dirname(__DIR__));
        self::assertSame(512, $importConfig->importMemoryLimitMb());

        $reflection = new ReflectionClass(ConfluenceImportService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('config')->setValue($service, $importConfig);
        try {
            self::assertNotFalse(ini_set('memory_limit', '128M'));
            $reflection->getMethod('ensureImportMemory')->invoke($service);
            self::assertSame('512M', ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', $previous);
        }
    }
}
