<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('metadata_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('aliasable_type', 64);
            $table->unsignedBigInteger('aliasable_id');
            $table->string('field', 64);
            $table->text('value');
            $table->string('normalized_value');
            $table->string('kind', 64)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('source', 64)->nullable();
            $table->string('source_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['aliasable_type', 'aliasable_id', 'field', 'normalized_value'],
                'metadata_aliases_alias_unique'
            );
            $table->index(['aliasable_type', 'aliasable_id'], 'metadata_aliases_aliasable_idx');
            $table->index(['field', 'normalized_value'], 'metadata_aliases_field_value_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metadata_aliases');
    }
};
