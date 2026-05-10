<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_browse_reindex_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->index();
            $table->string('phase')->nullable()->index();
            $table->string('suffix')->index();
            $table->string('files_alias')->nullable();
            $table->string('files_collection')->nullable();
            $table->string('reactions_alias')->nullable();
            $table->string('reactions_collection')->nullable();
            $table->unsignedBigInteger('files_total')->default(0);
            $table->unsignedBigInteger('files_indexed')->default(0);
            $table->unsignedBigInteger('reactions_total')->default(0);
            $table->unsignedBigInteger('reactions_indexed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_browse_reindex_runs');
    }
};
