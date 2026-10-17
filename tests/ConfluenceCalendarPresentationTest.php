<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceCalendarPresentation;
use PHPUnit\Framework\TestCase;

final class ConfluenceCalendarPresentationTest extends TestCase
{
    /**
     * EN: Source palette tokens map to the published colors; arbitrary CSS remains rejected.
     *
     * HR: Oznake izvorne palete mapiraju se u objavljene boje; proizvoljni CSS ostaje odbijen.
     */
    public function testNamedPaletteAndHexValues(): void
    {
        foreach (
            ['gray' => '#091E42', 'green2' => '#00875A', 'purple' => '#5243AA',
            'green3' => '#36B37E', 'yellow' => '#FFC400', 'orange2' => '#FF5630',
            'turquoise' => '#00A3BF', 'green' => '#006644', 'bronze' => '#FFAB00',
            'pink' => '#DE350B', 'purple2' => '#403294', 'blue' => '#0052CC',
            'blue2' => '#0049B0', 'gray2' => '#0065FF', 'purple3' => '#253858',
            'green4' => '#008DA6', 'red' => '#BF2600', 'purple4' => '#8777D9',
            'green5' => '#00B8D9', 'green6' => '#42526E', 'orange' => '#FF8B00'] as $token => $hex
        ) {
            self::assertSame($hex, ConfluenceCalendarPresentation::color('subcalendar-' . $token));
        }
        self::assertSame('#12ABCD', ConfluenceCalendarPresentation::color(' #12abcd '));
        foreach (['subcalendar-unknown', 'red', '#abc', '#123456;background:url(x)', '', 'rgb(0,0,0)'] as $invalid) {
            self::assertNull(ConfluenceCalendarPresentation::color($invalid));
        }
    }

    /**
     * EN: Official/private calendars are personal; an ambiguous name does not silently become team.
     * A personal suggestion does not itself supply a mapped owner.
     *
     * HR: Službeni/privatni kalendari su osobni; nejasan naziv ne postaje prešutno timski.
     * Prijedlog osobnog kalendara sam ne određuje mapiranog vlasnika.
     */
    public function testPersonalDefaultsNeverInventTeamOrOwner(): void
    {
        foreach (['Službeni kalendar', 'Ana - službeni', 'Sluzbeni kalendar', 'Privatni raspored', 'Official calendar', 'Private calendar', 'Personal calendar'] as $name) {
            self::assertSame('personal', ConfluenceCalendarPresentation::suggestedType(['name' => $name]));
        }
        self::assertSame('personal', ConfluenceCalendarPresentation::suggestedType(['name' => 'Project', 'space_keys' => ['~alice']]));
        self::assertSame('', ConfluenceCalendarPresentation::suggestedType(['name' => 'Project', 'space_keys' => ['TEAM']]));
        self::assertSame('', ConfluenceCalendarPresentation::suggestedType([]));
    }
}
