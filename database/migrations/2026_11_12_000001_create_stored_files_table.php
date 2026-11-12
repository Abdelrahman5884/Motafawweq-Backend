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
        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            
            // Entity association
            $table->string('entity_type', 60)->index();
            $table->string('entity_id', 100)->nullable()->index();
            
            // File metadata
            $table->string('original_filename', 255);
            $table->string('storage_disk', 40)->default('supabase');
            $table->string('storage_path', 500)->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->string('storage_provider', 40)->default('supabase');
            $table->string('upload_status', 30)->default('pending')->index(); // pending, completed, failed
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'entity_type']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stored_files');
    }
};

