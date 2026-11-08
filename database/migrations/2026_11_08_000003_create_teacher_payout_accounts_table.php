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
        Schema::create('teacher_payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();

            // instapay | vodafone | cib | card
            $table->string('type', 30)->index();
            $table->string('label_ar', 150);
            $table->string('label_en', 150);

            // Encrypted at rest using Laravel's 'encrypted' cast (AES-256-CBC)
            $table->text('account_identifier');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['teacher_id', 'is_default']);
            $table->index(['teacher_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teacher_payout_accounts');
    }
};

