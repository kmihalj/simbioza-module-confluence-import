<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceCalendarOwnerSuggestions;
use PHPUnit\Framework\TestCase;

/**
 * EN: Owner suggestions remain inert and ambiguous identities never become accepted owners.
 *
 * HR: Prijedlozi vlasnika ne izvršavaju promjene, a dvosmisleni identiteti nisu prihvaćeni vlasnici.
 */
final class ConfluenceCalendarOwnerSuggestionsTest extends TestCase
{
    /**
     * EN: A full name proposes its mapped person, not a different mapped calendar creator.
     *
     * HR: Puno ime predlaže mapiranu osobu, ne drugog mapiranog kreatora kalendara.
     */
    public function testNameSuggestionDoesNotUseCreatorAndDoesNotMutateInputs(): void
    {
        $sources = [$this->user('alice', 'Ana Čović', 'acovic'), $this->user('operator', 'Tehnički administrator', 'operator')];
        $owners = ['alice' => ['label' => 'Ana Čović', 'target' => 7], 'operator' => ['label' => 'Operator', 'target' => 85]];
        $calendar = ['name' => 'Ana Covic - službeni kalendar', 'owner_key' => 'operator', 'space_keys' => ['~operator']];
        $result = ConfluenceCalendarOwnerSuggestions::build(['one' => $calendar], $sources, $owners)['one'];
        self::assertSame('mapped', $result['status']);
        self::assertTrue($result['personal_candidate']);
        self::assertSame('alice', $result['choice']);
        self::assertSame(7, $result['candidates'][0]['target']);
        self::assertSame('name', $result['candidates'][0]['reason']);
        self::assertArrayNotHasKey('owner_ref', $calendar);
        self::assertSame('operator', $calendar['owner_key']);
    }

    /**
     * EN: A unique source match must be mapped before it can populate the owner picker.
     * A planned inactive account remains explicit and does not need a fake database ID.
     *
     * HR: Jedinstveni izvorni kandidat mora biti mapiran prije popunjavanja odabira vlasnika.
     * Planirani neaktivni račun ostaje izričit i ne treba izmišljeni ID baze.
     */
    public function testUnmappedAndPlannedOwnersAreSeparated(): void
    {
        $calendar = ['one' => ['name' => 'acovic@example.test - privatni', 'space_keys' => []]];
        $source = $this->user('alice', 'Ana Čović', 'acovic@example.test');
        $unmapped = ConfluenceCalendarOwnerSuggestions::build($calendar, [$source], [])['one'];
        self::assertSame('unmapped', $unmapped['status']);
        self::assertSame('', $unmapped['choice']);
        self::assertSame('login', $unmapped['candidates'][0]['reason']);
        $planned = ConfluenceCalendarOwnerSuggestions::build($calendar, [$source], ['alice' => ['label' => 'Ana Čović', 'target' => 'new:alice']])['one'];
        self::assertSame('planned', $planned['status']);
        self::assertSame('alice', $planned['choice']);
        self::assertSame('new:alice', $planned['candidates'][0]['target']);
    }

    /**
     * EN: Equal names with different target users are a conflict, including accent-folding collisions.
     * Two selected source identities for one target are not a false conflict.
     *
     * HR: Ista imena s različitim ciljevima sukob su, uključujući podudaranje bez dijakritika.
     * Dva odabrana izvorna identiteta istog cilja nisu lažni sukob.
     */
    public function testAmbiguousNamesCannotBeAcceptedAsUnique(): void
    {
        $sources = [$this->user('a', 'Ana Čović', 'one'), $this->user('b', 'Ana Ćović', 'two')];
        $calendars = ['one' => ['name' => 'Ana Covic - službeni', 'space_keys' => []]];
        $owners = ['a' => ['label' => 'One', 'target' => 1], 'b' => ['label' => 'Two', 'target' => 2]];
        $ambiguous = ConfluenceCalendarOwnerSuggestions::build($calendars, $sources, $owners)['one'];
        self::assertSame('ambiguous', $ambiguous['status']);
        self::assertSame('', $ambiguous['choice']);
        self::assertSame(2, $ambiguous['candidate_count']);
        $owners['b']['target'] = 1;
        self::assertSame('mapped', ConfluenceCalendarOwnerSuggestions::build($calendars, $sources, $owners)['one']['status']);
    }

    /**
     * EN: A page embedding a calendar is only a hint; multiple linked spaces and the creator are not guesses.
     * Partial names and substring matches do not establish a candidate.
     *
     * HR: Stranica koja ugrađuje kalendar samo je trag; više povezanih područja i kreator nisu nagađanje.
     * Dijelovi imena i podudaranja podnizova ne određuju kandidata.
     */
    public function testPersonalSpaceHintRequiresManualChoiceAndCreatorAloneIsIgnored(): void
    {
        $sources = [$this->user('alice', 'Ana Čović', 'acovic')];
        $owners = ['alice' => ['label' => 'Ana Čović', 'target' => 7]];
        $calendars = ['hint' => ['name' => 'Moj kalendar', 'space_keys' => ['~acovic']],
            'multiple' => ['name' => 'Službeni kalendar Srca', 'space_keys' => ['~acovic', '~someone']],
            'creator' => ['name' => 'Ana', 'owner_key' => 'alice', 'space_keys' => []]];
        $results = ConfluenceCalendarOwnerSuggestions::build($calendars, $sources, $owners);
        self::assertSame('hint', $results['hint']['status']);
        self::assertSame('', $results['hint']['choice']);
        self::assertSame('personal_space', $results['hint']['candidates'][0]['reason']);
        self::assertSame('none', $results['multiple']['status']);
        self::assertSame('none', $results['creator']['status']);
        self::assertFalse($results['creator']['personal_candidate']);
    }

    /**
     * EN: Bounds rendered candidates while keeping the full ambiguity count.
     *
     * HR: Ograničava prikazane kandidate uz puni broj dvosmislenih podudaranja.
     */
    public function testManySameNameCandidatesRemainBoundedAndUnaccepted(): void
    {
        $sources = [];
        for ($i = 1; $i <= 30; ++$i) {
            $sources[] = $this->user('u' . $i, 'Ana Čović', 'login' . $i);
        }
        $result = ConfluenceCalendarOwnerSuggestions::build(['one' => ['name' => 'Ana Čović - osobni']], $sources, [])['one'];
        self::assertSame('ambiguous', $result['status']);
        self::assertSame(30, $result['candidate_count']);
        self::assertCount(10, $result['candidates']);
        self::assertSame('', $result['choice']);
    }

    /**
     * EN: Supplies non-secret source identities without depending on Auth implementation details.
     *
     * HR: Daje netajne izvorne identitete bez ovisnosti o internim detaljima Autha.
     *
     * @return array<string,mixed>
     */
    private function user(string $ref, string $name, string $login): array
    {
        return ['source_id' => $ref, 'display_name' => $name, 'username' => $login, 'email' => '', 'first_name' => '', 'last_name' => ''];
    }
}
