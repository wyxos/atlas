<?php

namespace App\Listings;

use App\Http\Resources\FileResource;
use App\Models\File;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Scout\Builder as ScoutBuilder;
use Wyxos\Harmonie\Listing\ListingBase;

class FileListing extends ListingBase
{
    public function baseQuery(): Builder
    {
        return File::query();
    }

    public function filters(Builder|ScoutBuilder $base): void
    {
        // Search filter (filename, title, or source)
        if ($this->has('search') && $this->string('search')->isNotEmpty()) {
            $search = $this->string('search')->toString();
            $base->where(function ($q) use ($search) {
                $q->where('filename', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('source', 'like', "%{$search}%");
            });
        }

        // Date range filter (created_at)
        if ($this->has('date_from')) {
            $dateFrom = $this->string('date_from')->toString();
            if ($dateFrom !== '') {
                $base->whereDate('created_at', '>=', $dateFrom);
            }
        }

        if ($this->has('date_to')) {
            $dateTo = $this->string('date_to')->toString();
            if ($dateTo !== '') {
                $base->whereDate('created_at', '<=', $dateTo);
            }
        }

        // Source filter
        if ($this->has('source')) {
            $source = $this->string('source')->toString();
            if ($source !== 'all' && $source !== '') {
                $base->where('source', $source);
            }
        }

        // MIME type filter
        if ($this->has('mime_type')) {
            $mimeType = $this->string('mime_type')->toString();
            if ($mimeType !== 'all' && $mimeType !== '') {
                $base->where('mime_type', 'like', "{$mimeType}%");
            }
        }

        // Downloaded filter
        if ($this->has('downloaded')) {
            $downloaded = $this->string('downloaded')->toString();
            if ($downloaded === 'yes') {
                $base->where('downloaded', true);
            } elseif ($downloaded === 'no') {
                $base->where('downloaded', false);
            }
        }

        // Order by updated_at descending (latest first)
        $base->orderBy('updated_at', 'desc');
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 15);
    }

    public function filterLabels(): array
    {
        return [
            'search' => 'Search',
            'date_from' => 'Created From',
            'date_to' => 'Created To',
            'source' => 'Source',
            'mime_type' => 'Type',
            'downloaded' => 'Downloaded',
        ];
    }

    public function filterValues(): array
    {
        return [
            'source' => [
                'local' => 'Local',
                'NAS' => 'NAS',
                'YouTube' => 'YouTube',
                'Booru' => 'Booru',
            ],
            'mime_type' => [
                'image' => 'Image',
                'video' => 'Video',
                'audio' => 'Audio',
            ],
            'downloaded' => [
                'yes' => 'Yes',
                'no' => 'No',
            ],
        ];
    }

    public function append($item)
    {
        return new FileResource($item);
    }

    public function handle(): array
    {
        $page = $this->offsetGet('page') ?: 1;
        $base = $this->baseQuery();
        $this->filters($base);

        $perPage = $this->perPage() ?? $this->offsetGet('perPage');

        if ($base instanceof ScoutBuilder) {
            $pagination = $base->paginate($perPage);
        } else {
            $pagination = $base->paginate($perPage, ['*'], null, $page);
        }

        $this->load($pagination);
        $items = collect($pagination->items())->map(fn ($item) => $this->append($item));

        $listing = [
            'listing' => [
                'items' => $items,
                'total' => $pagination->total(),
                'perPage' => $perPage,
                'current_page' => $pagination->currentPage(),
                'last_page' => $pagination->lastPage(),
                'from' => $pagination->firstItem(),
                'to' => $pagination->lastItem(),
                'showing' => $pagination->count() + (int) $perPage * max(0, $pagination->currentPage() - 1),
                'nextPage' => $pagination->hasMorePages() ? $pagination->currentPage() + 1 : null,
            ],
            'links' => [
                'first' => $pagination->url(1),
                'last' => $pagination->url($pagination->lastPage()),
                'prev' => $pagination->previousPageUrl(),
                'next' => $pagination->nextPageUrl(),
            ],
        ];

        $filter = $this->formatFilters($this->all());

        return [
            ...$listing,
            ...$this->customData($items),
            'filters' => $filter,
        ];
    }
}
