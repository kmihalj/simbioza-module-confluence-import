<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests\Support;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceTeamCalendarReader;
use ZipArchive;

/**
 * EN: Small synthetic source data, with no copied users, secrets or event content from the real export.
 *
 * HR: Mali sintetički izvor bez kopiranih korisnika, tajni ili sadržaja događaja iz stvarnog izvoza.
 */
final class TeamCalendarsFixture
{
    /**
     * EN: Declares AO columns in order, including null/empty values, like a real site export.
     *
     * HR: Deklarira AO stupce redoslijedom, uključujući null/prazne vrijednosti, kao stvarni site izvoz.
     *
     * @param array<string,list<array<string,string>>> $tables
     */
    public static function archive(string $path, array $tables, string $prefix = ''): void
    {
        $xml = '<?xml version="1.0"?>' . $prefix . '<backup xmlns="http://www.atlassian.com/ao">';
        foreach ($tables as $name => $rows) {
            $xml .= '<table name="AO_950DC3_TC_' . $name . '">';
            foreach (array_keys($rows[0]) as $column) {
                $xml .= '<column name="' . $column . '"/>';
            }
            $xml .= '</table>';
        }
        foreach ($tables as $name => $rows) {
            $xml .= '<data tableName="AO_950DC3_TC_' . $name . '">';
            foreach ($rows as $row) {
                $xml .= '<row>';
                foreach ($row as $value) {
                    $xml .= '<string>' . htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</string>';
                }
                $xml .= '</row>';
            }
            $xml .= '</data>';
        }
        $xml .= '</backup>';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('exportDescriptor.properties', "exportType=site\nbackupAttachments=false\n");
        $zip->addFromString('entities.xml', '<hibernate-generic><object class="Space"><id>1</id><property name="key">ONE</property><property name="name">One</property></object>'
            . '<object class="InternalUser"><id>1</id><property name="name">alice</property><property name="active">true</property></object>'
            . '<object class="ConfluenceUserImpl"><id>alice-key</id><property name="lowerName">alice</property><property name="name">alice</property></object>'
            . '<object class="InternalGroup"><id>11</id><property name="name">staff</property></object></hibernate-generic>');
        $zip->addFromString(ConfluenceTeamCalendarReader::ENTRY, $xml);
        $zip->close();
    }

    /**
     * EN: Provides a native calendar and an event-type child.
     *
     * HR: Daje nativni kalendar i podkalendar tipa događaja.
     *
     * @return array<string,list<array<string,string>>>
     */
    public static function tables(): array
    {
        $calendar = ['ID' => 'calendar-one', 'PARENT_ID' => '', 'NAME' => 'Team one', 'DESCRIPTION' => 'Test',
            'CREATOR' => 'alice-key', 'COLOUR' => '#123456', 'SPACE_KEY' => 'ONE',
            'STORE_KEY' => 'com.atlassian.confluence.extra.calendar3.calendarstore.generic.GenericSubCalendarDataStore',
            'SUBSCRIPTION_ID' => '', 'TIME_ZONE_ID' => 'Europe/Zagreb', 'USING_CUSTOM_EVENT_TYPE_ID' => ''];
        return ['SUBCALS' => [$calendar, array_replace($calendar, ['ID' => 'type-one', 'PARENT_ID' => 'calendar-one',
            'NAME' => 'Meeting', 'STORE_KEY' => 'com.atlassian.confluence.extra.calendar3.calendarstore.generic.GenericLocalSubCalendarDataStore'])],
            'EVENTS' => [self::event()],
            'SUBCALS_PRIV_GRP' => [['ID' => '1', 'SUB_CALENDAR_ID' => 'calendar-one', 'TYPE' => 'VIEW', 'GROUP_NAME' => 'staff']],
            'SUBCALS_PRIV_USR' => [['ID' => '1', 'SUB_CALENDAR_ID' => 'calendar-one', 'TYPE' => 'EDIT', 'USER_KEY' => 'alice-key']]];
    }

    /**
     * EN: Uses UTC milliseconds for a summer event at 11:00 in Zagreb.
     *
     * HR: Koristi UTC milisekunde za ljetni događaj u 11:00 u Zagrebu.
     *
     * @return array<string,string>
     */
    public static function event(): array
    {
        return ['ID' => 'event-one', 'SUB_CALENDAR_ID' => 'type-one', 'SUMMARY' => 'Meeting', 'DESCRIPTION' => 'Plain text',
            'LOCATION' => 'Room', 'ORGANISER' => 'alice-key', 'START' => '1782118800000', 'END' => '1782122400000',
            'UTC_START' => '1782118800000', 'UTC_END' => '1782122400000', 'ALL_DAY' => 'false',
            'RECURRENCE_RULE' => '', 'RECURRENCE_ID_TIMESTAMP' => '', 'VEVENT_UID' => 'event-one@example.test'];
    }
}
