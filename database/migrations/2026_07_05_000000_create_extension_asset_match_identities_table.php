<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_asset_match_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->string('match_by', 32);
            $table->text('match_url');
            $table->string('match_url_hash', 64);
            $table->string('rule_id', 128)->nullable();
            $table->string('rule_digest', 128)->default('');
            $table->timestamps();

            $table->index(['match_by', 'match_url_hash'], 'extension_asset_match_lookup_idx');
            $table->unique(
                ['file_id', 'match_by', 'match_url_hash', 'rule_digest'],
                'extension_asset_match_file_rule_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_asset_match_identities');
    }
};
