<?php

use App\Enums\CertificateStatus;
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
        Schema::create('course_certificates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('certificate_number', 50)->unique()->index();
            $table->string('signature_hash', 64)->index();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();

            $table->string('student_name_ar', 150);
            $table->string('student_name_en', 150)->nullable();
            $table->string('student_email', 150)->nullable();
            $table->string('student_phone', 30)->nullable();

            $table->string('course_name_ar', 200);
            $table->string('course_name_en', 200)->nullable();
            $table->string('grade_ar', 100)->nullable();
            $table->decimal('grade_percent', 5, 2)->default(100.00);
            $table->string('honors_title_ar', 255);
            $table->string('honors_title_en', 255)->nullable();

            $table->date('issue_date');
            $table->string('status', 20)->default(CertificateStatus::VERIFIED->value)->index();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['teacher_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_certificates');
    }
};

