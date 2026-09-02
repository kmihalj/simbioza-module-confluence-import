<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluencePageHierarchy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfluencePageHierarchy::class)]
final class ConfluencePageHierarchyTest extends TestCase
{
    /** HR: Dijete izostavljenog nacrta ostaje pod najbližim uvezenim pretkom. EN: A child of an excluded draft remains below its nearest imported ancestor. */
    public function testSkipsExcludedDraftParentWithoutPromotingChildToRoot(): void
    {
        $ancestor = ['logical_source_id' => '10', 'parent_id' => '', 'status' => 'current'];
        $draft = ['logical_source_id' => '11', 'parent_id' => '10', 'status' => 'draft'];
        $child = ['logical_source_id' => '12', 'parent_id' => '11', 'status' => 'current'];

        $parents = (new ConfluencePageHierarchy())->normalizedParents(
            ['10' => [$ancestor], '12' => [$child]],
            [$ancestor, $draft, $child],
        );

        self::assertSame('', $parents['10']);
        self::assertSame('10', $parents['12']);
    }

    /** HR: Nepostojeći i ciklički roditelji sigurno završavaju u korijenu. EN: Missing and cyclic parents safely fall back to the root. */
    public function testFallsBackToRootForMissingOrCyclicParents(): void
    {
        $missing = ['logical_source_id' => '20', 'parent_id' => '404', 'status' => 'current'];
        $cycleA = ['logical_source_id' => '30', 'parent_id' => '31', 'status' => 'draft'];
        $cycleB = ['logical_source_id' => '31', 'parent_id' => '30', 'status' => 'draft'];
        $cycleChild = ['logical_source_id' => '32', 'parent_id' => '30', 'status' => 'current'];

        $parents = (new ConfluencePageHierarchy())->normalizedParents(
            ['20' => [$missing], '32' => [$cycleChild]],
            [$missing, $cycleA, $cycleB, $cycleChild],
        );

        self::assertSame('', $parents['20']);
        self::assertSame('', $parents['32']);
    }
}
