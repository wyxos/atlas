<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_scan_media_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_scan_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('type')->index();
            $table->string('status')->index();
            $table->string('phase')->nullable()->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('result')->nullable();
            $table->string('error_code')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->json('error_context')->nullable();
            $table->timestamps();

            $table->unique(['library_scan_item_id', 'type']);
            $table->index(['library_scan_item_id', 'status']);
            $table->index(['file_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_scan_media_tasks');
    }
};
