<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_analytics_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->date('metric_date');
            $table->unsignedInteger('active_students_count')->default(0);
            $table->decimal('attendance_avg', 5, 2)->default(0.00);
            $table->decimal('quiz_avg', 5, 2)->default(0.00);
            $table->unsignedInteger('at_risk_count')->default(0);
            $table->decimal('revenue_total', 10, 2)->default(0.00);
            $table->json('snapshots_json')->nullable();

            $table->timestamps();

            $table->index(['teacher_id', 'metric_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_analytics_snapshots');
    }
};

