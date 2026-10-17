<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteUserPlanner as Planner;
use PHPUnit\Framework\TestCase;

/**
 * EN: Verifies strict bulk matching without a database, credentials or Auth changes.
 *
 * HR: Provjerava strogo skupno povezivanje bez baze, vjerodajnica i promjena Autha.
 */
final class ConfluenceSiteUserPlannerTest extends TestCase
{
    public function testInactiveSourcesAreNotAutomaticallyCreatedButCanBeArchivedOrMatchedExplicitly(): void
    {
        $sources = [[...$this->source('disabled', 'old-login', ''), 'account_active' => false, 'directory_active' => true],
            [...$this->source('directory', 'outside', ''), 'account_active' => true, 'directory_active' => false],
            [...$this->source('reference', 'historical', ''), 'reference_only' => true],
            [...$this->source('unknown', 'unknown', ''), 'account_active' => true, 'directory_active' => null]];
        $plan = Planner::suggest($sources, [], true, true);
        self::assertSame(['', '', '', '__create__'], array_column($plan, 'choice'));
        self::assertSame(['account_disabled', 'directory_disabled', 'reference_only', 'auto_create'], array_column($plan, 'reason'));
        self::assertSame('__create__', Planner::suggest($sources, [], true, true, true)['disabled']['choice']);
        self::assertSame('7', Planner::suggest($sources, [$this->target(7, 'outside', '')], true, true)['directory']['choice']);
    }

    public function testExactUsernameAndEmailMatchesAreCaseInsensitiveAndHaveDistinctReasons(): void
    {
        $targets = [$this->target(1, 'alice', 'alice@example.org'), $this->target(2, 'bob', 'bob@example.org')];
        $sources = [$this->source('a', ' ALICE ', ''), $this->source('b', 'other', 'BOB@example.org'),
            $this->source('c', 'Alice', 'Alice@example.org')];
        $plan = Planner::suggest($sources, $targets, true, false);
        self::assertSame(['1', '2', '1'], array_column(array_values($plan), 'choice'));
        self::assertSame(['username', 'email', 'username_email'], array_column(array_values($plan), 'reason'));
    }

    public function testEmailAliasAndEmailShapedUsernameCanMatchAnInactiveUser(): void
    {
        $target = [...$this->target(17, 'local-login', 'primary@example.org'),
            'email_aliases' => ['alias@example.org'], 'is_active' => false];
        $plan = Planner::suggest([$this->source('a', 'unrelated', 'ALIAS@example.org'),
            $this->source('b', 'alias@example.org', '')], [$target], true, true);
        self::assertSame('17', $plan['a']['choice']);
        self::assertSame('17', $plan['b']['choice']);
        self::assertSame('email', $plan['b']['reason']);
    }

    public function testUsernameEmailDisagreementAndDuplicateTargetEmailNeverAutoMapOrCreate(): void
    {
        $targets = [$this->target(1, 'alice', 'shared@example.org'), $this->target(2, 'bob', 'shared@example.org')];
        $plan = Planner::suggest([$this->source('a', 'alice', 'shared@example.org')], $targets, true, true);
        self::assertSame(['choice' => '', 'reason' => 'conflict', 'conflict' => 'ambiguous'], $plan['a']);
        $different = Planner::suggest([$this->source('a', 'alice', 'bob@example.org')], [
            $this->target(1, 'alice', 'alice@example.org'), $this->target(2, 'bob', 'bob@example.org'),
        ], true, true);
        self::assertSame('ambiguous', $different['a']['conflict']);
    }

    public function testDisplayNameSimilarityIsNeverProofOfIdentity(): void
    {
        $source = [...$this->source('a', 'different-login', ''), 'display_name' => 'Alice Example'];
        $target = [...$this->target(1, 'alice', ''), 'display_name' => 'Alice Example'];
        self::assertSame('', Planner::suggest([$source], [$target], true, false)['a']['choice']);
    }

    public function testUnmappedCreationRequiresExplicitPolicyAndNeverDuplicatesExistingLogin(): void
    {
        $sources = [$this->source('a', 'new-user', 'new@example.org')];
        self::assertSame('', Planner::suggest($sources, [], true, false)['a']['choice']);
        self::assertSame('__create__', Planner::suggest($sources, [], true, true)['a']['choice']);
        $disabledMapping = Planner::suggest($sources, [$this->target(1, 'new-user', '')], false, true);
        self::assertSame('', $disabledMapping['a']['choice']);
        self::assertSame('collision', $disabledMapping['a']['conflict']);
    }

    public function testDuplicateSourceDirectoryLoginsAndEmailsRequireManualReview(): void
    {
        foreach (
            [[$this->source('dir-a', 'same', ''), $this->source('dir-b', 'SAME', '')],
            [$this->source('dir-a', 'first', 'same@example.org'), $this->source('dir-b', 'second', 'SAME@example.org')]] as $sources
        ) {
            $plan = Planner::suggest($sources, [], true, true);
            self::assertSame(['', ''], array_column(array_values($plan), 'choice'));
            self::assertSame(['collision', 'collision'], array_column(array_values($plan), 'conflict'));
        }
    }

    public function testMatchingLoginAndEmailOnOneSourceAreNotADuplicate(): void
    {
        self::assertSame('__create__', Planner::suggest([$this->source('a', 'alice@example.org', 'ALICE@example.org')], [], true, true)['a']['choice']);
    }

    public function testInvalidSourceFieldsAreNotSilentlyTruncatedOrCreated(): void
    {
        $plan = Planner::suggest([$this->source('a', str_repeat('x', 191), ''),
            $this->source('b', 'valid-login', 'invalid-email'), $this->source('c', "bad\x01login", '')], [], true, true);
        self::assertSame(['invalid_login', 'invalid_email', 'invalid_login'], array_column(array_values($plan), 'conflict'));
    }

    public function testThousandsOfSourceRecordsArePlannedIndependentlyOfPickerPagination(): void
    {
        $sources = [];
        for ($i = 1; $i <= 5000; ++$i) {
            $sources[] = $this->source('source-' . $i, 'account-' . $i, '');
        }
        $plan = Planner::suggest($sources, [$this->target(1, 'account-2500', '')], true, true);
        self::assertCount(5000, $plan);
        self::assertSame('1', $plan['source-2500']['choice']);
        self::assertSame(4999, count(array_filter($plan, static fn(array $row): bool => $row['choice'] === '__create__')));
    }

    /**
     * EN: Returns deterministic non-secret source metadata.
     *
     * HR: Vraća determinističke netajne izvorne metapodatke.
     *
     * @return array<string,mixed>
     */
    private function source(string $id, string $username, string $email): array
    {
        return ['source_id' => $id, 'username' => $username, 'email' => $email];
    }

    /**
     * EN: Returns the target projection the importer is allowed to compare.
     *
     * HR: Vraća ciljnu projekciju koju importer smije uspoređivati.
     *
     * @return array<string,mixed>
     */
    private function target(int $id, string $login, string $email): array
    {
        return ['id' => $id, 'login_identifier' => $login, 'email' => $email, 'email_aliases' => []];
    }
}
