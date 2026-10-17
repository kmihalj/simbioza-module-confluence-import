<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteMappingReview;
use PHPUnit\Framework\TestCase;

/**
 * EN: Review proposals never become saved identity, ownership or calendar-type choices.
 *
 * HR: Prijedlozi pregleda nikad ne postaju spremljeni odabiri identiteta, vlasništva ili vrste kalendara.
 */
final class ConfluenceSiteMappingReviewTest extends TestCase
{
    /**
     * EN: Active primary suggestions retain both sources and never silently apply the proposed merge.
     *
     * HR: Prijedlog aktivnog glavnog računa čuva oba izvora i ne primjenjuje spajanje prešutno.
     */
    public function testPrimaryProposalIsReadOnlyAndPrefersAnExistingTarget(): void
    {
        $access = ['catalog' => ['users' => [$this->user('old', 'old', 'Ana Čović', 'ana@example.test', false),
            $this->user('new', 'ana', 'Ana Čović', 'ana@example.test', true)]],
            'choices' => ['users' => ['old' => '7', 'new' => ''], 'user_conflicts' => ['new' => 'collision']]];
        $before = serialize($access);
        $review = ConfluenceSiteMappingReview::build($access, []);
        self::assertCount(1, $review['duplicates']);
        self::assertSame('old', $review['duplicates'][0]['primary']);
        self::assertSame('name_identifier', $review['duplicates'][0]['confidence']);
        self::assertSame(1, $review['conflicts']);
        self::assertSame($before, serialize($access));
    }

    /**
     * EN: Shared service email and same-name/different-email pairs remain explicitly weak or conflicting.
     *
     * HR: Zajednički servisni email i parovi istog imena/različitog emaila ostaju izričito slabi ili sukobljeni.
     */
    public function testSharedEmailAndNamesAreEvidenceNotIdentityProof(): void
    {
        $users = [$this->user('person', 'person', 'Ana Čović', 'shared@example.test', true),
            $this->user('service', 'connector', 'Service Connector', 'shared@example.test', true),
            $this->user('a', 'one', 'Ivan Marić', 'one@example.test', true),
            $this->user('b', 'two', 'Ivan Marić', 'two@example.test', true)];
        $review = ConfluenceSiteMappingReview::build(['catalog' => ['users' => $users]], []);
        self::assertCount(2, $review['duplicates']);
        self::assertSame(['different_names', 'name_only'], array_column($review['duplicates'], 'confidence'));
    }

    /**
     * EN: Resolved aliases disappear from pending duplicate groups, while separate future accounts do not.
     *
     * HR: Razriješene veze nestaju iz neriješenih grupa, ali zasebni budući računi ne nestaju.
     */
    public function testResolvedGroupsAreNotReportedAsPending(): void
    {
        $access = ['catalog' => ['users' => [$this->user('a', 'a', 'Ana Čović', 'a@example.test', true),
            $this->user('b', 'b', 'Ana Čović', 'a@example.test', false)]],
            'choices' => ['users' => ['a' => '__create__', 'b' => '__source__:a']]];
        self::assertSame([], ConfluenceSiteMappingReview::build($access, [])['duplicates']);
        $access['choices']['users']['b'] = '__create__';
        self::assertCount(1, ConfluenceSiteMappingReview::build($access, [])['duplicates']);
    }

    /**
     * EN: Room/vehicle/project names propose team even when embedded on a personal page; drafts stay unchanged.
     *
     * HR: Dvorana/vozilo/projekt predlaže timski i kada je na osobnoj stranici; nacrt ostaje isti.
     */
    public function testSharedResourcesDoNotInheritTheEmbeddingPageOwner(): void
    {
        $names = ['Dvorana – Example 1', 'Rezervacija službenog vozila', 'Team calendar', 'Timski kalendar'];
        $calendar = ['catalog' => ['calendars' => []], 'plan' => ['decisions' => []]];
        foreach ($names as $id => $name) {
            $calendar['catalog']['calendars'][(string)$id] = ['name' => $name, 'space_keys' => ['~ana']];
            $calendar['plan']['decisions'][(string)$id] = ['calendar_type' => 'personal', 'owner_ref' => ''];
        }
        $before = serialize($calendar);
        $review = ConfluenceSiteMappingReview::build([], $calendar);
        self::assertCount(4, $review['calendars']);
        self::assertSame(['team' => 4], $review['types']);
        self::assertSame(['personal'], array_values(array_unique(array_column($review['calendars'], 'current_type'))));
        self::assertSame($before, serialize($calendar));
    }

    /**
     * EN: Personal names retain candidates, unknown mixed contexts need a decision, and completed choices are omitted.
     *
     * HR: Osobna imena čuvaju kandidate, nepoznati miješani konteksti traže odluku, a gotovi odabiri se izostavljaju.
     */
    public function testCalendarReviewIncludesOnlyIncompleteSelectionsAndDoesNotGuessOwners(): void
    {
        $calendar = ['catalog' => ['calendars' => [
            'person' => ['name' => 'Ana Čović - službeni kalendar', 'space_keys' => []],
            'unknown' => ['name' => 'Custom', 'space_keys' => ['~ana', 'TEAM']],
            'team' => ['name' => 'Team', 'space_keys' => []],
            'done' => ['name' => 'Personal', 'space_keys' => []],
        ]], 'plan' => ['decisions' => ['team' => ['calendar_type' => 'team'],
            'done' => ['calendar_type' => 'personal', 'owner_ref' => 'a']]],
            'owner_suggestions' => ['person' => ['status' => 'unmapped', 'candidates' => [
                ['ref' => 'a', 'reason' => 'name', 'label' => 'Ana Čović'],
            ]]]];
        $review = ConfluenceSiteMappingReview::build([], $calendar);
        self::assertSame(['person', 'unknown'], array_column($review['calendars'], 'id'));
        self::assertSame(['personal', ''], array_column($review['calendars'], 'proposed_type'));
        self::assertSame('', $review['calendars'][0]['current_type']);
        self::assertSame('a', $review['calendars'][0]['candidates'][0]['ref']);
        self::assertArrayNotHasKey('owner_ref', $review['calendars'][0]);
    }

    /**
     * EN: Supplies a non-secret synthetic directory entry.
     *
     * HR: Daje sintetički zapis imenika bez tajnih podataka.
     *
     * @return array<string,mixed>
     */
    private function user(string $ref, string $login, string $name, string $email, bool $active): array
    {
        return ['source_id' => $ref, 'username' => $login, 'display_name' => $name, 'email' => $email, 'active' => $active];
    }

    public function testDisabledDirectoryDoesNotOutrankAnEnabledDirectoryBecauseOfShortLogin(): void
    {
        $old = [...$this->user('old', 'short', 'Example Person', 'same@example.test', true),
            'account_active' => true, 'directory_active' => false];
        $current = [...$this->user('new', 'long@example.test', 'Example Person', 'same@example.test', true),
            'account_active' => true, 'directory_active' => true];
        $access = ['catalog' => ['users' => [$old, $current]]];
        self::assertSame('new', ConfluenceSiteMappingReview::build($access, [])['duplicates'][0]['primary']);
    }

    /**
     * EN: A page hint for a different given name is suppressed instead of proposing the wrong owner.
     *
     * HR: Trag stranice drugog imena izostavlja se umjesto predlaganja pogrešnog vlasnika.
     */
    public function testUnrelatedEmbeddingOwnerIsNotProposedForAnExplicitPerson(): void
    {
        $calendar = ['catalog' => ['calendars' => ['one' => ['name' => 'Davorin Kremenjaš - službeni kalendar', 'space_keys' => ['~ognjen']]]],
            'owner_suggestions' => ['one' => ['status' => 'hint', 'candidates' => [
                ['ref' => 'ognjen', 'label' => 'Ognjen Orel', 'username' => 'ognjen', 'reason' => 'personal_space'],
            ]]]];
        self::assertSame([], ConfluenceSiteMappingReview::build([], $calendar)['calendars'][0]['candidates']);
    }

    /**
     * EN: Changed-surname candidates need a matching given name and linked source login and remain unaccepted.
     *
     * HR: Kandidati promijenjenog prezimena traže isto ime i povezani izvorni login te ostaju neprihvaćeni.
     */
    public function testNameAndPersonalSpaceProduceOnlyAnExplicitlyWeakReviewCandidate(): void
    {
        $source = $this->user('ana', 'azemljak', 'Ana Zemljak', 'ana@example.test', true);
        $calendar = ['catalog' => ['calendars' => ['one' => ['name' => 'Ana Zemljak Pećina', 'space_keys' => ['~azemljak']]]],
            'owner_choices' => ['ana' => ['label' => 'Ana Zemljak', 'target' => 'new:ana']]];
        $review = ConfluenceSiteMappingReview::build(['catalog' => ['users' => [$source]]], $calendar);
        self::assertSame('personal', $review['calendars'][0]['proposed_type']);
        self::assertSame('name_space', $review['calendars'][0]['candidates'][0]['reason']);
        self::assertSame('ana', $review['calendars'][0]['candidates'][0]['ref']);
        self::assertArrayNotHasKey('owner_ref', $review['calendars'][0]);
    }
}
