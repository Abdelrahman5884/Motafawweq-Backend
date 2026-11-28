<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('student_converted_lectures')) {
            Schema::table('student_converted_lectures', function (Blueprint $table) {
                if (! Schema::hasColumn('student_converted_lectures', 'attachments')) {
                    $table->json('attachments')->nullable()->after('outputs');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('student_converted_lectures') && Schema::hasColumn('student_converted_lectures', 'attachments')) {
            Schema::table('student_converted_lectures', function (Blueprint $table) {
                $table->dropColumn('attachments');
            });
        }
    }
};
