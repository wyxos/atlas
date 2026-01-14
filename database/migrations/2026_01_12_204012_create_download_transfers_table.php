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
        Schema::create('download_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained('files')->cascadeOnDelete();
            $table->text('url');
            $table->string('domain')->index();
            $table->string('status')->index();

            $table->unsignedBigInteger('bytes_total')->nullable();
            $table->unsignedBigInteger('bytes_downloaded')->default(0);
            $table->unsignedSmallInteger('last_broadcast_percent')->default(0);

            $table->string('batch_id')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['domain', 'status']);
            $table->index(['file_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('download_transfers');
    }
};
