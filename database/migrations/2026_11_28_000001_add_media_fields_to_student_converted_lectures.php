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
                if (! Schema::hasColumn('student_converted_lectures', 'media_disk')) {
                    $table->string('media_disk', 32)->nullable()->after('file_size');
                }
                if (! Schema::hasColumn('student_converted_lectures', 'media_path')) {
                    $table->string('media_path', 512)->nullable()->after('media_disk');
                }
                if (! Schema::hasColumn('student_converted_lectures', 'media_mime_type')) {
                    $table->string('media_mime_type', 120)->nullable()->after('media_path');
                }
                if (! Schema::hasColumn('student_converted_lectures', 'source_url')) {
                    $table->string('source_url', 500)->nullable()->after('media_mime_type');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('student_converted_lectures')) {
            Schema::table('student_converted_lectures', function (Blueprint $table) {
                foreach (['source_url', 'media_mime_type', 'media_path', 'media_disk'] as $col) {
                    if (Schema::hasColumn('student_converted_lectures', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
