<?php

namespace App\Models;

use App\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Columns
 * @property int id
 * @property string|null name
 * @property string type
 * @property array terms
 * @property string match
 * @property array|null unless
 * @property array|null with_terms
 * @property string action
 * @property bool active
 * @property string|null description
 * @property Carbon|null created_at
 * @property Carbon|null updated_at
 *
 * Relationships
 *
 * Getters
 *
 */
class ModerationRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'terms',
        'match',
        'unless',
        'with_terms',
        'action',
        'active',
        'description',
    ];

    protected $casts = [
        'terms' => 'array',
        'unless' => 'array',
        'with_terms' => 'array',
        'active' => 'boolean',
    ];

    public function toContentModeratorFormat(): array
    {
        $rule = [
            'type' => $this->type,
            'terms' => $this->terms,
            'action' => $this->action,
        ];

        if ($this->type === 'contains') {
            $rule['match'] = $this->match;
            if ($this->unless) {
                $rule['unless'] = $this->unless;
            }
        }

        if ($this->type === 'contains-combo') {
            if ($this->with_terms && !empty($this->with_terms)) {
                $rule['with'] = $this->with_terms;
            }
        }

        return $rule;
    }
}
