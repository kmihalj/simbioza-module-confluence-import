<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteGroupPlanner as Planner;
use PHPUnit\Framework\TestCase;

/**
 * EN: Exercises whole-catalog ordinary-group proposals without changing a live directory.
 *
 * HR: Provjerava prijedloge običnih grupa cijelog pregleda bez promjene živog imenika.
 */
final class ConfluenceSiteGroupPlannerTest extends TestCase
{
    /**
     * EN: Name matching is exact after trimming/case folding, not based on a group key or substring.
     *
     * HR: Nazivi se podudaraju točno nakon normalizacije, ne po ključu grupe ni podnizu.
     */
    public function testExactUnambiguousNames(): void
    {
        $targets = [['id' => 12, 'group_name' => ' Staff ', 'group_key' => 'different']];
        $result = Planner::suggest($this->sources(['staff', 'STAFF-extra', 'different']), $targets, true, false);
        self::assertSame('12', $result['g0']['choice']);
        self::assertSame('group_name', $result['g0']['reason']);
        self::assertSame('', $result['g1']['choice']);
        self::assertSame('', $result['g2']['choice']);
    }

    /**
     * EN: Global creation plans every missing ordinary group but does not duplicate an existing name.
     *
     * HR: Skupna izrada planira sve nedostajuće obične grupe, ali ne duplicira postojeći naziv.
     */
    public function testGlobalCreateAndIndependentMapToggle(): void
    {
        $sources = $this->sources(['Staff', 'New']);
        $targets = [['id' => 12, 'group_name' => 'Staff']];
        $result = Planner::suggest($sources, $targets, true, true);
        self::assertSame('12', $result['g0']['choice']);
        self::assertSame('__create__', $result['g1']['choice']);
        self::assertSame('group_auto_create', $result['g1']['reason']);
        self::assertSame('', Planner::suggest($sources, $targets, false, true)['g0']['choice']);
        self::assertSame('', Planner::suggest($sources, [], false, false)['g1']['choice']);
    }

    /**
     * EN: Equal directory labels never merge silently; qualified targets can be reused on reimport.
     *
     * HR: Jednaki nazivi imenika nikada se tiho ne spajaju; razlučeni ciljevi koriste se pri ponovnom uvozu.
     */
    public function testDuplicateDirectoryNamesStaySeparate(): void
    {
        $sources = $this->sources(['Staff', 'Staff']);
        $result = Planner::suggest($sources, [['id' => 9, 'group_name' => 'Staff']], true, true);
        self::assertSame('__create__', $result['g0']['choice']);
        self::assertSame('__create__', $result['g1']['choice']);
        $result = Planner::suggest($sources, [['id' => 12, 'group_name' => 'Staff [0]'], ['id' => 13, 'group_name' => 'Staff [1]']], true, true);
        self::assertSame('12', $result['g0']['choice']);
        self::assertSame('13', $result['g1']['choice']);
    }

    /**
     * EN: Protected, duplicate and invalid names need manual review, never an administrator fallback.
     *
     * HR: Zaštićeni, ponovljeni i nevaljani nazivi traže ručni pregled, nikada zamjenskog administratora.
     */
    public function testConflictsAndProtectedNames(): void
    {
        $result = Planner::suggest(
            $this->sources(['Administrator', 'Staff', '', str_repeat('x', 191)]),
            [['id' => 12, 'group_name' => 'staff'], ['id' => 13, 'group_name' => 'STAFF']],
            true,
            true,
            ['Administrator']
        );
        foreach ($result as $row) {
            self::assertSame('', $row['choice']);
            self::assertSame('conflict', $row['reason']);
        }
    }

    /**
     * EN: A large source catalog is processed with indexed names, not per-group queries.
     *
     * HR: Veliki izvorni pregled obrađuje indeksirane nazive, ne upite po pojedinoj grupi.
     */
    public function testWholeCatalog(): void
    {
        $result = Planner::suggest($this->sources(array_map(static fn(int $i): string => 'Group ' . $i, range(1, 5000))), [], true, true);
        self::assertCount(5000, $result);
        self::assertSame('__create__', $result['g4999']['choice']);
    }

    /**
     * EN: Creates only synthetic source metadata.
     *
     * HR: Izrađuje samo sintetičke izvorne metapodatke.
     *
     * @param list<string> $names
     * @return list<array<string,mixed>>
     */
    private function sources(array $names): array
    {
        $result = [];
        foreach ($names as $i => $name) {
            $result[] = ['source_id' => 'g' . $i, 'name' => $name, 'directory_id' => (string)$i];
        }
        return $result;
    }
}
