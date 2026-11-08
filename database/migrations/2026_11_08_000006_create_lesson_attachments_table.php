<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lesson_id')
                ->constrained('lessons')
                ->cascadeOnDelete();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Attachment Type ('pdf' | 'whiteboard_image')
            $table->string('type', 30);
            $table->string('title', 220);
            $table->string('caption', 500)->nullable();
            $table->string('original_file_name', 255);

            // Private Encrypted/Protected Storage Metadata
            $table->string('storage_disk', 40)->default('local');
            $table->string('storage_path', 500);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->string('file_size_label', 40)->default('1.5 MB');
            $table->unsignedSmallInteger('pages_count')->nullable();
            $table->unsignedSmallInteger('display_order')->default(1);

            $table->timestamps();

            $table->index(['lesson_id', 'type', 'display_order']);
            $table->index(['teacher_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_attachments');
    }
};

