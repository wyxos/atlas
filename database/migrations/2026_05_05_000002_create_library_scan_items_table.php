<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_scan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_scan_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->text('original_path');
            $table->text('imported_path')->nullable();
            $table->string('hash', 64)->nullable()->index();
            $table->string('mime_type')->nullable()->index();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('status')->index();
            $table->string('phase')->nullable()->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->boolean('duplicate')->default(false)->index();
            $table->string('parser')->nullable()->index();
            $table->timestamp('parser_queued_at')->nullable()->index();
            $table->string('error_code')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->json('error_context')->nullable();
            $table->timestamps();

            $table->index(['library_scan_run_id', 'status']);
            $table->index(['library_scan_run_id', 'status', 'parser_queued_at'], 'scan_items_run_status_parser_queued_idx');
            $table->index(['library_scan_run_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_scan_items');
    }
};
