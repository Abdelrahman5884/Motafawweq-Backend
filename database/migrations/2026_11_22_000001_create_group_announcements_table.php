<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('study_group_id')
                ->constrained('study_groups')
                ->cascadeOnDelete();
            $table->string('type', 32)->default('announcement');
            $table->text('message');
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamps();

            $table->index(['study_group_id', 'created_at']);
            $table->index(['teacher_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_announcements');
    }
};
