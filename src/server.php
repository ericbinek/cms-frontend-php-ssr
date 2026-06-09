<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/autoload.php';

use Cms\Views\Layout;
use Cms\Views\BlogPosting\ListView as BlogPostingList;
use Cms\Views\BlogPosting\DetailView as BlogPostingDetail;
use Cms\Views\BlogPosting\CreateView as BlogPostingCreate;
use Cms\Views\BlogPosting\EditView as BlogPostingEdit;
use Cms\Views\BlogPosting\DeleteView as BlogPostingDelete;
use Cms\Views\Person\ListView as PersonList;
use Cms\Views\Person\DetailView as PersonDetail;
use Cms\Views\Person\CreateView as PersonCreate;
use Cms\Views\Person\EditView as PersonEdit;
use Cms\Views\Person\DeleteView as PersonDelete;
use Cms\Views\WebPage\ListView as WebPageList;
use Cms\Views\WebPage\DetailView as WebPageDetail;
use Cms\Views\WebPage\CreateView as WebPageCreate;
use Cms\Views\WebPage\EditView as WebPageEdit;
use Cms\Views\WebPage\DeleteView as WebPageDelete;
use Cms\Views\ImageObject\ListView as ImageObjectList;
use Cms\Views\ImageObject\DetailView as ImageObjectDetail;
use Cms\Views\ImageObject\CreateView as ImageObjectCreate;
use Cms\Views\ImageObject\EditView as ImageObjectEdit;
use Cms\Views\ImageObject\DeleteView as ImageObjectDelete;
use Cms\Views\CategoryCode\ListView as CategoryCodeList;
use Cms\Views\CategoryCode\DetailView as CategoryCodeDetail;
use Cms\Views\CategoryCode\CreateView as CategoryCodeCreate;
use Cms\Views\CategoryCode\EditView as CategoryCodeEdit;
use Cms\Views\CategoryCode\DeleteView as CategoryCodeDelete;
use Cms\Views\CategoryCodeSet\ListView as CategoryCodeSetList;
use Cms\Views\CategoryCodeSet\DetailView as CategoryCodeSetDetail;
use Cms\Views\CategoryCodeSet\CreateView as CategoryCodeSetCreate;
use Cms\Views\CategoryCodeSet\EditView as CategoryCodeSetEdit;
use Cms\Views\CategoryCodeSet\DeleteView as CategoryCodeSetDelete;
use Cms\Views\DefinedTerm\ListView as DefinedTermList;
use Cms\Views\DefinedTerm\DetailView as DefinedTermDetail;
use Cms\Views\DefinedTerm\CreateView as DefinedTermCreate;
use Cms\Views\DefinedTerm\EditView as DefinedTermEdit;
use Cms\Views\DefinedTerm\DeleteView as DefinedTermDelete;
use Cms\Views\DefinedTermSet\ListView as DefinedTermSetList;
use Cms\Views\DefinedTermSet\DetailView as DefinedTermSetDetail;
use Cms\Views\DefinedTermSet\CreateView as DefinedTermSetCreate;
use Cms\Views\DefinedTermSet\EditView as DefinedTermSetEdit;
use Cms\Views\DefinedTermSet\DeleteView as DefinedTermSetDelete;
use Cms\Views\Comment\ListView as CommentList;
use Cms\Views\Comment\DetailView as CommentDetail;
use Cms\Views\Comment\CreateView as CommentCreate;
use Cms\Views\Comment\EditView as CommentEdit;
use Cms\Views\Comment\DeleteView as CommentDelete;
use Cms\Views\WebSite\ListView as WebSiteList;
use Cms\Views\WebSite\DetailView as WebSiteDetail;
use Cms\Views\WebSite\CreateView as WebSiteCreate;
use Cms\Views\WebSite\EditView as WebSiteEdit;
use Cms\Views\WebSite\DeleteView as WebSiteDelete;

const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$ENTITY_ROUTES = [
    ['BlogPosting', 'blog-postings', BlogPostingList::class, BlogPostingDetail::class, BlogPostingCreate::class, BlogPostingEdit::class, BlogPostingDelete::class],
    ['Person', 'persons', PersonList::class, PersonDetail::class, PersonCreate::class, PersonEdit::class, PersonDelete::class],
    ['WebPage', 'web-pages', WebPageList::class, WebPageDetail::class, WebPageCreate::class, WebPageEdit::class, WebPageDelete::class],
    ['ImageObject', 'image-objects', ImageObjectList::class, ImageObjectDetail::class, ImageObjectCreate::class, ImageObjectEdit::class, ImageObjectDelete::class],
    ['CategoryCode', 'category-codes', CategoryCodeList::class, CategoryCodeDetail::class, CategoryCodeCreate::class, CategoryCodeEdit::class, CategoryCodeDelete::class],
    ['CategoryCodeSet', 'category-code-sets', CategoryCodeSetList::class, CategoryCodeSetDetail::class, CategoryCodeSetCreate::class, CategoryCodeSetEdit::class, CategoryCodeSetDelete::class],
    ['DefinedTerm', 'defined-terms', DefinedTermList::class, DefinedTermDetail::class, DefinedTermCreate::class, DefinedTermEdit::class, DefinedTermDelete::class],
    ['DefinedTermSet', 'defined-term-sets', DefinedTermSetList::class, DefinedTermSetDetail::class, DefinedTermSetCreate::class, DefinedTermSetEdit::class, DefinedTermSetDelete::class],
    ['Comment', 'comments', CommentList::class, CommentDetail::class, CommentCreate::class, CommentEdit::class, CommentDelete::class],
    ['WebSite', 'web-sites', WebSiteList::class, WebSiteDetail::class, WebSiteCreate::class, WebSiteEdit::class, WebSiteDelete::class],
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$start = microtime(true);
register_shutdown_function(static function () use ($method, $path, $start): void {
    $code = http_response_code() ?: 200;
    $ms = (int) ((microtime(true) - $start) * 1000);
    error_log("$method $path $code {$ms}ms");
});

function send_html(int $status, string $html): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Length: ' . strlen($html));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    echo $html;
}

function send_redirect(string $location, int $status = 303): void
{
    http_response_code($status);
    header("Location: $location");
}

function not_found_response(): array
{
    return [
        'status' => 404,
        'html' => Layout::layout([
            'title' => 'Not Found',
            'body' => '<p role="alert">Page not found.</p>',
        ]),
    ];
}

function read_form_body(): string
{
    $raw = file_get_contents('php://input');
    return $raw === false ? '' : $raw;
}

function dispatch(array $entityRoutes, string $method, string $path): array
{
    if ($method === 'GET' && $path === '/health') {
        return ['status' => 200, 'json' => ['status' => 'ok']];
    }
    if ($method === 'GET' && $path === '/') {
        $items = '';
        foreach ($entityRoutes as [$entity, $plural, ,,,,]) {
            $items .= '<li><a href="/' . $plural . '">' . Layout::escapeHtml($entity) . '</a></li>';
        }
        return ['status' => 200, 'html' => Layout::layout([
            'title' => 'CMS',
            'body' => '<p>Schema.org-aligned CMS frontend.</p><ul>' . $items . '</ul>',
        ])];
    }

    foreach ($entityRoutes as [$entity, $plural, $listCls, $detailCls, $createCls, $editCls, $deleteCls]) {
        $base = '/' . $plural;
        if ($path === $base) {
            if ($method === 'GET') return $listCls::render(['url' => $_SERVER['REQUEST_URI'] ?? '/']);
            return ['status' => 405, 'html' => Layout::layout(['title' => 'Method not allowed', 'body' => '<p role="alert">Method not allowed.</p>'])];
        }
        if ($path === $base . '/new') {
            if ($method === 'GET') return $createCls::renderForm([]);
            if ($method === 'POST') {
                $form = read_form_body();
                $result = $createCls::handleSubmit(['form' => $form]);
                if (isset($result['redirect'])) return $result;
                if (isset($result['html'])) return $result;
                return $createCls::renderForm([
                    'errors' => $result['errors'] ?? [],
                    'values' => $result['values'] ?? [],
                ]);
            }
            return ['status' => 405, 'html' => Layout::layout(['title' => 'Method not allowed', 'body' => '<p role="alert">Method not allowed.</p>'])];
        }
        if (str_starts_with($path, $base . '/')) {
            $rest = substr($path, strlen($base) + 1);
            $slash = strpos($rest, '/');
            $id = $slash === false ? $rest : substr($rest, 0, $slash);
            $action = $slash === false ? null : substr($rest, $slash + 1);
            if ($action !== null && $action !== 'edit' && $action !== 'delete') {
                continue;
            }
            $idValid = preg_match(UUID_PATTERN, $id) === 1;
            if (!$idValid) {
                return ['status' => 400, 'html' => Layout::layout(['title' => 'Invalid ID', 'body' => '<p role="alert">ID must be a valid UUID.</p>'])];
            }
            if ($action === null) {
                if ($method === 'GET') return $detailCls::render(['id' => $id]);
            } elseif ($action === 'edit') {
                if ($method === 'GET') return $editCls::renderForm(['id' => $id]);
                if ($method === 'POST') {
                    $form = read_form_body();
                    $result = $editCls::handleSubmit(['id' => $id, 'form' => $form]);
                    if (isset($result['redirect'])) return $result;
                    if (isset($result['html'])) return $result;
                    return $editCls::renderForm([
                        'id' => $id,
                        'errors' => $result['errors'] ?? [],
                        'values' => $result['values'] ?? [],
                    ]);
                }
            } elseif ($action === 'delete') {
                if ($method === 'GET') return $deleteCls::renderForm(['id' => $id]);
                if ($method === 'POST') {
                    $result = $deleteCls::handleSubmit(['id' => $id]);
                    if (isset($result['redirect'])) return $result;
                    return $result;
                }
            }
            return ['status' => 405, 'html' => Layout::layout(['title' => 'Method not allowed', 'body' => '<p role="alert">Method not allowed.</p>'])];
        }
    }

    return not_found_response();
}

try {
    $response = dispatch($ENTITY_ROUTES, $method, $path);
    if (isset($response['redirect'])) {
        send_redirect($response['redirect'], $response['status'] ?? 303);
    } elseif (isset($response['json'])) {
        http_response_code($response['status']);
        header('Content-Type: application/json');
        echo json_encode($response['json']);
    } elseif (isset($response['html'])) {
        send_html($response['status'] ?? 200, $response['html']);
    }
} catch (\Throwable $e) {
    error_log("[$method $path] " . $e->getMessage());
    send_html(500, Layout::layout(['title' => 'Error', 'body' => '<p role="alert">Internal server error.</p>']));
}
