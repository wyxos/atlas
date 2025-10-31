<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key', 'machine', 'value',
    ];

    public static function get(string $key, $default = null): mixed
    {
        $machine = gethostname() ?: php_uname('n');
        $row = static::query()->where('key', $key)->where('machine', $machine)->first();
        if ($row) {
            return static::decodeValue($row->value);
        }

        $row = static::query()->where('key', $key)->where('machine', '')->first();
        if ($row) {
            return static::decodeValue($row->value);
        }

        return $default;
    }

    public static function setForCurrentMachine(string $key, $value): self
    {
        $machine = gethostname() ?: php_uname('n');

        return static::updateOrCreate(
            ['key' => $key, 'machine' => $machine],
            ['value' => static::encodeValue($value)]
        );
    }

    public static function setGlobal(string $key, $value): self
    {
        return static::updateOrCreate(
            ['key' => $key, 'machine' => ''],
            ['value' => static::encodeValue($value)]
        );
    }

    protected static function encodeValue($value): string
    {
        // Store arrays/objects as JSON, scalars as plain strings
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }

    protected static function decodeValue(string $raw): mixed
    {
        $trim = trim($raw);
        if ($trim === '') {
            return '';
        }
        // Detect JSON
        if ((str_starts_with($trim, '{') && str_ends_with($trim, '}')) || (str_starts_with($trim, '[') && str_ends_with($trim, ']'))) {
            $decoded = json_decode($raw, true);

            return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $raw : $decoded;
        }

        return $raw;
    }
}
