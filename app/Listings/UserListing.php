<?php

namespace App\Listings;

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
        return $item;
    }
}
