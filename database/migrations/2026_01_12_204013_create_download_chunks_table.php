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
        Schema::create('download_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('download_transfer_id')->constrained('download_transfers')->cascadeOnDelete();
            $table->unsignedInteger('index');
            $table->unsignedBigInteger('range_start');
            $table->unsignedBigInteger('range_end');
            $table->unsignedBigInteger('bytes_downloaded')->default(0);
            $table->string('status')->index();
            $table->text('part_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['download_transfer_id', 'index']);
            $table->index(['download_transfer_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('download_chunks');
    }
};
