<?php

namespace App\Listings;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Scout\Builder as ScoutBuilder;
use Wyxos\Harmonie\Listing\ListingBase;

class UserListing extends ListingBase
{
    public function baseQuery(): Builder
    {
        return User::query();
    }

    public function filters(Builder|ScoutBuilder $base): void
    {
        // Search filter (name or email)
        if ($this->has('search') && $this->string('search')->isNotEmpty()) {
            $search = $this->string('search')->toString();
            $base->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Date range filter (created_at) - using whereDate for date-only comparison
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

        // Status filter (verified/unverified)
        if ($this->has('status')) {
            $status = $this->string('status')->toString();
            if ($status === 'verified') {
                $base->whereNotNull('email_verified_at');
            } elseif ($status === 'unverified') {
                $base->whereNull('email_verified_at');
            }
        }

        // Order by name
        $base->orderBy('name');
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
            'status' => 'Status',
        ];
    }

    public function filterValues(): array
    {
        return [
            'status' => [
                'verified' => 'Verified',
                'unverified' => 'Unverified',
            ],
        ];
    }

    public function append($item)
    {
        return new UserResource($item);
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
