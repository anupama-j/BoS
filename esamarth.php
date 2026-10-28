<?php
/** e-Samarth Student Data API client (Basic auth, paged) with MySQL cache. */
require_once __DIR__ . '/db.php';

/**
 * One GET request. Returns ['status'=>int, 'headers'=>array, 'body'=>string].
 * Throws RuntimeException with a human-readable reason on connection problems.
 */
function esamarth_http(string $url): array {
    $a = cfg()['api'];
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is disabled. In XAMPP php.ini enable "extension=curl" and restart Apache.');
    }
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,                       // Authorization: Basic base64(user:pass)
        CURLOPT_USERPWD        => $a['username'] . ':' . $a['password'],
        CURLOPT_SSL_VERIFYPEER => $a['verify_ssl'],
        CURLOPT_SSL_VERIFYHOST => $a['verify_ssl'] ? 2 : 0,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            $p = explode(':', $line, 2);
            if (count($p) === 2) $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($line);
        },
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        $hint = '';
        if (stripos($err, 'SSL') !== false) $hint = ' (SSL problem: set verify_ssl to false in config.php for local testing, or add a CA bundle to php.ini curl.cainfo)';
        throw new RuntimeException("Could not connect to $url: $err$hint");
    }
    return ['status' => $status, 'headers' => $respHeaders, 'body' => $body];
}

/** Explain common failures in plain words. */
function esamarth_fail(array $r, string $url): RuntimeException {
    $s = $r['status'];
    $why = match (true) {
        $s === 401 => 'Wrong API username/password (Basic auth rejected).',
        $s === 403 => 'Credentials accepted but access is forbidden - ask Samarth to enable this API / whitelist your server IP.',
        $s === 404 => 'Endpoint not found - check base_url (must end in /index.php/apiv2) and the endpoint path.',
        $s >= 500  => 'Samarth server error.',
        default    => 'Unexpected response.',
    };
    return new RuntimeException("HTTP $s from $url - $why");
}

/** Fetch every page of a list endpoint; returns the merged list of rows. */
function esamarth_fetch_all(string $path, ?array &$diag = null): array {
    $a    = cfg()['api'];
    $base = rtrim($a['base_url'], '/') . $path;
    $rows = [];
    $pages = 1;
    for ($page = 1; $page <= $pages && $page <= (int)$a['max_pages']; $page++) {
        $url = $base . (strpos($base, '?') === false ? '?' : '&') . 'page=' . $page;
        $r = esamarth_http($url);
        if ($r['status'] < 200 || $r['status'] >= 300) throw esamarth_fail($r, $url);
        $json = json_decode($r['body'], true);
        if (!is_array($json)) {
            throw new RuntimeException('API did not return JSON (first 120 chars): ' . substr(strip_tags($r['body']), 0, 120));
        }
        // Samarth returns a top-level array; tolerate {"data":[...]} too
        if (isset($json['data']) && is_array($json['data'])) $json = $json['data'];
        foreach ($json as $row) if (is_array($row)) $rows[] = $row;

        if ($page === 1) {
            $pages = max(1, (int)($r['headers']['x-pagination-page-count'] ?? 1));
            $diag = [
                'url'         => $url,
                'status'      => $r['status'],
                'total_count' => $r['headers']['x-pagination-total-count'] ?? null,
                'page_count'  => $pages,
                'per_page'    => $r['headers']['x-pagination-per-page'] ?? null,
                'first_row'   => $json[0] ?? null,
            ];
        }
    }
    return $rows;
}

function map_row(array $row, array $map): array {
    $out = [];
    foreach ($map as $to => $from) $out[$to] = isset($row[$from]) ? (string)$row[$from] : '';
    return $out;
}

/** @return array{items:array,source:string,error:?string} source = api|cache|demo */
function cached_fetch(string $key, string $path, array $map, array $demo): array {
    $pdo = db();
    $ttl = cfg()['api']['cache_ttl'];

    $st = $pdo->prepare('SELECT payload, fetched_at FROM api_cache WHERE cache_key = ?');
    $st->execute([$key]);
    $row = $st->fetch();

    if ($row && (time() - (int)$row['fetched_at']) < $ttl && empty($_GET['refresh'])) {
        return ['items' => json_decode($row['payload'], true), 'source' => 'cache', 'error' => null];
    }
    try {
        $items = array_map(fn($r) => map_row($r, $map), esamarth_fetch_all($path));
        $pdo->prepare('REPLACE INTO api_cache (cache_key, payload, fetched_at) VALUES (?,?,?)')
            ->execute([$key, json_encode($items, JSON_UNESCAPED_UNICODE), time()]);
        return ['items' => $items, 'source' => 'api', 'error' => null];
    } catch (Throwable $e) {
        if ($row) {
            return ['items' => json_decode($row['payload'], true), 'source' => 'cache',
                    'error' => $e->getMessage() . ' (showing last saved copy)'];
        }
        return ['items' => $demo, 'source' => 'demo', 'error' => $e->getMessage()];
    }
}

function get_programmes(): array {
    $c = cfg();
    return cached_fetch('programmes', $c['api']['ep_programmes'], $c['api']['map_programme'], $c['demo_programmes']);
}

/** The Student Data API has no programme -> course link, so this is the full course catalogue. */
function get_courses(): array {
    $c = cfg();
    return cached_fetch('courses', $c['api']['ep_courses'], $c['api']['map_course'], $c['demo_subjects']);
}
