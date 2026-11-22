<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homeworks', function (Blueprint $table) {
            if (! Schema::hasColumn('homeworks', 'attachment_disk')) {
                $table->string('attachment_disk', 30)->default('local')->after('attachment_path');
            }
            if (! Schema::hasColumn('homeworks', 'attachment_mime_type')) {
                $table->string('attachment_mime_type', 100)->nullable()->after('attachment_disk');
            }
            if (! Schema::hasColumn('homeworks', 'attachment_size_bytes')) {
                $table->unsignedBigInteger('attachment_size_bytes')->nullable()->after('attachment_mime_type');
            }
        });

        Schema::table('homework_submissions', function (Blueprint $table) {
            if (! Schema::hasColumn('homework_submissions', 'attachment_disk')) {
                $table->string('attachment_disk', 30)->default('local')->after('attachment_path');
            }
            if (! Schema::hasColumn('homework_submissions', 'attachment_mime_type')) {
                $table->string('attachment_mime_type', 100)->nullable()->after('attachment_disk');
            }
            if (! Schema::hasColumn('homework_submissions', 'attachment_size_bytes')) {
                $table->unsignedBigInteger('attachment_size_bytes')->nullable()->after('attachment_mime_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('homeworks', function (Blueprint $table) {
            $columns = array_filter(
                ['attachment_disk', 'attachment_mime_type', 'attachment_size_bytes'],
                fn (string $col) => Schema::hasColumn('homeworks', $col)
            );
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('homework_submissions', function (Blueprint $table) {
            $columns = array_filter(
                ['attachment_disk', 'attachment_mime_type', 'attachment_size_bytes'],
                fn (string $col) => Schema::hasColumn('homework_submissions', $col)
            );
            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
