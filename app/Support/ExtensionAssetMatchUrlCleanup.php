<?php

namespace App\Support;

class ExtensionAssetMatchUrlCleanup
{
    public function apply(string $url, mixed $cleanup): string
    {
        if (! is_array($cleanup)) {
            return $url;
        }

        if (array_is_list($cleanup)) {
            foreach ($cleanup as $operation) {
                $url = $this->applyOperation($url, is_array($operation) ? $operation : []);
            }

            return $url;
        }

        if (($cleanup['removeFragment'] ?? false) === true) {
            $url = $this->removeFragment($url);
        }

        $query = is_array($cleanup['query'] ?? null) ? $cleanup['query'] : [];
        $mode = $this->normalizeString($query['mode'] ?? 'none');
        $params = $this->normalizeQueryParams($query['params'] ?? []);

        return match ($mode) {
            'strip-all' => $this->stripAllQuery($url),
            'strip-selected' => $this->filterQueryParams($url, $params, keep: false),
            'keep-selected' => $this->filterQueryParams($url, $params, keep: true),
            default => $url,
        };
    }

    public function urlMatchesDomain(string $url, ?string $domain): bool
    {
        if ($domain === null) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host)) {
            return false;
        }

        $normalizedHost = strtolower($host);

        return $normalizedHost === $domain || str_ends_with($normalizedHost, '.'.$domain);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function applyOperation(string $url, array $operation): string
    {
        $type = $this->normalizeString($operation['type'] ?? '');
        $queryParams = $this->normalizeQueryParams($operation['query_params'] ?? []);

        return match ($type) {
            'remove_fragment' => $this->removeFragment($url),
            'strip_all_query_params' => $this->stripAllQuery($url),
            'strip_query_params' => $this->filterQueryParams($url, $queryParams, keep: false),
            'keep_query_params' => $this->filterQueryParams($url, $queryParams, keep: true),
            default => $url,
        };
    }

    private function removeFragment(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        unset($parts['fragment']);

        return $this->buildUrl($parts);
    }

    private function stripAllQuery(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        unset($parts['query']);

        return $this->buildUrl($parts);
    }

    /**
     * @param  list<string>  $params
     */
    private function filterQueryParams(string $url, array $params, bool $keep): string
    {
        if ($params === []) {
            return $url;
        }

        $parts = parse_url($url);
        $query = is_array($parts) && is_string($parts['query'] ?? null) ? $parts['query'] : '';
        if (! is_array($parts) || $query === '') {
            return $url;
        }

        $remaining = [];
        foreach (explode('&', $query) as $segment) {
            if ($segment === '') {
                continue;
            }

            $key = strtolower(urldecode(explode('=', $segment, 2)[0]));
            $matched = in_array($key, $params, true);
            if (($keep && $matched) || (! $keep && ! $matched)) {
                $remaining[] = $segment;
            }
        }

        if ($remaining === []) {
            unset($parts['query']);
        } else {
            $parts['query'] = implode('&', $remaining);
        }

        return $this->buildUrl($parts);
    }

    /**
     * @return list<string>
     */
    private function normalizeQueryParams(mixed $params): array
    {
        if (! is_array($params)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $param): string => $this->normalizeString($param), $params),
            fn (string $param): bool => $param !== '',
        )));
    }

    private function normalizeString(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function buildUrl(array $parts): string
    {
        $url = strtolower((string) ($parts['scheme'] ?? 'https')).'://';
        if (isset($parts['user'])) {
            $url .= (string) $parts['user'];
            if (isset($parts['pass'])) {
                $url .= ':'.(string) $parts['pass'];
            }
            $url .= '@';
        }

        $url .= strtolower((string) ($parts['host'] ?? ''));
        if (isset($parts['port'])) {
            $url .= ':'.(string) $parts['port'];
        }

        $url .= (string) ($parts['path'] ?? '');
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?'.(string) $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $url .= '#'.(string) $parts['fragment'];
        }

        return $url;
    }
}
