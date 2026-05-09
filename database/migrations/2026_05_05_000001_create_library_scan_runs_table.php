<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_scan_runs', function (Blueprint $table) {
            $table->id();
            $table->string('mode')->default('scan')->index();
            $table->string('status')->index();
            $table->string('phase')->nullable()->index();
            $table->unsignedBigInteger('files_found')->default(0);
            $table->unsignedBigInteger('files_imported')->default(0);
            $table->unsignedBigInteger('files_duplicate')->default(0);
            $table->unsignedBigInteger('files_processed')->default(0);
            $table->unsignedBigInteger('files_failed')->default(0);
            $table->unsignedBigInteger('files_canceled')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('scan_completed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_scan_runs');
    }
};
