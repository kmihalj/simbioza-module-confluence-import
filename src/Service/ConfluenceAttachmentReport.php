<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use function is_numeric;
use function is_scalar;
use function max;
use function trim;

/**
 * EN: Separates current attachment outcomes from their history.
 *
 * HR: Razdvaja ishode aktualnih privitaka od njihove povijesti.
 */
final class ConfluenceAttachmentReport
{
    /**
     * EN: Summarizes current durable records using their explicit logical attachment identity.
     *
     * HR: Sažima aktualne trajne zapise prema izričitom logičkom identitetu privitka.
     *
     * @param iterable<array<string,mixed>> $records
     * @return array{current_total:int,current_imported:int,current_failed:int,historical_total:int,historical_imported:int,historical_failed:int,current_failures:list<array{name:string,version:int,error:string}>,historical_failures:list<array{name:string,version:int,error:string}>}
     */
    public static function summarize(iterable $records): array
    {
        $rows = [];
        $current = [];
        foreach ($records as $record) {
            $sourceId = self::text($record['source_attachment_id'] ?? '');
            $logicalId = self::text($record['logical_source_id'] ?? '');
            if ($sourceId === '' || $logicalId === '') {
                continue;
            }
            $version = is_numeric($record['source_version'] ?? null) ? max(1, (int)$record['source_version']) : 1;
            $rows[] = ['logical_id' => $logicalId, 'source_id' => $sourceId, 'version' => $version, 'record' => $record];
            if (!isset($current[$logicalId]) || $version > $current[$logicalId]['version']) {
                $current[$logicalId] = ['source_id' => $sourceId, 'version' => $version];
            }
        }

        $report = [
            'current_total' => 0,
            'current_imported' => 0,
            'current_failed' => 0,
            'historical_total' => 0,
            'historical_imported' => 0,
            'historical_failed' => 0,
            'current_failures' => [],
            'historical_failures' => [],
        ];
        foreach ($rows as $row) {
            $selected = $current[$row['logical_id']];
            $kind = $row['source_id'] === $selected['source_id'] && $row['version'] === $selected['version']
                ? 'current'
                : 'historical';
            ++$report[$kind . '_total'];
            $record = $row['record'];
            $status = self::text($record['status'] ?? '');
            if ($status === 'stored' || $status === 'registered') {
                ++$report[$kind . '_imported'];
            } elseif ($status === 'failed') {
                ++$report[$kind . '_failed'];
                $report[$kind . '_failures'][] = [
                    'name' => self::text($record['original_name'] ?? ''),
                    'version' => $row['version'],
                    'error' => self::text($record['error_message'] ?? ''),
                ];
            }
        }

        return $report;
    }

    /**
     * EN: Rejects implicit conversions of non-scalar metadata.
     *
     * HR: Odbija implicitne pretvorbe nescalarnih metapodataka.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
