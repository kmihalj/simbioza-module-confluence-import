<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use XMLReader;
use ZipArchive;

/**
 * EN: Streams only allowlisted Team Calendars rows; unrelated plugin secrets never leave the reader.
 * No extraction, external entities, subscription URLs or reminder payloads are used.
 *
 * HR: Tokom čita samo dopuštene retke Team Calendara; tajne drugih dodataka ne napuštaju čitač.
 * Ne raspakirava arhivu niti koristi vanjske entitete, URL-ove pretplata ili podsjetnike.
 */
final readonly class ConfluenceTeamCalendarReader
{
    public const ENTRY = 'plugin-data/com.atlassian.activeobjects.confluence.spi/activeObjectsBackupRestoreProvider.pdata';
    private const TABLES = [
        'SUBCALS' => ['ID', 'PARENT_ID', 'NAME', 'DESCRIPTION', 'CREATOR', 'COLOUR', 'SPACE_KEY', 'STORE_KEY', 'SUBSCRIPTION_ID', 'TIME_ZONE_ID', 'USING_CUSTOM_EVENT_TYPE_ID'],
        'EVENTS' => ['ID', 'SUB_CALENDAR_ID', 'SUMMARY', 'DESCRIPTION', 'LOCATION', 'ORGANISER', 'START', 'END', 'UTC_START', 'UTC_END', 'ALL_DAY', 'RECURRENCE_RULE', 'RECURRENCE_ID_TIMESTAMP', 'VEVENT_UID'],
        'EVENTS_EXCL' => ['ID', 'EVENT_ID', 'EXCLUSION', 'ALL_DAY'],
        'EVENTS_INVITEES' => ['ID', 'EVENT_ID', 'INVITEE_ID'],
        'SUBCALS_PRIV_GRP' => ['ID', 'SUB_CALENDAR_ID', 'TYPE', 'GROUP_NAME'],
        'SUBCALS_PRIV_USR' => ['ID', 'SUB_CALENDAR_ID', 'TYPE', 'USER_KEY'],
        'SUBCALS_IN_SPACE' => ['ID', 'SPACE_KEY', 'SUB_CALENDAR_ID'],
        'CUSTOM_EV_TYPES' => ['ID', 'BELONG_SUB_CALENDAR_ID', 'TITLE', 'ICON'],
        'DISABLE_EV_TYPES' => ['ID', 'EVENT_KEY', 'SUB_CALENDAR_ID'],
    ];

    /**
     * EN: Reuses the importer's validated archive boundary.
     *
     * HR: Koristi postojeću provjeru arhive importera.
     */
    public function __construct(private ConfluenceArchive $archive)
    {
    }

    /**
     * EN: Yields positional AO values using their declared schema, never SQL or serialized objects.
     * The validated ZIP bounds the stream; selected record/value counts are limited separately.
     *
     * HR: Vraća pozicijske AO vrijednosti prema deklariranoj shemi, nikada SQL ili serijalizirane objekte.
     * Provjereni ZIP ograničava tok; broj odabranih zapisa i veličina vrijednosti dodatno su ograničeni.
     *
     * @return \Generator<int,array{table:string,row:array<string,string>}>
     */
    public function rows(string $path): \Generator
    {
        $this->archive->inspect($path);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ConfluenceImportException(__('Confluence ZIP arhiva nije pronađena.'));
        }
        $exists = $zip->locateName(self::ENTRY) !== false;
        $zip->close();
        if (!$exists) {
            return;
        }
        // EN: zip:// also supports PHP 8.2; the entry name is fixed, not request-controlled.
        //
        // HR: zip:// radi i na PHP-u 8.2; naziv zapisa je zadan, ne dolazi iz zahtjeva.
        if (str_contains($path, '#')) {
            throw new ConfluenceImportException(__('Putanja Confluence arhive nije podržana.'));
        }
        $xml = new XMLReader();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if (!$xml->open('zip://' . $path . '#' . self::ENTRY, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
                throw new ConfluenceImportException(__('Team Calendars XML nije moguće otvoriti.'));
            }
            $xml->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            $xml->setParserProperty(XMLReader::LOADDTD, false);
            $schemas = [];
            $table = '';
            $records = 0;
            $hasNode = $xml->read();
            while ($hasNode) {
                if (in_array($xml->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY_REF], true)) {
                    throw new ConfluenceImportException(__('Team Calendars XML sadrži nedopušten entitet.'));
                }
                if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'table') {
                    $name = (string)$xml->getAttribute('name');
                    if (str_starts_with($name, 'AO_950DC3_TC_') && isset(self::TABLES[substr($name, 13)])) {
                        $columns = [];
                        $depth = $xml->depth;
                        while ($xml->read() && ($xml->nodeType !== XMLReader::END_ELEMENT || $xml->depth !== $depth)) {
                            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'column') {
                                $columns[] = (string)$xml->getAttribute('name');
                                if (count($columns) > 100 || count(array_unique($columns)) !== count($columns)) {
                                    throw new ConfluenceImportException(__('Team Calendars shema nije valjana.'));
                                }
                            }
                        }
                        if (isset($schemas[$name])) {
                            throw new ConfluenceImportException(__('Team Calendars shema nije valjana.'));
                        }
                        $schemas[$name] = $columns;
                    } else {
                        $hasNode = $xml->next();
                        continue;
                    }
                } elseif ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'data') {
                    $table = (string)($xml->getAttribute('tableName') ?: $xml->getAttribute('table'));
                    if (!isset($schemas[$table])) {
                        $hasNode = $xml->next();
                        $table = '';
                        continue;
                    }
                } elseif ($xml->nodeType === XMLReader::END_ELEMENT && $xml->localName === 'data') {
                    $table = '';
                } elseif ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'row' && $table !== '') {
                    if (++$records > 2000000) {
                        throw new ConfluenceImportException(__('Team Calendars sadrži previše zapisa.'));
                    }
                    $row = $this->row($xml, $schemas[$table], self::TABLES[substr($table, 13)]);
                    yield ['table' => substr($table, 13), 'row' => $row];
                }
                $hasNode = $xml->read();
            }
            if (libxml_get_errors() !== []) {
                throw new ConfluenceImportException(__('Team Calendars XML nije potpun ili ispravan.'));
            }
        } finally {
            $xml->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * EN: Verifies positional values against a selected schema, including explicit null values.
     *
     * HR: Provjerava pozicijske vrijednosti prema odabranoj shemi, uključujući izričite null vrijednosti.
     *
     * @param list<string> $columns
     * @param list<string> $allowed
     * @return array<string,string>
     */
    private function row(XMLReader $xml, array $columns, array $allowed): array
    {
        $row = [];
        $position = 0;
        $depth = $xml->depth;
        while ($xml->read() && ($xml->nodeType !== XMLReader::END_ELEMENT || $xml->depth !== $depth)) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->depth === $depth + 1) {
                $key = $columns[$position++] ?? '';
                if ($key === '') {
                    throw new ConfluenceImportException(__('Team Calendars redak ne odgovara shemi.'));
                }
                $value = $xml->readString();
                if (strlen($value) > 262144) {
                    throw new ConfluenceImportException(__('Team Calendars vrijednost je prevelika.'));
                }
                if (in_array($key, $allowed, true)) {
                    $row[$key] = $value;
                }
            } elseif ($xml->nodeType === XMLReader::ENTITY_REF) {
                throw new ConfluenceImportException(__('Team Calendars XML sadrži nedopušten entitet.'));
            }
        }
        if ($position !== count($columns)) {
            throw new ConfluenceImportException(__('Team Calendars redak ne odgovara shemi.'));
        }
        return $row;
    }
}
