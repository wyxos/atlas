<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModerationRule extends Model
{
    /** @use HasFactory<\\Database\\Factories\\ModerationRuleFactory> */
    use HasFactory;

    protected $table = 'moderation_rules';

    protected $fillable = [
        'name', 'active', 'nsfw', 'op', 'terms', 'min', 'options', 'children',
    ];

    protected $casts = [
        'active' => 'boolean',
        'nsfw' => 'boolean',
        'terms' => 'array',
        'min' => 'integer',
        'options' => 'array',
        'children' => 'array',
    ];

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
