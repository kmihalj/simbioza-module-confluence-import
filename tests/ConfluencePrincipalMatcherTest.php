<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluencePrincipalMatcher;
use PHPUnit\Framework\TestCase;

final class ConfluencePrincipalMatcherTest extends TestCase
{
    /** HR: Točan naziv ili ključ grupe daje prijedlog, sličan naziv ne. EN: An exact group name or key is suggested; a merely similar name is not. */
    public function testGroupSuggestionUsesStrictNormalizedIdentity(): void
    {
        $matcher = new ConfluencePrincipalMatcher();
        $groups = [
            ['id' => 11, 'group_key' => 'srce-zaposlenici', 'group_name' => 'SRCE zaposlenici', 'is_enabled' => true],
            ['id' => 12, 'group_key' => 'srce-suradnici', 'group_name' => 'SRCE suradnici', 'is_enabled' => true],
        ];

        self::assertSame(11, $matcher->suggestGroupId(' SRCE-ZAPOSLENICI ', $groups));
        self::assertSame(11, $matcher->suggestGroupId('srce zaposlenici', $groups));
        self::assertNull($matcher->suggestGroupId('srce-zaposlenik', $groups));
    }

    /** HR: Neaktivni ciljni korisnici također se predlažu po točnom emailu. EN: Inactive target users are also suggested by exact email. */
    public function testUserSuggestionIncludesInactiveExactEmailMatch(): void
    {
        $matcher = new ConfluencePrincipalMatcher();
        $users = [[
            'id' => 21,
            'login_identifier' => 'staged-user',
            'email' => 'person@example.test',
            'email_aliases' => ['alias@example.test'],
            'is_active' => false,
        ]];

        self::assertSame(21, $matcher->suggestUserId(['email' => 'PERSON@example.test'], $users));
        self::assertSame(21, $matcher->suggestUserId(['email' => 'alias@example.test'], $users));
    }

    /** HR: Dvosmisleni email ostaje bez automatskog mapiranja. EN: An ambiguous email remains unmapped. */
    public function testAmbiguousUserEmailIsNeverAutoMapped(): void
    {
        $matcher = new ConfluencePrincipalMatcher();
        $users = [
            ['id' => 31, 'login_identifier' => 'first', 'email' => 'shared@example.test'],
            ['id' => 32, 'login_identifier' => 'second', 'email' => 'shared@example.test'],
        ];

        self::assertNull($matcher->suggestUserId(['email' => 'shared@example.test'], $users));
    }

    /** HR: Izvor bez korisničkog imena ili emaila dobiva stabilnu tehničku oznaku. EN: A source without username or email receives a stable technical identifier. */
    public function testInactiveLoginIdentifierFallsBackToStableSourceHash(): void
    {
        $matcher = new ConfluencePrincipalMatcher();
        $source = ['source_key' => 'confluence-user-123'];

        self::assertSame(
            $matcher->inactiveLoginIdentifier($source),
            $matcher->inactiveLoginIdentifier($source),
        );
        self::assertStringStartsWith('confluence-', $matcher->inactiveLoginIdentifier($source));
    }
}
