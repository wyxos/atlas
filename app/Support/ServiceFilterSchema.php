<?php

namespace App\Support;

use Illuminate\Support\Str;

final class ServiceFilterSchema
{
    public function __construct(
        private array $keyMap = [],
        private array $typeMap = [],
        private array $labelMap = [],
    ) {}

    public static function make(): self
    {
        return new self;
    }

    /**
     * @param  array<string, string>  $keyMap
     * @param  array<string, string>  $typeMap
     * @param  array<string, string>  $labelMap
     */
    public static function fromMaps(array $keyMap = [], array $typeMap = [], array $labelMap = []): self
    {
        return self::make()
            ->keys($keyMap)
            ->types($typeMap)
            ->labels($labelMap);
    }

    /**
     * @param  array<string, string>  $map
     */
    public function keys(array $map): self
    {
        $this->keyMap = $map;

        return $this;
    }

    /**
     * @param  array<string, string>  $map
     */
    public function types(array $map): self
    {
        $this->typeMap = $map;

        return $this;
    }

    /**
     * @param  array<string, string>  $map
     */
    public function labels(array $map): self
    {
        $this->labelMap = $map;

        return $this;
    }

    public function serviceKey(string $uiKey): string
    {
        return $this->keyMap[$uiKey] ?? $uiKey;
    }

    public function type(string $uiKey, string $default = 'text'): string
    {
        return $this->typeMap[$uiKey] ?? $default;
    }

    public function label(string $uiKey): string
    {
        return $this->labelMap[$uiKey] ?? Str::headline($uiKey);
    }

    public function field(string $uiKey, array $overrides = []): array
    {
        return [
            'uiKey' => $uiKey,
            'serviceKey' => $this->serviceKey($uiKey),
            'type' => $this->type($uiKey),
            'label' => $this->label($uiKey),
            ...$overrides,
        ];
    }

    /**
     * Wrap a list of fields into the schema payload shape.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    public function fields(array $fields): array
    {
        return [
            'fields' => $fields,
        ];
    }

    public function pageField(array $overrides = []): array
    {
        return $this->field('page', [
            'description' => 'Pagination cursor (managed automatically).',
            ...$overrides,
        ]);
    }

    public function limitField(array $overrides = []): array
    {
        return $this->field('limit', [
            'description' => 'The number of results per page (0-200).',
            'min' => 0,
            'max' => 200,
            'step' => 1,
            ...$overrides,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paginationFields(): array
    {
        return [
            $this->pageField(),
            $this->limitField(),
        ];
    }
}
