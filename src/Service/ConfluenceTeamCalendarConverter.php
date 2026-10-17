<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * EN: Converts supported AO events to inert ICS for the Calendar bulk-import API.
 * Unsupported recurrence/source semantics are reported instead of silently changing a series.
 *
 * HR: Pretvara podržane AO događaje u pasivni ICS za Calendar API skupnog uvoza.
 * Nepodržana ponavljanja i izvori prijavljuju se umjesto tihe promjene serije.
 */
final class ConfluenceTeamCalendarConverter
{
    /** @var array<string,true> */
    private array $zones;

    /**
     * EN: Builds the timezone allowlist once, not once per event.
     *
     * HR: Jednom gradi popis dopuštenih zona, ne zasebno za svaki događaj.
     */
    public function __construct()
    {
        $this->zones = array_fill_keys(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
        $this->zones['UTC'] = true;
    }
    /**
     * EN: Prepares bounded batches; all master exclusions are known before any exception is imported.
     * No ATTENDEE, VALARM, METHOD or subscription URL is emitted.
     *
     * HR: Priprema ograničene pakete; sve iznimke serije poznate su prije uvoza izmijenjenog događaja.
     * Ne ispisuje ATTENDEE, VALARM, METHOD ili URL pretplate.
     *
     * @param list<array<string,mixed>> $events
     * @param array<string,mixed> $calendar
     * @return array{batches:list<array{type:string,ics:string,count:int}>,warnings:list<array{id:string,reason:string}>,skipped:int,events:int}
     */
    public function convert(#[\SensitiveParameter] array $events, array $calendar): array
    {
        if (count($events) > 100000) {
            throw new ConfluenceImportException(__('Kalendar sadrži previše događaja za jedan pregled.'));
        }
        $masters = $exceptions = $blocked = $identities = $warnings = [];
        foreach ($events as $row) {
            $uid = $this->uid($row);
            $recurrence = (string)($row['RECURRENCE_ID_TIMESTAMP'] ?? '');
            $identity = $uid . ':' . $recurrence;
            if (isset($identities[$identity])) {
                $blocked[$uid] = __('Serija ima ponovljen UID ili ponovljenu iznimku.');
            }
            $identities[$identity] = true;
            if ($recurrence === '') {
                $masters[$uid] = $row;
                try {
                    $this->component($row, $calendar, []);
                } catch (ConfluenceImportException $error) {
                    $blocked[$uid] = $error->getMessage();
                }
            } else {
                $exceptions[$uid][] = ['value' => $recurrence, 'all_day' => ($row['ALL_DAY'] ?? '') === 'true'];
            }
        }
        foreach ($masters as $uid => $row) {
            try {
                $this->component($row, $calendar, $exceptions[$uid] ?? []);
            } catch (ConfluenceImportException $error) {
                $blocked[$uid] = $error->getMessage();
            }
        }
        $byType = [];
        $skipped = 0;
        $imported = 0;
        foreach ($events as $row) {
            $uid = $this->uid($row);
            try {
                if (isset($blocked[$uid])) {
                    throw new ConfluenceImportException($blocked[$uid]);
                }
                if (($row['RECURRENCE_ID_TIMESTAMP'] ?? '') !== '' && !isset($masters[$uid])) {
                    throw new ConfluenceImportException(__('Iznimka nema izvornu seriju u arhivi.'));
                }
                $type = $calendar['types'][$row['SUB_CALENDAR_ID']] ?? [];
                $label = mb_substr(trim((string)($type['label'] ?? '')), 0, 120) ?: 'Confluence';
                $byType['type:' . $label][] = $this->component($row, $calendar, $exceptions[$uid] ?? []);
                ++$imported;
            } catch (ConfluenceImportException $error) {
                ++$skipped;
                if (count($warnings) < 200) {
                    $warnings[] = ['id' => (string)$row['ID'], 'reason' => $error->getMessage()];
                }
            }
        }
        $batches = [];
        foreach ($byType as $label => $components) {
            foreach (array_chunk($components, 250) as $chunk) {
                $batches[] = ['type' => substr($label, 5), 'count' => count($chunk),
                    'ics' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Simbioza//Confluence migration//EN\r\n" . implode('', $chunk) . "END:VCALENDAR\r\n"];
            }
        }
        return ['batches' => $batches, 'warnings' => $warnings, 'skipped' => $skipped, 'events' => $imported];
    }

    /**
     * EN: Preserves source UIDs; an absent UID receives a deterministic source-event identity.
     *
     * HR: Čuva izvorne UID-jeve; nedostajući UID dobiva deterministički identitet izvornog događaja.
     *
     * @param array<string,mixed> $row
     */
    private function uid(#[\SensitiveParameter] array $row): string
    {
        return trim((string)($row['VEVENT_UID'] ?? '')) ?: 'confluence-' . hash('sha256', (string)$row['ID']);
    }

    /**
     * EN: Emits plain text only, explicit TZID/date values, source exceptions and supported RRULEs.
     * Calendar stores inert provenance without interpreting it as executable HTML or notifications.
     *
     * HR: Ispisuje samo obični tekst, izričite TZID/datume, iznimke i podržane RRULE vrijednosti.
     * Calendar sprema pasivno podrijetlo bez tumačenja kao izvršivi HTML ili obavijesti.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $calendar
     * @param list<array{value:string,all_day:bool}> $exceptions
     */
    private function component(#[\SensitiveParameter] array $row, array $calendar, array $exceptions): string
    {
        $type = $calendar['types'][$row['SUB_CALENDAR_ID']] ?? null;
        if (!is_array($type) || preg_match('/\.(GenericSubCalendarDataStore|GenericLocalSubCalendarDataStore|CustomSubCalendarDataStore|BirthdaySubCalendarDataStore|TravelSubCalendarDataStore|LeaveSubCalendarDataStore)$/D', (string)$type['store']) !== 1) {
            throw new ConfluenceImportException(__('Izvor događaja nije podržani lokalni kalendar.'));
        }
        $zoneName = (string)($type['timezone'] ?: $calendar['timezone']);
        if (!isset($this->zones[$zoneName])) {
            throw new ConfluenceImportException(__('Vremenska zona događaja nije podržana.'));
        }
        $zone = new DateTimeZone($zoneName);
        $allDay = ($row['ALL_DAY'] ?? '') === 'true';
        $start = $this->date((string)($allDay ? $row['START'] : ($row['UTC_START'] ?: $row['START'])), $allDay, $zone);
        $end = $this->date((string)($allDay ? $row['END'] : ($row['UTC_END'] ?: $row['END'])), $allDay, $zone);
        if ($end <= $start) {
            throw new ConfluenceImportException(__('Završetak događaja nije nakon početka.'));
        }
        $uid = $this->uid($row);
        if (strlen($uid) > 190 || preg_match('/[\x00-\x1f\x7f]/', $uid) === 1) {
            throw new ConfluenceImportException(__('UID događaja nije valjan.'));
        }
        // EN: Match Calendar's schema limits before confirmation; report oversized values, never truncate.
        //
        // HR: Prije potvrde poštuje granice Calendar sheme; preduge vrijednosti prijavljuje, nikad ne reže.
        if (mb_strlen((string)$row['SUMMARY'], 'UTF-8') > 765) {
            throw new ConfluenceImportException(__('Naslov događaja može imati najviše 765 znakova.'));
        }
        if (mb_strlen((string)$row['LOCATION'], 'UTF-8') > 510) {
            throw new ConfluenceImportException(__('Lokacija događaja može imati najviše 510 znakova.'));
        }
        $suffix = $allDay ? ';VALUE=DATE' : ';TZID=' . $zoneName;
        $recurrence = (string)($row['RECURRENCE_ID_TIMESTAMP'] ?? '');
        $lines = ['BEGIN:VEVENT', 'UID:' . $this->escape($uid), 'DTSTART' . $suffix . ':' . $start,
            'DTEND' . $suffix . ':' . $end, 'SUMMARY:' . $this->escape((string)$row['SUMMARY']),
            'DESCRIPTION:' . $this->escape(trim(strip_tags((string)$row['DESCRIPTION']))),
            'LOCATION:' . $this->escape((string)$row['LOCATION']),
            'X-CONFLUENCE-EVENT-ID:' . $this->escape((string)$row['ID'])];
        if ($recurrence !== '') {
            $lines[] = 'RECURRENCE-ID' . $suffix . ':' . $this->date($recurrence, $allDay, $zone);
        } else {
            $rule = $this->rule((string)($row['RECURRENCE_RULE'] ?? ''), $start);
            if ($rule !== '') {
                $lines[] = 'RRULE:' . $rule;
            }
            foreach ([...($row['_exclusions'] ?? []), ...$exceptions] as $exclusion) {
                if ($exclusion['all_day'] !== $allDay) {
                    throw new ConfluenceImportException(__('Iznimka nema isti tip datuma kao serija.'));
                }
                $lines[] = 'EXDATE' . $suffix . ':' . $this->date($exclusion['value'], $allDay, $zone);
            }
        }
        $lines[] = 'END:VEVENT';
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * EN: AO uses epoch milliseconds; all-day fields are floating dates represented at UTC midnight.
     * Timed UTC instants are converted to the source calendar zone, including daylight-saving changes.
     *
     * HR: AO koristi milisekunde epohe; cjelodnevna polja su datumi prikazani u UTC ponoći.
     * Vremenski UTC trenuci pretvaraju se u zonu izvornog kalendara, uključujući ljetno računanje vremena.
     */
    private function date(string $value, bool $allDay, DateTimeZone $zone): string
    {
        if (preg_match('/^-?\d{10,14}$/D', $value) !== 1) {
            throw new ConfluenceImportException(__('Datum događaja nije podržan.'));
        }
        $date = new DateTimeImmutable('@' . (int)floor((int)$value / 1000));
        if ((int)$date->format('Y') < 1900 || (int)$date->format('Y') > 2200) {
            throw new ConfluenceImportException(__('Datum događaja nije podržan.'));
        }
        return $allDay ? $date->format('Ymd') : $date->setTimezone($zone)->format('Ymd\THis');
    }

    /**
     * EN: Rejects patterns the current Calendar recurrence engine cannot reproduce faithfully.
     * Confluence COUNT=-1 means unlimited and is removed, not converted to a single occurrence.
     *
     * HR: Odbija obrasce koje postojeći Calendar ne može vjerno ponoviti.
     * Confluence COUNT=-1 znači neograničeno i uklanja se, ne pretvara se u jedan događaj.
     */
    private function rule(string $rule, string $start): string
    {
        if ($rule === '') {
            return '';
        }
        $parts = [];
        foreach (explode(';', strtoupper($rule)) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2 || isset($parts[$pair[0]]) || !in_array($pair[0], ['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'WKST'], true)) {
                throw new ConfluenceImportException(__('Pravilo ponavljanja nije podržano.'));
            }
            $parts[$pair[0]] = $pair[1];
        }
        $frequency = $parts['FREQ'] ?? '';
        if (!in_array($frequency, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            throw new ConfluenceImportException(__('Pravilo ponavljanja nije podržano.'));
        }
        if (($parts['COUNT'] ?? '') === '-1') {
            unset($parts['COUNT']);
        }
        foreach (['COUNT', 'INTERVAL'] as $key) {
            if (isset($parts[$key]) && (preg_match('/^[1-9]\d{0,4}$/D', $parts[$key]) !== 1)) {
                throw new ConfluenceImportException(__('Pravilo ponavljanja nije podržano.'));
            }
        }
        if (isset($parts['UNTIL']) && preg_match('/^\d{8}(?:T\d{6}Z?)?$/D', $parts['UNTIL']) !== 1) {
            throw new ConfluenceImportException(__('Pravilo ponavljanja nije podržano.'));
        }
        if (isset($parts['BYDAY'])) {
            $day = $parts['BYDAY'];
            $weekday = strtoupper(substr((new DateTimeImmutable(substr($start, 0, 8)))->format('D'), 0, 2));
            if (
                ($frequency !== 'WEEKLY' || $day !== $weekday) && ($frequency !== 'MONTHLY' || preg_match('/^-?[1-4](MO|TU|WE|TH|FR|SA|SU)$/D', $day) !== 1)
            ) {
                throw new ConfluenceImportException(__('Pravilo ponavljanja nije podržano.'));
            }
        } elseif (
            ($frequency === 'MONTHLY' && (int)substr($start, 6, 2) > 28)
            || ($frequency === 'YEARLY' && substr($start, 4, 4) === '0229')
        ) {
            throw new ConfluenceImportException(__('Pravilo ponavljanja nije podržano.'));
        }
        unset($parts['WKST']);
        return implode(';', array_map(static fn(string $key, string $value): string => $key . '=' . $value, array_keys($parts), $parts));
    }

    /**
     * EN: Prevents ICS property injection while keeping Unicode/plain-text content.
     *
     * HR: Sprječava umetanje ICS svojstava uz očuvanje Unicode/običnog teksta.
     */
    private function escape(string $value): string
    {
        return str_replace(["\r\n", "\r", "\n", ';', ','], ['\\n', '\\n', '\\n', '\\;', '\\,'], str_replace('\\', '\\\\', str_replace("\0", '', $value)));
    }
}
