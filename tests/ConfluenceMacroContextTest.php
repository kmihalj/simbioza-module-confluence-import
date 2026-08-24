<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Value\ConfluenceMacroContext;
use PHPUnit\Framework\TestCase;

final class ConfluenceMacroContextTest extends TestCase
{
    /**
     * HR: Veliki Confluence izvozi često daju brojčane PHP ključeve i oni se moraju normalizirati prije pretvorbe makroa.
     * EN: Large Confluence exports often yield numeric PHP keys and they must be normalized before macro conversion.
     */
    public function testNumericConfluencePageIdentifierIsNormalizedToString(): void
    {
        $context = new ConfluenceMacroContext(143180505, [], []);

        self::assertSame('143180505', $context->currentPageId);
    }
}
