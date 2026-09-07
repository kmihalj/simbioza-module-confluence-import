<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use function is_array;
use function is_numeric;
use function is_scalar;
use function ksort;
use function strtolower;
use function trim;

/** HR: Odabire stvarne aktualne privitke iz Confluence verzijskih zapisa. EN: Selects actual current attachments from Confluence version records. */
final class ConfluenceAttachmentSelector
{
    /**
     * HR: Grupira zapise preko izvornog logičkog ID-a i zadržava najnoviju aktualnu verziju.
     * EN: Groups records by source logical ID and retains the newest current version.
     *
     * @param iterable<array<string,mixed>> $attachments
     * @return array<string,array<string,mixed>>
     */
    public static function latestCurrent(iterable $attachments): array
    {
        $latest = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $status = is_scalar($attachment['status'] ?? null)
                ? strtolower(trim((string)$attachment['status']))
                : 'current';
            if ($status !== 'current') {
                continue;
            }

            $logicalValue = $attachment['logical_source_id'] ?? $attachment['source_id'] ?? '';
            $logicalId = is_scalar($logicalValue) ? trim((string)$logicalValue) : '';
            if ($logicalId === '') {
                continue;
            }

            $version = is_numeric($attachment['version'] ?? null) ? (int)$attachment['version'] : 1;
            if (!isset($latest[$logicalId]) || $version > (int)($latest[$logicalId]['version'] ?? 1)) {
                $latest[$logicalId] = $attachment;
            }
        }

        ksort($latest);

        return $latest;
    }
}
