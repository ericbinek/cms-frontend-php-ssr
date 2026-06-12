<?php
declare(strict_types=1);

const CMS_PLURALS = [
    'BlogPosting' => 'blog-postings',
    'Person' => 'persons',
    'WebPage' => 'web-pages',
    'ImageObject' => 'image-objects',
    'CategoryCode' => 'category-codes',
    'CategoryCodeSet' => 'category-code-sets',
    'DefinedTerm' => 'defined-terms',
    'DefinedTermSet' => 'defined-term-sets',
    'Comment' => 'comments',
    'WebSite' => 'web-sites',
];

const CMS_SAMPLES = [
    'BlogPosting' => [
        'headline' => 'sample',
        'articleBody' => 'sample',
        'author' => ['__ref' => 'Person'],
    ],
    'Person' => [
        'name' => 'sample',
    ],
    'WebPage' => [
        'headline' => 'sample',
    ],
    'ImageObject' => [
        'contentUrl' => 'https://example.com/x',
    ],
    'CategoryCode' => [
        'name' => 'sample',
        'codeValue' => 'sample',
        'inCodeSet' => ['__ref' => 'CategoryCodeSet'],
    ],
    'CategoryCodeSet' => [
        'name' => 'sample',
    ],
    'DefinedTerm' => [
        'name' => 'sample',
        'termCode' => 'sample',
        'inDefinedTermSet' => ['__ref' => 'DefinedTermSet'],
    ],
    'DefinedTermSet' => [
        'name' => 'sample',
    ],
    'Comment' => [
        'text' => 'sample',
        'author' => ['__ref' => 'Person'],
        'about' => ['__ref' => 'BlogPosting'],
    ],
    'WebSite' => [
        'name' => 'sample',
        'url' => 'https://example.com/x',
    ],
];

const CMS_ENTITIES = ['BlogPosting', 'Person', 'WebPage', 'ImageObject', 'CategoryCode', 'CategoryCodeSet', 'DefinedTerm', 'DefinedTermSet', 'Comment', 'WebSite'];

$CMS_SEEDED = [];

function cms_start_php_server(string $script, int $port, array $env, ?string $docRoot = null): array
{
    $repoRoot = realpath(__DIR__ . '/..');
    $docroot = $docRoot ?? ($repoRoot . '/public');
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ];
    $finalEnv = array_merge($_ENV, getenv(), $env);
    $cmd = sprintf('exec php -S 127.0.0.1:%d -t %s %s',
        $port, escapeshellarg($docroot), escapeshellarg($script));
    $proc = proc_open($cmd, $descriptors, $pipes, $repoRoot, $finalEnv);
    if (!is_resource($proc)) throw new RuntimeException("Failed to start: $cmd");

    $baseUrl = "http://127.0.0.1:$port";
    for ($i = 0; $i < 100; $i++) {
        $ch = curl_init($baseUrl . '/health');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status === 200) {
            return ['proc' => $proc, 'pipes' => $pipes, 'baseUrl' => $baseUrl];
        }
        usleep(50_000);
    }
    proc_terminate($proc);
    proc_close($proc);
    throw new RuntimeException("Server at $baseUrl did not become healthy");
}

function cms_stop_php_server(array $server): void
{
    if (is_resource($server['proc'])) {
        proc_terminate($server['proc']);
        proc_close($server['proc']);
    }
    foreach ($server['pipes'] ?? [] as $pipe) {
        if (is_resource($pipe)) fclose($pipe);
    }
}

function cms_start_stack(): array
{
    $repoRoot = realpath(__DIR__ . '/..');
    $mockDocroot = sys_get_temp_dir() . '/cms-mock-docroot-' . bin2hex(random_bytes(4));
    @mkdir($mockDocroot, 0755, true);
    $mockDataDir = sys_get_temp_dir() . '/cms-mock-data-' . bin2hex(random_bytes(4));
    @mkdir($mockDataDir, 0755, true);

    $mockPort = 15000 + random_int(0, 1000);
    $mock = cms_start_php_server(
        $repoRoot . '/test/_mock_api.php',
        $mockPort,
        ['MOCK_DATA_DIR' => $mockDataDir],
        $mockDocroot,
    );

    $frontPort = 16000 + random_int(0, 1000);
    $front = cms_start_php_server(
        $repoRoot . '/src/server.php',
        $frontPort,
        ['API_BASE_URL' => $mock['baseUrl']],
    );

    return [
        'mock' => $mock,
        'front' => $front,
        'mockDocroot' => $mockDocroot,
        'mockDataDir' => $mockDataDir,
        'apiBaseUrl' => $mock['baseUrl'],
        'frontendBaseUrl' => $front['baseUrl'],
    ];
}

function cms_stop_stack(array $stack): void
{
    cms_stop_php_server($stack['front']);
    cms_stop_php_server($stack['mock']);
    if (isset($stack['mockDataDir']) && is_dir($stack['mockDataDir'])) {
        foreach (glob($stack['mockDataDir'] . '/*') as $f) @unlink($f);
        @rmdir($stack['mockDataDir']);
    }
    if (isset($stack['mockDocroot']) && is_dir($stack['mockDocroot'])) {
        @rmdir($stack['mockDocroot']);
    }
}

function cms_reset_seed_cache(): void
{
    global $CMS_SEEDED;
    $CMS_SEEDED = [];
}

function cms_http(string $method, string $url, ?string $body = null, array $headers = [], bool $followRedirects = false): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if (count($headers)) {
        $hdr = [];
        foreach ($headers as $k => $v) $hdr[] = "$k: $v";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    }
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        throw new RuntimeException("HTTP request failed: $err");
    }
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $rawHeaders = substr($response, 0, $hsize);
    $rawBody = substr($response, $hsize);
    $hdrs = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        $parts = explode(': ', $line, 2);
        if (count($parts) === 2) $hdrs[strtolower($parts[0])] = $parts[1];
    }
    return ['status' => $status, 'headers' => $hdrs, 'body' => $rawBody];
}

function cms_resolve_refs(array $stack, array $sample): array
{
    $resolved = [];
    foreach ($sample as $k => $v) {
        if (is_array($v) && array_is_list($v)) {
            $out = [];
            foreach ($v as $vv) {
                if (is_array($vv) && isset($vv['__ref'])) {
                    $out[] = cms_ensure_entity($stack, $vv['__ref']);
                } else {
                    $out[] = $vv;
                }
            }
            $resolved[$k] = $out;
        } elseif (is_array($v) && isset($v['__ref'])) {
            $resolved[$k] = cms_ensure_entity($stack, $v['__ref']);
        } else {
            $resolved[$k] = $v;
        }
    }
    return $resolved;
}

function cms_ensure_entity(array $stack, string $entity): string
{
    global $CMS_SEEDED;
    if (isset($CMS_SEEDED[$entity])) return $CMS_SEEDED[$entity];
    $sample = cms_resolve_refs($stack, CMS_SAMPLES[$entity]);
    $r = cms_http(
        'POST',
        $stack['apiBaseUrl'] . '/' . CMS_PLURALS[$entity],
        json_encode($sample, JSON_UNESCAPED_SLASHES),
        ['Content-Type' => 'application/json'],
    );
    if ($r['status'] !== 201) {
        throw new RuntimeException("cms_ensure_entity($entity) failed: {$r['status']} " . $r['body']);
    }
    $item = json_decode($r['body'], true);
    return $CMS_SEEDED[$entity] = $item['id'];
}

// Seed one fresh entity with chosen field overrides, bypassing the seed cache.
// Used to plant a hostile field value (e.g. a "javascript:" URL) and check how
// the frontend renders it back.
function cms_seed_with(array $stack, string $entity, array $overrides): string
{
    $sample = array_merge(cms_resolve_refs($stack, CMS_SAMPLES[$entity]), $overrides);
    $r = cms_http(
        'POST',
        $stack['apiBaseUrl'] . '/' . CMS_PLURALS[$entity],
        json_encode($sample, JSON_UNESCAPED_SLASHES),
        ['Content-Type' => 'application/json'],
    );
    if ($r['status'] !== 201) {
        throw new RuntimeException("cms_seed_with($entity) failed: {$r['status']} " . $r['body']);
    }
    $item = json_decode($r['body'], true);
    return $item['id'];
}

function cms_encode_one(mixed $v): string
{
    if ($v === null) return '';
    if (is_array($v)) {
        if (isset($v['@type']) && $v['@type'] === 'Language') return (string) ($v['alternateName'] ?? '');
        return json_encode($v);
    }
    if (is_bool($v)) return $v ? 'true' : 'false';
    return (string) $v;
}

function cms_form_body_for(array $stack, string $entity): string
{
    $sample = cms_resolve_refs($stack, CMS_SAMPLES[$entity]);
    $pairs = [];
    foreach ($sample as $key => $value) {
        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $vv) {
                $pairs[] = rawurlencode($key) . '=' . rawurlencode(cms_encode_one($vv));
            }
        } else {
            $pairs[] = rawurlencode($key) . '=' . rawurlencode(cms_encode_one($value));
        }
    }
    return implode('&', $pairs);
}

function cms_frontend_get(array $stack, string $path): array
{
    return cms_http('GET', $stack['frontendBaseUrl'] . $path);
}

function cms_frontend_post_form(array $stack, string $path, string $body): array
{
    return cms_http('POST', $stack['frontendBaseUrl'] . $path, $body, ['Content-Type' => 'application/x-www-form-urlencoded']);
}

function cms_assert(bool $cond, string $msg = 'assertion failed'): void { if (!$cond) throw new RuntimeException($msg); }
function cms_assert_equal(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        throw new RuntimeException(($msg !== '' ? "$msg: " : '') . "expected $e, got $a");
    }
}
function cms_assert_match(string $regex, string $haystack, string $msg = ''): void
{
    if (preg_match($regex, $haystack) !== 1) {
        throw new RuntimeException(($msg !== '' ? "$msg: " : '') . "expected $regex to match");
    }
}
