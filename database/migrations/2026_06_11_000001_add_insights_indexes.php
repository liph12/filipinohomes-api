<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes for the admin Listing Insights endpoints.
 *
 *  - properties.has_ats_files: a stored generated boolean (1 when the listing
 *    carries any ATS photo/document) so the "with attachments" filter becomes
 *    index-backed instead of a per-row JSON_LENGTH scan.
 *  - properties.ats_status: speeds the drill-down ATS-status filter.
 *  - listings.created_at: speeds the date-range filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('properties', 'has_ats_files')) {
            // Raw DDL — Laravel's storedAs JSON path quoting is finicky across
            // MySQL/MariaDB, so define the generated column explicitly.
            DB::statement(<<<'SQL'
                ALTER TABLE properties
                ADD COLUMN has_ats_files TINYINT(1)
                AS (
                    CASE WHEN ats_attachments IS NOT NULL
                         AND (
                            COALESCE(JSON_LENGTH(JSON_EXTRACT(ats_attachments, '$.photos')), 0) > 0
                            OR COALESCE(JSON_LENGTH(JSON_EXTRACT(ats_attachments, '$.documents')), 0) > 0
                         )
                    THEN 1 ELSE 0 END
                ) STORED
            SQL);
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->index('has_ats_files');
            $table->index('ats_status');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['ats_status']);
            $table->dropIndex(['has_ats_files']);
        });

        if (Schema::hasColumn('properties', 'has_ats_files')) {
            DB::statement('ALTER TABLE properties DROP COLUMN has_ats_files');
        }
    }
};
