<?php

namespace App\Models;

use App\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Columns
 *
 * @property int id
 * @property string aliasable_type
 * @property int aliasable_id
 * @property string field
 * @property string value
 * @property string normalized_value
 * @property string|null kind
 * @property string|null locale
 * @property string|null source
 * @property string|null source_id
 * @property Carbon|null created_at
 * @property Carbon|null updated_at
 *
 * Relationships
 *
 * Getters
 */
class MetadataAlias extends Model
{
    use HasFactory;

    protected $fillable = [
        'aliasable_type',
        'aliasable_id',
        'field',
        'value',
        'normalized_value',
        'kind',
        'locale',
        'source',
        'source_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $alias): void {
            $alias->field = trim((string) $alias->field);
            $alias->value = trim((string) $alias->value);
            $alias->normalized_value = self::normalizeValue($alias->value);
        });
    }

    public function aliasable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function normalizeValue(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(trim($value))) ?? '');
    }
}
