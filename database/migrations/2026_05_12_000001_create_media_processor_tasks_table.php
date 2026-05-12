<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_processor_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignId('library_scan_media_task_id')->nullable()->constrained('library_scan_media_tasks')->nullOnDelete();
            $table->string('operation')->index();
            $table->string('status')->index();
            $table->string('phase')->nullable()->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('processor_url')->nullable();
            $table->string('storage_profile');
            $table->string('atlas_instance')->nullable();
            $table->text('input_path');
            $table->json('output_paths')->nullable();
            $table->json('options')->nullable();
            $table->json('result')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('last_event_at')->nullable()->index();
            $table->string('error_code')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->json('error_context')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_event_at']);
            $table->index(['file_id', 'operation']);
            $table->index(['library_scan_media_task_id', 'operation'], 'media_processor_scan_task_operation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_processor_tasks');
    }
};
