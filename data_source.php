<?php
/**
 * One place that answers "give me the programmes / subjects / colleges / exam session"
 * no matter which source config.php selects (excel | db | api).
 * Used by api.php (to fill the page) and index.php (to re-check values when saving).
 */
require_once __DIR__ . '/esamarth.php';
require_once __DIR__ . '/excel_source.php';
require_once __DIR__ . '/db_source.php';

function src(): string { return cfg()['source'] ?? 'excel'; }

/** Does the data source say which papers belong to which programme? (false -> the dashboard cannot compute "pending") */
function src_has_structure(): bool {
    return match (src()) { 'api' => false, 'db' => !db_csv_table_mode() || db_courses_mode(), default => !programmes_from_csv() };
}

function src_programmes(): array {
    return match (src()) { 'db' => db_programmes(), 'api' => get_programmes(), default => excel_programmes() };
}
function src_subjects(string $programmeId): array {
    return match (src()) { 'db' => db_subjects($programmeId), 'api' => get_courses(), default => excel_subjects($programmeId) };
}
function src_colleges(): array {
    return src() === 'db' ? db_colleges() : excel_colleges();
}
function src_sessions(): array {
    return src() === 'db' ? db_sessions() : excel_sessions();
}

/** Name of the current exam session, or null if none is marked current. */
function src_current_session(): ?string {
    $r = src_sessions();
    foreach ($r['items'] as $s) if ($s['current']) return $s['name'];
    return $r['items'][0]['name'] ?? null;
}

function src_find_programme(string $id): ?array {
    if ($id === '') return null;
    foreach (src_programmes()['items'] as $p) if ((string)$p['id'] === $id) return $p;
    return null;
}
function src_find_subject(string $programmeId, string $subjectId): ?array {
    if ($subjectId === '') return null;
    foreach (src_subjects($programmeId)['items'] as $s) if ((string)$s['id'] === $subjectId) return $s;
    return null;
}

/** Programmes the dashboard should expect papers for: with a courses table only the programmes that have courses. */
function src_expected_programmes(): array {
    $items = src_programmes()['items'];
    if (src() === 'db' && db_courses_mode()) {
        $codes = db_courses_programme_codes();
        $items = array_values(array_filter($items, fn($p) => isset($codes[(string)($p['code'] ?? '')])));
    }
    return $items;
}
