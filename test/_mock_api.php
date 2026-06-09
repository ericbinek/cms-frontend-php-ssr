<?php
// Mock API for frontend conformance tests. Same wire contract as the real
// cms-api-php target ({items,total}, {status,error,message,details,path})
// but with required-field validation only. Storage is JSON files under
// $MOCK_DATA_DIR — persistent across requests because cli-server re-runs
// the script per request and in-process state is lost between requests.

declare(strict_types=1);

const SCHEMAS = [
    'BlogPosting' => ['plural' => 'blog-postings', 'required' => ['headline', 'articleBody', 'author']],
    'Person' => ['plural' => 'persons', 'required' => ['name']],
    'WebPage' => ['plural' => 'web-pages', 'required' => ['headline']],
    'ImageObject' => ['plural' => 'image-objects', 'required' => ['contentUrl']],
    'CategoryCode' => ['plural' => 'category-codes', 'required' => ['name', 'codeValue', 'inCodeSet']],
    'CategoryCodeSet' => ['plural' => 'category-code-sets', 'required' => ['name']],
    'DefinedTerm' => ['plural' => 'defined-terms', 'required' => ['name', 'termCode', 'inDefinedTermSet']],
    'DefinedTermSet' => ['plural' => 'defined-term-sets', 'required' => ['name']],
    'Comment' => ['plural' => 'comments', 'required' => ['text', 'author', 'about']],
    'WebSite' => ['plural' => 'web-sites', 'required' => ['name', 'url']],
];

function mock_data_dir(): string
{
    $dir = getenv('MOCK_DATA_DIR') ?: (sys_get_temp_dir() . '/cms-mock-' . posix_getpid());
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function mock_entity_by_plural(string $plural): ?string
{
    foreach (SCHEMAS as $name => $s) {
        if ($s['plural'] === $plural) return $name;
    }
    return null;
}

function mock_read(string $entity): array
{
    $path = mock_data_dir() . '/' . SCHEMAS[$entity]['plural'] . '.json';
    if (!file_exists($path)) return [];
    $content = @file_get_contents($path);
    if ($content === false || $content === '') return [];
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

function mock_write(string $entity, array $items): void
{
    $path = mock_data_dir() . '/' . SCHEMAS[$entity]['plural'] . '.json';
    file_put_contents($path, json_encode(array_values($items), JSON_UNESCAPED_SLASHES));
}

function mock_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
}

function mock_json(int $status, mixed $data): void
{
    header('Access-Control-Allow-Origin: *');
    if ($status === 204) { http_response_code(204); return; }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if ($data !== null) echo json_encode($data, JSON_UNESCAPED_SLASHES);
}

function mock_error(int $status, string $code, string $message, array $details, string $path): array
{
    return ['status' => $status, 'error' => $code, 'message' => $message, 'details' => $details, 'path' => $path];
}

function mock_validate_required(string $entity, array $data, bool $partial): array
{
    if ($partial) return [];
    $missing = [];
    foreach (SCHEMAS[$entity]['required'] as $f) {
        $v = $data[$f] ?? null;
        if ($v === null || $v === '' || (is_array($v) && count($v) === 0)) {
            $missing[] = "Field \"$f\" is required.";
        }
    }
    return $missing;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestPath = "$method $path";

if ($method === 'OPTIONS') {
    http_response_code(204);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    return;
}
if ($method === 'GET' && $path === '/health') {
    mock_json(200, ['status' => 'ok']);
    return;
}

$seg = array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''));
if (count($seg) < 1 || count($seg) > 2) {
    mock_json(404, mock_error(404, 'ROUTE_NOT_FOUND', 'No route matches this request.', [], $requestPath));
    return;
}
$entity = mock_entity_by_plural($seg[0]);
if ($entity === null) {
    mock_json(404, mock_error(404, 'ROUTE_NOT_FOUND', 'No route matches this request.', [], $requestPath));
    return;
}

try {
    if (count($seg) === 1) {
        if ($method === 'GET') {
            $items = mock_read($entity);
            $sort = $_GET['sort'] ?? 'dateCreated';
            $dir = ($_GET['order'] ?? 'desc') === 'asc' ? 1 : -1;
            usort($items, static function ($a, $b) use ($sort, $dir) {
                $va = $a[$sort] ?? ''; $vb = $b[$sort] ?? '';
                if ($va === $vb) return 0;
                return ($va < $vb ? -1 : 1) * $dir;
            });
            $total = count($items);
            $limit = min((int) ($_GET['limit'] ?? 20), 100);
            $offset = (int) ($_GET['offset'] ?? 0);
            mock_json(200, ['items' => array_slice($items, $offset, $limit), 'total' => $total]);
            return;
        }
        if ($method === 'POST') {
            $raw = file_get_contents('php://input') ?: '';
            $data = $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($data)) {
                mock_json(400, mock_error(400, 'INVALID_JSON', 'Request body is not valid JSON.', [], $requestPath));
                return;
            }
            $errors = mock_validate_required($entity, $data, false);
            if (count($errors)) {
                mock_json(400, mock_error(400, 'VALIDATION_ERROR', 'Invalid request data.', $errors, $requestPath));
                return;
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $item = array_merge(['@context' => 'https://schema.org', '@type' => $entity], $data, [
                'id' => mock_uuid(),
                'dateCreated' => $now,
                'dateModified' => $now,
            ]);
            $items = mock_read($entity);
            $items[] = $item;
            mock_write($entity, $items);
            mock_json(201, $item);
            return;
        }
        mock_json(405, mock_error(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.', [], $requestPath));
        return;
    }

    $id = strtolower($seg[1]);
    $items = mock_read($entity);
    $idx = null;
    foreach ($items as $i => $item) {
        if (($item['id'] ?? null) === $id) { $idx = $i; break; }
    }
    $current = $idx !== null ? $items[$idx] : null;

    if ($method === 'GET') {
        if ($current === null) { mock_json(404, mock_error(404, 'NOT_FOUND', "$entity not found.", [], $requestPath)); return; }
        mock_json(200, $current);
        return;
    }
    if ($method === 'PUT') {
        if ($current === null) { mock_json(404, mock_error(404, 'NOT_FOUND', "$entity not found.", [], $requestPath)); return; }
        $raw = file_get_contents('php://input') ?: '';
        $data = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) { mock_json(400, mock_error(400, 'INVALID_JSON', 'Request body is not valid JSON.', [], $requestPath)); return; }
        $errors = mock_validate_required($entity, $data, true);
        if (count($errors)) { mock_json(400, mock_error(400, 'VALIDATION_ERROR', 'Invalid request data.', $errors, $requestPath)); return; }
        $updated = array_merge($current, $data, [
            'id' => $current['id'],
            'dateCreated' => $current['dateCreated'],
            'dateModified' => gmdate('Y-m-d\TH:i:s\Z'),
            '@context' => $current['@context'] ?? 'https://schema.org',
            '@type' => $current['@type'] ?? $entity,
        ]);
        $items[$idx] = $updated;
        mock_write($entity, $items);
        mock_json(200, $updated);
        return;
    }
    if ($method === 'DELETE') {
        if ($current === null) { mock_json(404, mock_error(404, 'NOT_FOUND', "$entity not found.", [], $requestPath)); return; }
        array_splice($items, $idx, 1);
        mock_write($entity, $items);
        mock_json(204, null);
        return;
    }
    mock_json(405, mock_error(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.', [], $requestPath));
} catch (\Throwable $e) {
    mock_json(500, mock_error(500, 'INTERNAL_ERROR', 'Internal server error: ' . $e->getMessage(), [], $requestPath));
}
