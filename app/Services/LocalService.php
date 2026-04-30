<?php

namespace App\Services;

use App\Services\Local\LocalBrowseTypesenseGateway;
use App\Services\Local\LocalFetchParams;

class LocalService extends BaseService
{
    public const string KEY = 'local';

    public const string SOURCE = 'Local';

    public const string LABEL = 'Local Files';

    public function __construct(
        private LocalBrowseTypesenseGateway $typesenseGateway,
        array $params = [],
    ) {
        parent::__construct($params);
    }

    public function fetch(array $params = []): array
    {
        $context = LocalFetchParams::normalize($params);
        $this->params = $context['params'];

        if ($context['shouldReturnEmpty']) {
            return LocalFetchParams::emptyResponse();
        }

        if ($context['sort'] === 'random' && (! is_int($context['seed']) || $context['seed'] < 1)) {
            $seed = time();
            $this->params['seed'] = $seed;
            $context['seed'] = $seed;
        }

        return $this->typesenseGateway->search($context);
    }

    public function transform(array $response, array $params = []): array
    {
        $files = $response['files'] ?? [];
        $nextCursor = $response['metadata']['nextCursor'] ?? null;
        $total = $response['metadata']['total'] ?? null;
        $total = is_numeric($total) ? (int) $total : null;

        return [
            'files' => $files,
            'filter' => [
                ...$this->params,
                'next' => $nextCursor,
            ],
            'meta' => [
                'total' => $total,
            ],
        ];
    }

    public function defaultParams(): array
    {
        return [
            'limit' => 20,
            'source' => 'all',
        ];
    }
}
