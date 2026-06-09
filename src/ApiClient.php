<?php
declare(strict_types=1);

namespace Cms;

final class ApiClient
{
    public const PLURALS = [
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

    public static function baseUrl(): string
    {
        return rtrim(getenv('API_BASE_URL') ?: 'http://localhost:3002', '/');
    }

    public static function pluralOf(string $entity): string
    {
        if (!isset(self::PLURALS[$entity])) {
            throw new \InvalidArgumentException("Unknown entity for plural lookup: $entity");
        }
        return self::PLURALS[$entity];
    }

    private static function request(string $method, string $path, mixed $body = null, array $headers = []): array
    {
        $ch = curl_init(self::baseUrl() . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $hdr = ['Accept: application/json'];
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
            $hdr[] = 'Content-Type: application/json';
        }
        foreach ($headers as $k => $v) $hdr[] = "$k: $v";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            return ['status' => 0, 'body' => ['message' => "ApiClient request failed: $err"], 'etag' => null];
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $rawHeaders = substr($response, 0, $headerSize);
        $rawBody = substr($response, $headerSize);
        $etag = null;
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (stripos($line, 'etag:') === 0) {
                $etag = trim(substr($line, 5));
                break;
            }
        }
        $parsed = $rawBody !== '' ? json_decode($rawBody, true) : null;
        return ['status' => $status, 'body' => $parsed, 'etag' => $etag];
    }

    public static function list(string $entity, array $query = []): array
    {
        $sp = http_build_query(array_filter($query, static fn ($v) => $v !== null && $v !== ''));
        $path = '/' . self::pluralOf($entity) . ($sp !== '' ? '?' . $sp : '');
        return self::request('GET', $path);
    }

    public static function get(string $entity, string $id): array
    {
        return self::request('GET', '/' . self::pluralOf($entity) . '/' . rawurlencode($id));
    }

    public static function create(string $entity, array $payload): array
    {
        return self::request('POST', '/' . self::pluralOf($entity), $payload);
    }

    public static function update(string $entity, string $id, array $payload): array
    {
        return self::request('PUT', '/' . self::pluralOf($entity) . '/' . rawurlencode($id), $payload);
    }

    public static function remove(string $entity, string $id): array
    {
        return self::request('DELETE', '/' . self::pluralOf($entity) . '/' . rawurlencode($id));
    }
}
