<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluencePersonalAppendPlan;
use PHPUnit\Framework\TestCase;

/**
 * EN: Add-only plans preserve identity, disabled content and occupied native slugs.
 *
 * HR: Planovi dodavanja čuvaju identitet, isključeni sadržaj i zauzete nativne slugove.
 */
final class ConfluencePersonalAppendPlanTest extends TestCase
{
    /**
     * EN: A source update is not permission to replace an already imported page.
     *
     * HR: Izmjena izvora nije dopuštenje za zamjenu već uvezene stranice.
     */
    public function testKeepsExistingSourceAndReservesNativeSlugs(): void
    {
        $node = ['id' => 10, 'document_key' => 'old-document', 'slug' => 'occupied', 'is_enabled' => true];
        $pages = ['100' => [['title' => 'Changed remotely']], '101' => [['title' => 'New child']]];
        $plan = ConfluencePersonalAppendPlan::build(
            $pages,
            [$node,
            ['id' => 11, 'document_key' => 'native-document', 'slug' => 'native', 'is_enabled' => false]],
            [['logical_source_id' => '100', 'target_node_id' => 10, 'target_document_key' => 'old-document']]
        );
        self::assertSame(['101'], array_map(strval(...), array_keys($plan['pages'])));
        self::assertSame($node, $plan['retained']['100']);
        self::assertSame(10, $plan['parents']['100']);
        self::assertSame(['occupied' => true, 'native' => true], $plan['used']);
        self::assertCount(2, $pages);
    }

    /**
     * EN: Disabled pages are preserved but cannot become active parents of new pages.
     *
     * HR: Isključene stranice ostaju sačuvane, ali nisu aktivni roditelji novih stranica.
     */
    public function testDoesNotRecreateOrEnableDisabledMappedPage(): void
    {
        $plan = ConfluencePersonalAppendPlan::build(
            ['100' => []],
            [['id' => 10, 'document_key' => 'old', 'slug' => 'old', 'is_enabled' => false]],
            [['logical_source_id' => '100', 'target_node_id' => 10, 'target_document_key' => 'old']]
        );
        self::assertSame([], $plan['pages']);
        self::assertSame([], $plan['parents']);
        self::assertCount(1, $plan['retained']);
    }

    /**
     * EN: A stale target fails closed instead of recreating or overwriting an unrelated document.
     *
     * HR: Zastarjeli cilj prekida uvoz umjesto ponovne izrade ili prepisivanja nepovezanog dokumenta.
     */
    public function testRejectsChangedDocumentIdentity(): void
    {
        $this->expectException(ConfluenceImportException::class);
        ConfluencePersonalAppendPlan::build(
            ['100' => []],
            [['id' => 10, 'document_key' => 'native', 'slug' => 'same', 'is_enabled' => true]],
            [['logical_source_id' => '100', 'target_node_id' => 10, 'target_document_key' => 'source']]
        );
    }

    /**
     * EN: A matching title is never treated as source identity.
     *
     * HR: Isti naslov nikada se ne smatra izvornim identitetom.
     */
    public function testNativePageWithSameTitleIsNotReused(): void
    {
        $plan = ConfluencePersonalAppendPlan::build(
            ['100' => [['title' => 'Same title']]],
            [['id' => 10, 'title' => 'Same title', 'document_key' => 'native', 'slug' => 'same-title', 'is_enabled' => true]],
            []
        );
        self::assertCount(1, $plan['pages']);
        self::assertSame([], $plan['retained']);
        self::assertSame(['same-title' => true], $plan['used']);
    }
}
