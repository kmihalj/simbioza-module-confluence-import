<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

/**
 * EN: Builds a read-only overview from linked, owned reports; it never scans archives or mutates content.
 * Only counters and stable report UUIDs leave this boundary, not private paths, tokens or imported script bodies.
 *
 * HR: Gradi pregled samo iz povezanih vlastitih izvještaja; ne čita arhivu niti mijenja sadržaj.
 * Iz ove granice izlaze samo brojanja i stabilni UUID-jevi, ne privatne putanje, tokeni ili tijela uvezenih skripti.
 */
final readonly class ConfluenceSiteReportService
{
    /**
     * EN: Receives the importer persistence boundary.
     *
     * HR: Prima granicu spremanja importera.
     */
    public function __construct(private ConfluenceImportRepository $repository)
    {
    }

    /**
     * EN: Includes unfinished steps without describing them as completed; portable UUID links survive backup.
     * Report details are reduced one batch at a time, so page bodies/issues are not accumulated across spaces.
     *
     * HR: Uključuje nedovršene korake bez proglašavanja dovršenima; prenosive UUID veze ostaju u backupu.
     * Detalji se svode paket po paket pa se tijela/problemi stranica ne gomilaju kroz područja.
     *
     * @param array<string,mixed> $parent
     * @return array<string,mixed>
     */
    public function overview(array $parent): array
    {
        $summary = $parent['summary'] ?? [];
        $options = $parent['options'] ?? [];
        $ids = [...($summary['site_reports']['access'] ?? []), ...($summary['site_reports']['calendars'] ?? []),
            $options['site_access']['last_report_uuid'] ?? '', $options['site_calendars']['report_uuid'] ?? '',
            ...array_values($options['site_content']['children'] ?? []), ...array_column($summary['children'] ?? [], 'uuid')];
        $ids = array_values(array_unique(array_filter($ids, static fn(mixed $id): bool => is_string($id) && $id !== '')));
        $reports = [];
        $totals = [];
        $hash = (string)($summary['source_sha256'] ?? $parent['sha256'] ?? '');
        foreach ($this->repository->reportJobs($ids, (int)$parent['actor_user_id']) as $job) {
            if ($hash === '' || !hash_equals($hash, (string)$job['sha256']) || !in_array($job['operation'], ['site_access', 'site_space', 'site_calendars'], true)) {
                continue;
            }
            $data = $job['summary'];
            $counts = [];
            foreach (
                ['users_created', 'users_mapped', 'groups_created', 'groups_mapped', 'memberships_unresolved',
                'pages_imported', 'history_versions_imported', 'attachments_imported', 'attachments_failed', 'comments_imported'] as $key
            ) {
                if (isset($data[$key])) {
                    $counts[$key] = max(0, (int)$data[$key]);
                }
            }
            $counts['warnings'] = count($data['warnings'] ?? []);
            if ($job['operation'] === 'site_space') {
                $counts = [...$counts, ...$job['link_counts']];
            }
            foreach ($data['review_pages'] ?? [] as $page) {
                foreach ($page['issues'] ?? [] as $issue) {
                    if (($issue['resolved'] ?? false) !== true) {
                        $key = ($issue['type'] ?? '') === 'external_script' ? 'scripts_pending' : 'review_pending';
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                }
            }
            foreach ($data['results'] ?? [] as $result) {
                $counts['calendars'] = ($counts['calendars'] ?? 0) + 1;
                foreach (['created', 'existing', 'skipped'] as $key) {
                    $counts['events_' . $key] = ($counts['events_' . $key] ?? 0) + (int)($result[$key] ?? 0);
                }
                $counts['warnings'] += count($result['access_warnings'] ?? []);
            }
            $reports[] = ['uuid' => $job['uuid'], 'operation' => $job['operation'], 'status' => $job['status'],
                'stage' => $job['stage'], 'name' => $job['source_space_key'] ?? '', 'partial' => ($data['partial'] ?? false) === true,
                'counts' => $counts];
            foreach ($counts as $key => $value) {
                $totals[$key] = ($totals[$key] ?? 0) + $value;
            }
        }
        $states = [$parent['status'], ...array_column($reports, 'status')];
        $status = in_array('failed', $states, true) ? 'failed' : (in_array('running', $states, true) ? 'running' : $parent['status']);
        $phase = $options['site_workflow']['phase'] ?? $summary['site_workflow_report']['phase'] ?? null;
        if ($phase !== null && !in_array($phase, ['draft', 'review', 'completed'], true)) {
            $status = $phase === 'failed' ? 'failed' : 'running';
        }
        return ['status' => $status, 'reports' => $reports, 'totals' => $totals,
            'unavailable_reports' => count($ids) - count($reports), 'source_retained' => ($parent['archive_path'] ?? '') !== '',
            'source_released' => ($summary['source_released'] ?? false) === true,
            'page_calendars' => $summary['site_calendar_pages'] ?? [],
            'source_url' => $summary['source_url'] ?? $options['site_content']['source_url'] ?? $options['site_access']['source_url'] ?? ''];
    }
}
