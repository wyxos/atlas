<?php

namespace App\Models;

use App\Enums\BlacklistPreviewedCountMode;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModerationRule extends Model
{
    /** @use HasFactory<\Database\Factories\ModerationRuleFactory> */
    use HasFactory;

    protected $table = 'moderation_rules';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name', 'active', 'nsfw', 'action_type', 'blacklist_previewed_count_mode', 'op', 'terms', 'min', 'options', 'children',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'nsfw' => 'boolean',
            'terms' => 'array',
            'min' => 'integer',
            'options' => 'array',
            'children' => 'array',
        ];
    }

    /**
     * Default legacy/null rows to preserving previewed count.
     */
    protected function blacklistPreviewedCountMode(): Attribute
    {
        return Attribute::get(fn (?string $value): string => $value ?: BlacklistPreviewedCountMode::PRESERVE);
    }

    /**
     * Convert the rule to a node structure.
     */
    public function toNode(): array
    {
        return array_filter([
            'op' => $this->op,
            'terms' => $this->terms,
            'min' => $this->min,
            'options' => $this->options,
            'children' => $this->children,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
