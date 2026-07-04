<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtensionAssetMatchIdentity extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $identity): void {
            $matchUrl = trim((string) ($identity->match_url ?? ''));
            $identity->match_url = $matchUrl;
            $identity->match_url_hash = $matchUrl !== '' ? hash('sha256', $matchUrl) : '';
            $identity->match_by = strtolower(trim((string) ($identity->match_by ?? '')));
            $identity->rule_id = trim((string) ($identity->rule_id ?? '')) ?: null;
            $identity->rule_digest = trim((string) ($identity->rule_digest ?? ''));
        });
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'file_id',
        'match_by',
        'match_url',
        'match_url_hash',
        'rule_id',
        'rule_digest',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
