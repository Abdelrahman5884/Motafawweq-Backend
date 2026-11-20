<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('republic_leagues', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('started_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('title_ar', 250)->default('دوري الجمهورية العام');
            $table->string('duration_type', 30)->default('monthly');
            $table->unsignedSmallInteger('duration_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('republic_leagues');
    }
};
