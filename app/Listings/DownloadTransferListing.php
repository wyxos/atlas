<?php

namespace App\Listings;

use App\Enums\DownloadTransferStatus;
use App\Http\Resources\DownloadTransferResource;
use App\Models\DownloadTransfer;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Scout\Builder as ScoutBuilder;
use Wyxos\Harmonie\Listing\ListingBase;

class DownloadTransferListing extends ListingBase
{
    public function baseQuery(): Builder
    {
        return DownloadTransfer::query()->with('file');
    }

    public function filters(Builder|ScoutBuilder $base): void
    {
        if ($this->has('search') && $this->string('search')->isNotEmpty()) {
            $search = $this->string('search')->toString();

            $base->where(function ($query) use ($search) {
                $query->where('domain', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%")
                    ->orWhereHas('file', fn ($subQuery) => $subQuery->where('filename', 'like', "%{$search}%"));

                if (is_numeric($search)) {
                    $query->orWhere('id', (int) $search)
                        ->orWhere('file_id', (int) $search);
                }
            });
        }

        $status = $this->has('status') ? $this->string('status')->toString() : 'active';

        if ($status !== '' && $status !== 'all') {
            if ($status === 'active') {
                $base->whereIn('status', [
                    DownloadTransferStatus::PENDING,
                    DownloadTransferStatus::QUEUED,
                    DownloadTransferStatus::PREPARING,
                    DownloadTransferStatus::DOWNLOADING,
                    DownloadTransferStatus::ASSEMBLING,
                    DownloadTransferStatus::PAUSED,
                    DownloadTransferStatus::FAILED,
                ]);
            } elseif (in_array($status, [
                DownloadTransferStatus::PENDING,
                DownloadTransferStatus::QUEUED,
                DownloadTransferStatus::PREPARING,
                DownloadTransferStatus::DOWNLOADING,
                DownloadTransferStatus::ASSEMBLING,
                DownloadTransferStatus::PAUSED,
                DownloadTransferStatus::COMPLETED,
                DownloadTransferStatus::FAILED,
                DownloadTransferStatus::CANCELED,
            ], true)) {
                $base->where('status', $status);
            }
        }

        $base->orderByDesc('id');
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 50);
    }

    public function filterLabels(): array
    {
        return [
            'search' => 'Search',
            'status' => 'Status',
        ];
    }

    public function filterValues(): array
    {
        return [
            'status' => [
                'active' => 'Active',
                'queued' => 'Queued',
                'paused' => 'Paused',
                'downloading' => 'Downloading',
                'assembling' => 'Assembling',
                'failed' => 'Failed',
                'completed' => 'Completed',
                'canceled' => 'Canceled',
                'all' => 'All',
            ],
        ];
    }

    public function append($item)
    {
        return new DownloadTransferResource($item);
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
