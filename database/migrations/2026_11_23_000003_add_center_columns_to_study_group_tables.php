<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_groups', function (Blueprint $table) {
            $table->foreignId('center_id')
                ->nullable()
                ->after('teacher_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('center_branch_id')
                ->nullable()
                ->after('center_id')
                ->constrained('center_branches')
                ->nullOnDelete();
            $table->foreignId('center_room_id')
                ->nullable()
                ->after('center_branch_id')
                ->constrained('center_rooms')
                ->nullOnDelete();
            $table->foreignId('center_teacher_id')
                ->nullable()
                ->after('center_room_id')
                ->constrained('center_teachers')
                ->nullOnDelete();
            $table->string('teacher_name_override', 150)
                ->nullable()
                ->after('center_teacher_id');

            $table->index(['center_id', 'status', 'deleted_at'], 'study_groups_center_status_idx');
            $table->index(['center_id', 'center_branch_id'], 'study_groups_center_branch_idx');
        });

        Schema::table('group_schedule_slots', function (Blueprint $table) {
            $table->foreignId('center_room_id')
                ->nullable()
                ->after('hall_name')
                ->constrained('center_rooms')
                ->nullOnDelete();

            $table->index(['center_room_id', 'day_of_week'], 'slots_room_day_idx');
        });

        Schema::table('group_enrollments', function (Blueprint $table) {
            $table->string('payment_status', 20)->default('paid')->after('academic_status'); // paid | partial | due
            $table->decimal('due_amount_egp', 10, 2)->default(0.00)->after('payment_status');
            $table->date('payment_due_since')->nullable()->after('due_amount_egp');
            $table->timestamp('last_payment_reminder_at')->nullable()->after('payment_due_since');

            $table->index(['study_group_id', 'payment_status'], 'enrollments_group_payment_idx');
        });

        Schema::table('group_attendance_sessions', function (Blueprint $table) {
            $table->foreignId('center_id')
                ->nullable()
                ->after('teacher_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('center_room_id')
                ->nullable()
                ->after('schedule_slot_id')
                ->constrained('center_rooms')
                ->nullOnDelete();

            $table->index(['center_id', 'session_date'], 'attendance_sessions_center_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('group_attendance_sessions', function (Blueprint $table) {
            $table->dropIndex('attendance_sessions_center_date_idx');
            $table->dropConstrainedForeignId('center_room_id');
            $table->dropConstrainedForeignId('center_id');
        });

        Schema::table('group_enrollments', function (Blueprint $table) {
            $table->dropIndex('enrollments_group_payment_idx');
            $table->dropColumn([
                'payment_status',
                'due_amount_egp',
                'payment_due_since',
                'last_payment_reminder_at',
            ]);
        });

        Schema::table('group_schedule_slots', function (Blueprint $table) {
            $table->dropIndex('slots_room_day_idx');
            $table->dropConstrainedForeignId('center_room_id');
        });

        Schema::table('study_groups', function (Blueprint $table) {
            $table->dropIndex('study_groups_center_branch_idx');
            $table->dropIndex('study_groups_center_status_idx');
            $table->dropColumn('teacher_name_override');
            $table->dropConstrainedForeignId('center_teacher_id');
            $table->dropConstrainedForeignId('center_room_id');
            $table->dropConstrainedForeignId('center_branch_id');
            $table->dropConstrainedForeignId('center_id');
        });
    }
};
