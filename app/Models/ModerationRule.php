<?php

namespace App\Models;

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
        'name', 'active', 'nsfw', 'action_type', 'op', 'terms', 'min', 'options', 'children',
    ];

    /**
     * Action type constants.
     */
    public const ACTION_UI_COUNTDOWN = 'ui_countdown';
    public const ACTION_AUTO_DISLIKE = 'auto_dislike';
    public const ACTION_BLACKLIST = 'blacklist';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
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
     * Convert the rule to a node structure.
     *
     * @return array<string, mixed>
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
