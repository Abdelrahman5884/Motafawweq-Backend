<?php

use App\Enums\VerificationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('admin_details_ar', 255)->nullable()->after('avatar');
            $table->string('admin_details_en', 255)->nullable()->after('admin_details_ar');
            $table->text('national_id_encrypted')->nullable()->after('admin_details_en');
            $table->string('national_id_last4', 8)->nullable()->after('national_id_encrypted');
            $table->string('national_id_hash', 64)->nullable()->index()->after('national_id_last4');
        });

        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->string('degree_ar', 255)->nullable()->after('bio_en');
            $table->string('degree_en', 255)->nullable()->after('degree_ar');
            $table->string('background_check_status', 30)->default('clean')->after('degree_en');
            $table->string('verification_status', 30)
                ->default(VerificationStatus::VERIFIED->value)
                ->index()
                ->after('is_verified_educator');
            $table->text('verification_notes')->nullable()->after('verification_status');
            $table->timestamp('verified_at')->nullable()->after('verification_notes');
            $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->index()->after('status');
            $table->decimal('rating', 3, 2)->default(5.00)->after('is_featured');
            $table->text('moderation_notes')->nullable()->after('rating');
            $table->foreignId('moderated_by')->nullable()->after('moderation_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable()->after('moderated_by');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex(['is_featured']);
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropColumn([
                'is_featured',
                'rating',
                'moderation_notes',
                'moderated_at',
            ]);
        });

        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropIndex(['verification_status']);
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn([
                'degree_ar',
                'degree_en',
                'background_check_status',
                'verification_status',
                'verification_notes',
                'verified_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['national_id_hash']);
            $table->dropColumn([
                'admin_details_ar',
                'admin_details_en',
                'national_id_encrypted',
                'national_id_last4',
                'national_id_hash',
            ]);
        });
    }
};
