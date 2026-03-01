<?php

namespace App\Services\Downloads;

use App\Models\DownloadTransfer;

final class DownloadTransferRequestOptions
{
    public function __construct(private readonly DownloadTransferRuntimeStore $runtimeStore) {}

    /**
     * @return array<string, string>
     */
    public function httpHeaders(DownloadTransfer $transfer): array
    {
        $headers = [];

        if ($transfer->file?->referrer_url) {
            $headers['Referer'] = $transfer->file->referrer_url;
        }

        $runtimeContext = $this->runtimeStore->getForTransfer($transfer->id);
        $runtimeUserAgent = trim((string) ($runtimeContext['user_agent'] ?? ''));
        if ($runtimeUserAgent !== '') {
            $headers['User-Agent'] = $runtimeUserAgent;
        }

        $cookies = $this->cookiesForUrl($runtimeContext['cookies'] ?? [], (string) $transfer->url);
        $cookieHeader = $this->buildCookieHeader($cookies);
        if ($cookieHeader !== null) {
            $headers['Cookie'] = $cookieHeader;
        }

        return $headers;
    }

    /**
     * @return array{0: array{cookies_path?: string, user_agent?: string}, 1: string|null}
     */
    public function ytDlpRuntimeOptions(DownloadTransfer $transfer, string $absoluteTmpDir): array
    {
        $runtimeContext = $this->runtimeStore->getForTransfer($transfer->id);
        $runtimeOptions = [];
        $cookieJarPath = null;

        $runtimeUserAgent = trim((string) ($runtimeContext['user_agent'] ?? ''));
        if ($runtimeUserAgent !== '') {
            $runtimeOptions['user_agent'] = $runtimeUserAgent;
        }

        $cookies = $this->cookiesForUrl($runtimeContext['cookies'] ?? [], (string) $transfer->url);
        if ($cookies !== []) {
            $cookieJarPath = $this->writeCookieJar($cookies, $absoluteTmpDir);
            if ($cookieJarPath !== null) {
                $runtimeOptions['cookies_path'] = $cookieJarPath;
            }
        }

        return [$runtimeOptions, $cookieJarPath];
    }

    /**
     * @return list<array{
     *     name: string,
     *     value: string,
     *     domain: string,
     *     path: string,
     *     secure: bool,
     *     http_only: bool,
     *     host_only: bool,
     *     expires_at: int|null
     * }>
     */
    private function cookiesForUrl(mixed $cookies, string $url): array
    {
        if (! is_array($cookies) || $cookies === []) {
            return [];
        }

        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($host) || $host === '') {
            return [];
        }

        $host = strtolower($host);
        $isHttps = is_string($scheme) && strtolower($scheme) === 'https';
        $requestPath = is_string($path) && $path !== '' ? $path : '/';
        $now = time();

        $matched = [];

        foreach ($cookies as $cookie) {
            if (! is_array($cookie)) {
                continue;
            }

            $cookieDomain = ltrim(strtolower((string) ($cookie['domain'] ?? '')), '.');
            $cookiePath = (string) ($cookie['path'] ?? '/');
            if ($cookiePath === '') {
                $cookiePath = '/';
            }

            $expiresAt = isset($cookie['expires_at']) && is_numeric($cookie['expires_at'])
                ? (int) $cookie['expires_at']
                : null;
            if ($expiresAt !== null && $expiresAt > 0 && $expiresAt <= $now) {
                continue;
            }

            $hostOnly = ($cookie['host_only'] ?? false) === true;
            if ($hostOnly) {
                if ($cookieDomain !== $host) {
                    continue;
                }
            } elseif ($cookieDomain === '' || ($cookieDomain !== $host && ! str_ends_with($host, '.'.$cookieDomain))) {
                continue;
            }

            $secure = ($cookie['secure'] ?? false) === true;
            if ($secure && ! $isHttps) {
                continue;
            }

            if (! str_starts_with($requestPath, $cookiePath)) {
                continue;
            }

            if ($cookiePath !== '/' && ! str_ends_with($cookiePath, '/')) {
                $next = substr($requestPath, strlen($cookiePath), 1);
                if ($next !== '' && $next !== '/') {
                    continue;
                }
            }

            $matched[] = [
                'name' => (string) ($cookie['name'] ?? ''),
                'value' => (string) ($cookie['value'] ?? ''),
                'domain' => $cookieDomain,
                'path' => $cookiePath,
                'secure' => $secure,
                'http_only' => ($cookie['http_only'] ?? false) === true,
                'host_only' => $hostOnly,
                'expires_at' => $expiresAt,
            ];
        }

        usort($matched, static fn (array $left, array $right): int => strlen($right['path']) <=> strlen($left['path']));

        return $matched;
    }

    /**
     * @param  list<array{name: string, value: string}>  $cookies
     */
    private function buildCookieHeader(array $cookies): ?string
    {
        if ($cookies === []) {
            return null;
        }

        $pairs = [];
        foreach ($cookies as $cookie) {
            $name = trim($cookie['name']);
            if ($name === '') {
                continue;
            }

            $value = str_replace(["\r", "\n", ';'], '', $cookie['value']);
            $pairs[] = $name.'='.$value;
        }

        if ($pairs === []) {
            return null;
        }

        return implode('; ', $pairs);
    }

    /**
     * @param  list<array{
     *     name: string,
     *     value: string,
     *     domain: string,
     *     path: string,
     *     secure: bool,
     *     http_only: bool,
     *     host_only: bool,
     *     expires_at: int|null
     * }>  $cookies
     */
    private function writeCookieJar(array $cookies, string $absoluteTmpDir): ?string
    {
        $lines = ['# Netscape HTTP Cookie File', '# Generated by Atlas runtime options'];

        foreach ($cookies as $cookie) {
            $name = trim($cookie['name']);
            if ($name === '' || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
                continue;
            }

            $domain = $cookie['domain'];
            if ($domain === '') {
                continue;
            }

            $includeSubdomains = $cookie['host_only'] ? 'FALSE' : 'TRUE';
            if (! $cookie['host_only'] && ! str_starts_with($domain, '.')) {
                $domain = '.'.$domain;
            }

            $domainField = $cookie['http_only'] ? '#HttpOnly_'.$domain : $domain;
            $path = $cookie['path'] !== '' ? $cookie['path'] : '/';
            $secure = $cookie['secure'] ? 'TRUE' : 'FALSE';
            $expires = $cookie['expires_at'] !== null ? max(0, (int) $cookie['expires_at']) : 0;
            $value = str_replace(["\t", "\r", "\n"], '', $cookie['value']);

            $lines[] = implode("\t", [
                $domainField,
                $includeSubdomains,
                $path,
                $secure,
                (string) $expires,
                $name,
                $value,
            ]);
        }

        if (count($lines) <= 2) {
            return null;
        }

        $cookieJarPath = $absoluteTmpDir.DIRECTORY_SEPARATOR.'runtime-cookies.txt';
        $written = @file_put_contents($cookieJarPath, implode(PHP_EOL, $lines).PHP_EOL);

        return $written !== false ? $cookieJarPath : null;
    }
}
