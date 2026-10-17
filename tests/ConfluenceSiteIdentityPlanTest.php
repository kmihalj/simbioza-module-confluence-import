<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteIdentityPlan;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository;
use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * EN: Reviews future accounts and execution guards without business writes or fake numeric IDs.
 *
 * HR: Provjerava buduće račune i zaštite izvršavanja bez poslovnih upisa ili lažnih brojčanih ID-jeva.
 */
final class ConfluenceSiteIdentityPlanTest extends TestCase
{
    /**
     * EN: Explicit aliases share one future user even when their primary occurs later in the catalog.
     *
     * HR: Izričite veze dijele jednog budućeg korisnika i kada je glavni račun kasnije u pregledu.
     */
    public function testAliasesResolveOneDeferredOwnerWithoutInventingIds(): void
    {
        $catalog = ['users' => [
            ['source_id' => 'old', 'display_name' => 'Old Name', 'username' => 'old', 'aliases' => ['confluence-user:old-key']],
            ['source_id' => 'middle', 'display_name' => 'Middle', 'username' => 'middle'],
            ['source_id' => 'primary', 'display_name' => 'Current Name', 'username' => 'primary'],
        ]];
        $plan = ConfluenceSiteIdentityPlan::project($catalog, [
            'old' => '__source__:middle', 'middle' => '__source__:primary', 'primary' => '__create__',
        ], []);
        self::assertSame('new:primary', $plan['users']['old-key']);
        self::assertSame($plan['owners']['old'], $plan['owners']['primary']);
        self::assertSame('Current Name', $plan['owners']['old']['label']);
    }

    /**
     * EN: Cycles, missing roots and skipped roots cannot become executable identities.
     *
     * HR: Ciklusi, nepostojeći i preskočeni korijeni ne smiju postati izvršivi identiteti.
     */
    public function testInvalidSourceRelationsAreRejected(): void
    {
        foreach (
            [['a' => '__source__:a'], ['a' => '__source__:b', 'b' => '__source__:a'],
            ['a' => '__source__:missing'], ['a' => '__source__:b', 'b' => ''],
            ['b' => '', 'a' => '__source__:b']] as $choices
        ) {
            try {
                ConfluenceSiteIdentityPlan::resolve($choices);
                self::fail('Invalid source relation accepted.');
            } catch (ConfluenceImportException) {
                self::assertTrue(true);
            }
        }
    }

    /**
     * EN: Long source chains are bounded by catalog size, not recursive call depth.
     *
     * HR: Duge veze izvora ograničene su veličinom pregleda, ne dubinom rekurzivnog poziva.
     */
    public function testTenThousandAliasesResolveIteratively(): void
    {
        $choices = [];
        for ($i = 0; $i < 10000; ++$i) {
            $choices['source-' . $i] = '__source__:source-' . ($i + 1);
        }
        $choices['source-10000'] = '42';
        $resolved = ConfluenceSiteIdentityPlan::resolve($choices);
        self::assertCount(10001, $resolved);
        self::assertSame(['source_id' => 'source-10000', 'choice' => '42'], $resolved['source-0']);
    }

    /**
     * EN: Different personal source owners share one preview only after their explicit identity relation.
     *
     * HR: Različiti vlasnici osobnih izvora dijele pregled tek nakon izričitog povezivanja identiteta.
     */
    public function testPersonalGroupsRespectExplicitSourceRelations(): void
    {
        $catalog = ['users' => [
            ['source_id' => 'old', 'display_name' => 'Old Name', 'username' => 'old', 'aliases' => ['confluence-user:old-key']],
            ['source_id' => 'primary', 'display_name' => 'Current Name', 'username' => 'primary', 'aliases' => ['confluence-user:new-key']],
        ]];
        $spaces = [['source_id' => '1', 'source_key' => '~old', 'type' => 'personal', 'owner_source_key' => 'old-key'],
            ['source_id' => '2', 'source_key' => '~new', 'type' => 'personal', 'owner_source_key' => 'new-key']];
        self::assertSame([], ConfluenceSiteIdentityPlan::personalGroups($spaces, $catalog, ['old' => '__create__', 'primary' => '__create__']));
        $groups = ConfluenceSiteIdentityPlan::personalGroups($spaces, $catalog, ['old' => '__source__:primary', 'primary' => '__create__']);
        self::assertSame([['owner_label' => 'Current Name', 'sources' => [['id' => '1', 'key' => '~old'], ['id' => '2', 'key' => '~new']]]], $groups);
    }

    public function testOnlySelectedValidTargetsAndDeferredAccountsAreProjected(): void
    {
        $catalog = ['users' => [
            ['source_id' => 'one', 'display_name' => 'New One', 'username' => 'one', 'aliases' => ['confluence-user:key-one']],
            ['source_id' => 'two', 'display_name' => 'Two', 'username' => 'two', 'aliases' => ['confluence-user:key-two']],
            ['source_id' => 'three', 'display_name' => 'Three', 'username' => 'three'],
        ]];
        $plan = ConfluenceSiteIdentityPlan::project(
            $catalog,
            ['one' => '__create__', 'two' => '42', 'three' => '999'],
            [['id' => 42, 'display_name' => 'Local Two']]
        );
        self::assertSame('new:one', $plan['users']['key-one']);
        self::assertSame(42, $plan['users']['key-two']);
        self::assertSame('one', $plan['aliases']['confluence-user:key-one']);
        self::assertSame('Local Two', $plan['owners']['two']['label']);
        self::assertArrayNotHasKey('three', $plan['users']);
    }

    public function testExecutionGuardRestoresAfterCoordinatorFailure(): void
    {
        $repo = (new ReflectionClass(ConfluenceImportRepository::class))->newInstanceWithoutConstructor();
        $job = ['options' => ['site_workflow' => ['phase' => 'identities']]];
        try {
            $repo->withWorkflowContext(function () use ($repo, $job): never {
                $repo->assertSiteSetupMutable($job);
                throw new \RuntimeException('Synthetic interruption');
            });
        } catch (\RuntimeException) {
            $this->expectException(ConfluenceImportException::class);
            $repo->assertSiteSetupMutable($job);
        }
    }
}
