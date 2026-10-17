<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteUserPagination;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * EN: Exercises real result slices and rendered controls, not only template source text.
 *
 * HR: Provjerava stvarne izreze rezultata i renderirane kontrole, ne samo izvorni tekst predloška.
 */
final class ConfluenceSiteUserPaginationTest extends TestCase
{
    public function testFirstMiddleAndLastPageHaveStableNonOverlappingSlices(): void
    {
        $rows = array_reverse($this->users(442));
        foreach ([1 => [1, 50], 2 => [51, 100], 9 => [401, 442]] as $page => [$from, $to]) {
            $result = ConfluenceSiteUserPagination::paginate($rows, ['identity_page' => $page]);
            self::assertSame($from, $result['from']);
            self::assertSame($to, $result['to']);
            self::assertSame(442, $result['total']);
            self::assertSame(9, $result['pages']);
            self::assertContains($page, $result['numbers']);
            self::assertSame(1, $result['numbers'][0]);
            self::assertSame(9, $result['numbers'][count($result['numbers']) - 1]);
            self::assertLessThanOrEqual(9, count($result['numbers']));
            self::assertSame('user:' . $from, $result['items'][0]['source_id']);
            self::assertSame('user:' . $to, $result['items'][count($result['items']) - 1]['source_id']);
        }
    }

    public function testFilterPrecedesPaginationAndUsesDisplayNamesEmailAndDirectory(): void
    {
        $users = $this->users(100);
        $users[30]['display_name'] = 'Željko';
        $users[30]['email'] = 'Unique@example.org';
        foreach (['žELJKO', 'unique@EXAMPLE.org', 'user:31'] as $search) {
            $result = ConfluenceSiteUserPagination::paginate($users, ['identity_q' => $search, 'identity_page' => 9]);
            self::assertSame(1, $result['total']);
            self::assertSame(1, $result['page']);
            self::assertSame('user:31', $result['items'][0]['source_id']);
        }
        self::assertSame(100, ConfluenceSiteUserPagination::paginate($users, ['identity_q' => 'directory'])['total']);
    }

    public function testSizesBoundsEmptyResultsAndMalformedQueryParameters(): void
    {
        foreach ([25 => 18, 50 => 9, 100 => 5, 999 => 9] as $size => $pages) {
            $result = ConfluenceSiteUserPagination::paginate($this->users(442), ['identity_per_page' => $size, 'identity_page' => 999]);
            self::assertSame($pages, $result['pages']);
            self::assertSame($pages, $result['page']);
            self::assertSame(442, $result['to']);
        }
        $empty = ConfluenceSiteUserPagination::paginate($this->users(442), ['identity_q' => 'no-such-user', 'identity_page' => 9]);
        self::assertSame([0, 0, 0, 1, 1, []], [$empty['from'], $empty['to'], $empty['total'], $empty['page'], $empty['pages'], $empty['items']]);
        self::assertSame(1, ConfluenceSiteUserPagination::paginate([], [])['page']);
        $invalid = ConfluenceSiteUserPagination::paginate($this->users(100), [
            'identity_q' => ['nested'], 'identity_page' => ['9'], 'identity_per_page' => ['100'],
        ]);
        self::assertSame('', $invalid['query']);
        self::assertSame(1, $invalid['page']);
        self::assertSame(50, $invalid['per_page']);
        self::assertSame(1, ConfluenceSiteUserPagination::paginate($this->users(100), ['identity_page' => -1])['page']);
    }

    public function testLargeCatalogHasBoundedWindowAndExplicitGaps(): void
    {
        $result = ConfluenceSiteUserPagination::paginate($this->users(1000), ['identity_page' => 20, 'identity_per_page' => 25]);
        self::assertSame([1, null, 18, 19, 20, 21, 22, null, 40], $result['numbers']);
        self::assertSame([1, null, 38, 39, 40], ConfluenceSiteUserPagination::paginate($this->users(1000), [
            'identity_page' => 40, 'identity_per_page' => 25,
        ])['numbers']);
        self::assertSame([1, 2, 3, 4, null, 9], ConfluenceSiteUserPagination::paginate($this->users(442), ['identity_page' => 2])['numbers']);
        $thousands = ConfluenceSiteUserPagination::paginate($this->users(5000), ['identity_page' => 100, 'identity_per_page' => 25]);
        self::assertSame([1, null, 98, 99, 100, 101, 102, null, 200], $thousands['numbers']);
        self::assertSame(200, $thousands['pages']);
        $xpath = $this->render(['identity_page' => 100, 'identity_per_page' => 25], [], [], 5000);
        self::assertSame(2, $xpath->query('//ul[contains(@class,"pagination")]/li/span[@aria-current="page" and text()="100"]')->length);
        self::assertSame(26, $xpath->query('//ul[contains(@class,"pagination")]/li')->length);
    }

    public function testDuplicateDisplayNamesHaveDeterministicSourceIdTieBreaker(): void
    {
        $rows = $this->users(100);
        foreach ($rows as &$user) {
            $user['display_name'] = 'Same name';
        }
        unset($user);
        self::assertSame(ConfluenceSiteUserPagination::paginate($rows, [])['items'], ConfluenceSiteUserPagination::paginate(array_reverse($rows), [])['items']);
    }

    public function testMappingStatusFiltersBeforePaginationAndCombinesWithSearch(): void
    {
        $users = $this->users(100);
        $choices = ['user:1' => '42', 'user:2' => '43', 'user:3' => '__create__', 'user:4' => ''];
        $reasons = ['user:1' => 'email', 'user:2' => 'manual', 'user:3' => 'auto_create', 'user:4' => 'conflict'];
        $conflicts = ['user:4' => 'ambiguous'];
        foreach (['mapped' => 2, 'unmapped' => 97, 'create' => 1, 'automatic' => 1, 'conflict' => 1] as $status => $total) {
            $result = ConfluenceSiteUserPagination::paginate($users, ['identity_status' => $status, 'identity_page' => 99], $choices, $reasons, $conflicts);
            self::assertSame($total, $result['total']);
            self::assertSame(100, $result['counts']['all']);
            self::assertSame(2, $result['counts']['mapped']);
            self::assertSame(min(2, (int)ceil($total / 50)), $result['page']);
        }
        self::assertSame(0, ConfluenceSiteUserPagination::paginate($users, [
            'identity_status' => 'mapped', 'identity_q' => 'User 099',
        ], $choices, $reasons, $conflicts)['total']);
        self::assertSame('all', ConfluenceSiteUserPagination::paginate($users, ['identity_status' => ['mapped']])['status']);
    }

    public function testRenderedNavigationMarksCurrentPageAndPreservesFilterSizeLanguageAndJob(): void
    {
        $xpath = $this->render(['identity_page' => 2, 'identity_per_page' => 50, 'identity_q' => 'User', 'lang' => 'en']);
        self::assertSame(2, $xpath->query('//nav[@aria-label="Stranice izvornih korisnika"]')->length);
        self::assertSame(2, $xpath->query('//span[@aria-current="page" and text()="2"]')->length);
        self::assertSame(2, $xpath->query('//a[@aria-label="Prva stranica" and text()="«"]')->length);
        self::assertSame(2, $xpath->query('//a[@aria-label="Prethodna stranica" and text()="‹"]')->length);
        self::assertSame(2, $xpath->query('//a[@aria-label="Sljedeća stranica" and text()="›"]')->length);
        self::assertSame(2, $xpath->query('//a[@aria-label="Zadnja stranica" and text()="»"]')->length);
        self::assertSame(50, $xpath->query('//input[@data-site-kind="users"]')->length);
        foreach ($xpath->query('//a[@data-site-identity-page]') as $link) {
            $href = $link->getAttribute('href');
            self::assertStringContainsString('job=job-uuid', $href);
            self::assertStringContainsString('identity_per_page=50', $href);
            self::assertStringContainsString('identity_q=User', $href);
            self::assertStringContainsString('lang=en', $href);
            self::assertStringEndsWith('#site-identities', $href);
        }
        self::assertSame(0, $xpath->query('//form[@id="confluence-site-identity-filter"]//input[@name="identity_page"]')->length);
    }

    public function testBoundaryControlsAreDisabledSpansAndEmptySearchIsExplicit(): void
    {
        $first = $this->render(['identity_page' => 1]);
        self::assertSame(2, $first->query('//span[@aria-disabled="true" and @aria-label="Prva stranica"]')->length);
        self::assertSame(0, $first->query('//a[@aria-label="Prva stranica"]')->length);
        $last = $this->render(['identity_page' => 9]);
        self::assertSame(2, $last->query('//span[@aria-disabled="true" and @aria-label="Zadnja stranica"]')->length);
        self::assertSame(42, $last->query('//input[@data-site-kind="users"]')->length);
        $none = $this->render(['identity_q' => '<script>invalid</script>', 'identity_page' => 9]);
        self::assertSame(0, $none->query('//nav')->length);
        self::assertSame(0, $none->query('//script')->length);
        self::assertSame(1, $none->query('//td[text()="Nema korisnika koji odgovaraju pretrazi."]')->length);
    }

    public function testSavedMappingDisplaysTheSameFullAccountLabelAsThePickerResult(): void
    {
        $xpath = $this->render([], ['user:1' => '17'], [['id' => 17,
            'display_name' => 'Target name', 'login_identifier' => 'target.login']]);
        self::assertSame(1, $xpath->query('//button[@id="c2-user-0-toggle" and text()="Target name · target.login"]')->length);
        self::assertSame(1, $xpath->query('//input[@data-source-id="user:1" and @value="17"]')->length);
        $script = (string)file_get_contents(__DIR__ . '/../views/settings/index.php');
        self::assertStringContainsString("choice.setAttribute('aria-pressed', String(selected))", $script);
        self::assertStringContainsString("value.dispatchEvent(new Event('change', {bubbles: true}))", $script);
        self::assertStringContainsString("panel.addEventListener('mousedown'", $script);
        self::assertStringContainsString('next.search === current.search) window.location.reload()', $script);
        self::assertStringContainsString("panel.showPopover === 'function'", $script);
        self::assertStringContainsString('panel.hidePopover()', $script);
        self::assertSame(50, $xpath->query('//div[@data-identity-picker-panel and @popover="manual"]')->length);
    }

    /**
     * EN: Creates deterministic metadata only, without real identities or credentials.
     *
     * HR: Stvara samo determinističke metapodatke, bez stvarnih identiteta ili vjerodajnica.
     *
     * @return list<array<string,mixed>>
     */
    private function users(int $count): array
    {
        return array_map(static fn(int $i): array => ['source_id' => 'user:' . $i,
            'username' => 'user-' . $i, 'display_name' => sprintf('User %03d', $i),
            'email' => '', 'directory_name' => 'Directory'], range(1, $count));
    }

    /**
     * EN: Renders the actual nested PHP templates with escaping and source-page choices.
     *
     * HR: Renderira stvarne ugniježđene PHP predloške s escapingom i odabirima izvorne stranice.
     *
     * @param array<string,mixed> $query
     */
    private function render(array $query, array $choices = [], array $targets = [], int $count = 442): DOMXPath
    {
        $view = new class {
            public function escape(string $value): string
            {
                return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            public function render(array $data): string
            {
                extract($data);
                ob_start();
                require __DIR__ . '/../views/settings/site-access.php';
                return (string)ob_get_clean();
            }
        };
        $html = $view->render(['siteAccessState' => ['ready' => true, 'catalog' => ['users' => $this->users($count),
            'groups' => [], 'memberships' => []], 'source_url' => 'https://source.test', 'memberships' => false,
            'target_users' => $targets, 'target_groups' => [], 'choices' => ['users' => $choices, 'groups' => []]],
            'siteAccessQuery' => $query, 'job' => ['uuid' => 'job-uuid'], 'settingsPath' => '/hfc/settings/confluence-import',
            'siteUsersPath' => '/site/users', 'sourceBaseUrl' => 'https://source.test']);
        $dom = new DOMDocument();
        $before = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }
        return new DOMXPath($dom);
    }
}
