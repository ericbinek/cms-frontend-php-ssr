<?php
declare(strict_types=1);

$ENTITY = 'Comment';
$BASE = '/comments';

test("$ENTITY: GET list renders semantic page", function (array $stack) use ($ENTITY, $BASE) {
    cms_ensure_entity($stack, $ENTITY);
    $r = cms_frontend_get($stack, $BASE);
    cms_assert_equal(200, $r['status']);
    cms_assert_match('/<table\b/', $r['body']);
    cms_assert_match('/<caption>/', $r['body']);
    cms_assert_match('/' . preg_quote($ENTITY, '/') . '/', $r['body']);
});

test("$ENTITY: GET /new renders a form", function (array $stack) use ($ENTITY, $BASE) {
    $r = cms_frontend_get($stack, $BASE . '/new');
    cms_assert_equal(200, $r['status']);
    cms_assert_match('/<form[^>]+method="POST"/', $r['body']);
});

test("$ENTITY: POST /new with valid form redirects to detail", function (array $stack) use ($ENTITY, $BASE) {
    $body = cms_form_body_for($stack, $ENTITY);
    $r = cms_frontend_post_form($stack, $BASE . '/new', $body);
    cms_assert_equal(303, $r['status']);
    $loc = $r['headers']['location'] ?? '';
    cms_assert(str_starts_with($loc, $BASE . '/'), "expected redirect to $BASE/<id>, got $loc");
});

test("$ENTITY: POST /new with empty form returns 400 or 303", function (array $stack) use ($ENTITY, $BASE) {
    $r = cms_frontend_post_form($stack, $BASE . '/new', '');
    if ($r['status'] === 303) return;
    cms_assert_equal(400, $r['status']);
    cms_assert_match('/role="alert"/', $r['body']);
});

test("$ENTITY: GET detail returns 200 with article markup", function (array $stack) use ($ENTITY, $BASE) {
    $id = cms_ensure_entity($stack, $ENTITY);
    $r = cms_frontend_get($stack, $BASE . '/' . $id);
    cms_assert_equal(200, $r['status']);
    cms_assert_match('/<article\b/', $r['body']);
    cms_assert_match('/<dl>/', $r['body']);
    cms_assert_match('/' . preg_quote($id, '/') . '/', $r['body']);
});

test("$ENTITY: GET edit renders pre-filled form", function (array $stack) use ($ENTITY, $BASE) {
    $id = cms_ensure_entity($stack, $ENTITY);
    $r = cms_frontend_get($stack, $BASE . '/' . $id . '/edit');
    cms_assert_equal(200, $r['status']);
    cms_assert_match('/<form[^>]+method="POST"/', $r['body']);
});

test("$ENTITY: POST edit redirects back to detail", function (array $stack) use ($ENTITY, $BASE) {
    $id = cms_ensure_entity($stack, $ENTITY);
    $body = cms_form_body_for($stack, $ENTITY);
    $r = cms_frontend_post_form($stack, $BASE . '/' . $id . '/edit', $body);
    cms_assert_equal(303, $r['status']);
    cms_assert_equal($BASE . '/' . $id, $r['headers']['location'] ?? '');
});

test("$ENTITY: GET delete renders confirmation form", function (array $stack) use ($ENTITY, $BASE) {
    $id = cms_ensure_entity($stack, $ENTITY);
    $r = cms_frontend_get($stack, $BASE . '/' . $id . '/delete');
    cms_assert_equal(200, $r['status']);
    cms_assert_match('/<form[^>]+method="POST"/', $r['body']);
    cms_assert_match('/Confirm Delete/', $r['body']);
});

test("$ENTITY: POST delete redirects to list", function (array $stack) use ($ENTITY, $BASE) {
    $id = cms_ensure_entity($stack, $ENTITY);
    $r = cms_frontend_post_form($stack, $BASE . '/' . $id . '/delete', '');
    cms_assert_equal(303, $r['status']);
    cms_assert_equal($BASE, $r['headers']['location'] ?? '');
});

test("$ENTITY: GET detail with non-UUID id returns 400 with alert", function (array $stack) use ($ENTITY, $BASE) {
    $r = cms_frontend_get($stack, $BASE . '/not-a-uuid');
    cms_assert_equal(400, $r['status']);
    cms_assert_match('/role="alert"/', $r['body']);
});

test("$ENTITY: GET detail of missing id renders 404 page", function (array $stack) use ($ENTITY, $BASE) {
    $r = cms_frontend_get($stack, $BASE . '/00000000-0000-0000-0000-000000000000');
    cms_assert_equal(404, $r['status']);
    cms_assert_match('/role="alert"/', $r['body']);
});

test("$ENTITY: navigation includes self link with aria-current", function (array $stack) use ($ENTITY, $BASE) {
    cms_ensure_entity($stack, $ENTITY);
    $r = cms_frontend_get($stack, $BASE);
    cms_assert_match('/aria-current="page"/', $r['body']);
});

test("$ENTITY: list view paginates with previous and next navigation", function (array $stack) use ($ENTITY, $BASE) {
    cms_seed_with($stack, $ENTITY, []);
    cms_seed_with($stack, $ENTITY, []);
    cms_seed_with($stack, $ENTITY, []);
    $first = cms_frontend_get($stack, $BASE . '?limit=2&offset=0');
    cms_assert_equal(200, $first['status']);
    cms_assert(str_contains($first['body'], 'rel="next"'), 'expected a next link on page one');
    cms_assert(str_contains($first['body'], 'offset=2'), 'expected next link to advance offset to 2');
    cms_assert(!str_contains($first['body'], 'rel="prev"'), 'page one must not have a previous link');

    $second = cms_frontend_get($stack, $BASE . '?limit=2&offset=2');
    cms_assert_equal(200, $second['status']);
    cms_assert(str_contains($second['body'], 'rel="prev"'), 'expected a previous link on page two');
});
