<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reactions', function (Blueprint $table) {
            // Supports reaction_at sorting for a single user.
            $table->index(['user_id', 'created_at', 'file_id'], 'reactions_user_created_file_idx');
            // Supports reaction_at sorting scoped to specific reaction types.
            $table->index(['user_id', 'type', 'created_at', 'file_id'], 'reactions_user_type_created_file_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reactions', function (Blueprint $table) {
            $table->dropIndex('reactions_user_created_file_idx');
            $table->dropIndex('reactions_user_type_created_file_idx');
        });
    }
};
