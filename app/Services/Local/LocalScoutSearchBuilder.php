<?php

namespace App\Services\Local;

use App\Models\File;

class LocalScoutSearchBuilder
{
    /**
     * @param  array<string, mixed>  $params
     * @param  array<int, string>  $fileTypes
     */
    public static function build(
        array $params,
        ?string $source,
        string $downloaded,
        string $blacklisted,
        string $blacklistType,
        string $sort,
        ?int $seed,
        ?int $maxPreviewed,
        array $fileTypes,
    ): mixed {
        $search = $params['search'] ?? '';
        if ($search === '') {
            $search = config('scout.driver') === 'typesense' ? '*' : '';
        }

        $builder = File::search($search);

        if ($source && $source !== 'all') {
            $builder->where('source', $source);
        }

        if ($downloaded === 'yes') {
            $builder->where('downloaded', true);
        } elseif ($downloaded === 'no') {
            $builder->where('downloaded', false);
        }

        if ($blacklisted === 'yes') {
            $builder->where('blacklisted', true);
        } elseif ($blacklisted === 'no') {
            $builder->where('blacklisted', false);
        }

        if (in_array($blacklistType, ['manual', 'auto'], true)) {
            $builder->where('blacklisted', true);
            $builder->where('blacklist_type', $blacklistType);
        }

        if (is_int($maxPreviewed) && $maxPreviewed >= 0) {
            $builder->where('previewed_count', ['<=', $maxPreviewed]);
        }

        if (! in_array('all', $fileTypes, true)) {
            if (count($fileTypes) === 1) {
                $builder->where('mime_group', $fileTypes[0]);
            } else {
                $builder->whereIn('mime_group', $fileTypes);
            }
        }

        $driver = config('scout.driver');
        if ($sort === 'random' && $driver === 'typesense') {
            $rand = $seed && $seed > 0 ? "_rand({$seed})" : '_rand()';
            $builder->orderBy($rand, 'desc');
        } elseif ($sort === 'created_at_asc') {
            $builder->orderBy('created_at', 'asc');
        } elseif ($sort === 'created_at') {
            $builder->orderBy('created_at', 'desc');
        } elseif ($sort === 'updated_at') {
            $builder->orderBy('updated_at', 'desc');
        } elseif ($sort === 'updated_at_asc') {
            $builder->orderBy('updated_at', 'asc');
        } elseif ($sort === 'blacklisted_at') {
            $builder->orderBy('blacklisted_at', 'desc')
                ->orderBy('updated_at', 'desc');
        } elseif ($sort === 'blacklisted_at_asc') {
            $builder->orderBy('blacklisted_at', 'asc')
                ->orderBy('updated_at', 'asc');
        } elseif ($sort === 'downloaded_at_asc') {
            $builder->orderBy('downloaded_at', 'asc')
                ->orderBy('updated_at', 'asc');
        } else {
            $builder->orderBy('downloaded_at', 'desc')
                ->orderBy('updated_at', 'desc');
        }

        return $builder;
    }

    public static function applyAutoDislikedFilter(mixed $builder, string $autoDisliked): mixed
    {
        if ($autoDisliked === 'yes') {
            $builder->where('auto_disliked', true);
        } elseif ($autoDisliked === 'no') {
            $builder->where('auto_disliked', false);
        }

        return $builder;
    }
}
