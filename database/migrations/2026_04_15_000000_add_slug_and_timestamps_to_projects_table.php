<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'slug')) {
                $table->string('slug', 191)->nullable();
            }
            // Add timestamps if not present (append without relying on existing columns)
            if (!Schema::hasColumn('projects', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('projects', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
            // Soft deletes (like listings)
            if (!Schema::hasColumn('projects', 'deleted_at')) {
                $table->softDeletes();
            }
            if (!Schema::hasColumn('projects', 'deleted_by')) {
                $table->unsignedBigInteger('deleted_by')->nullable();
            }
            // Creator/Updater like listings (optional but requested)
            if (!Schema::hasColumn('projects', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable();
            }
            if (!Schema::hasColumn('projects', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable();
            }
        });

        // Backfill slug for existing rows
        $rows = DB::table('projects')->select('id', 'name')->orderBy('id')->get();

        $used = [];
        foreach ($rows as $row) {
            $base = Str::slug((string)($row->name ?? ''));
            if ($base === '') {
                $base = 'project-' . $row->id;
            }
            $slug = $base;
            $i = 1;
            // ensure uniqueness within this migration run and against DB
            while (in_array($slug, $used, true) || DB::table('projects')->where('slug', $slug)->where('id', '!=', $row->id)->exists()) {
                $i++;
                $slug = $base . '-' . $i;
            }
            $used[] = $slug;
            DB::table('projects')->where('id', $row->id)->update([
                'slug' => $slug,
                // initialize timestamps if null
                'created_at' => DB::raw('COALESCE(created_at, NOW())'),
                'updated_at' => DB::raw('COALESCE(updated_at, NOW())'),
            ]);
        }

        // Add a unique index on slug (best-effort; ignore if it already exists)
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'slug')) return; // safety
            try {
                $table->unique('slug', 'projects_slug_unique');
            } catch (\Throwable $e) {
                // ignore if already exists or platform doesn't support in this context
            }
        });

        // Make 'devid' column nullable without requiring Doctrine DBAL
        if (Schema::hasColumn('projects', 'devid')) {
            try {
                $col = DB::selectOne("SELECT DATA_TYPE AS dt, CHARACTER_MAXIMUM_LENGTH AS len FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'devid'");
                if ($col) {
                    $type = strtolower($col->dt ?? 'varchar');
                    $len  = $col->len ? (int) $col->len : 255;
                    $definition = in_array($type, ['varchar','char']) ? strtoupper($type)."(".$len.")" : strtoupper($type);
                    DB::statement("ALTER TABLE projects MODIFY devid $definition NULL");
                } else {
                    // Fallback: assume VARCHAR(255)
                    DB::statement("ALTER TABLE projects MODIFY devid VARCHAR(255) NULL");
                }
            } catch (\Throwable $e) {
                // swallow to keep migration resilient across platforms
            }
        }
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'slug')) {
                try { $table->dropUnique('projects_slug_unique'); } catch (\Throwable $e) {}
                $table->dropColumn('slug');
            }
            if (Schema::hasColumn('projects', 'created_at')) {
                $table->dropColumn('created_at');
            }
            if (Schema::hasColumn('projects', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
            if (Schema::hasColumn('projects', 'deleted_at')) {
                $table->dropColumn('deleted_at');
            }
            if (Schema::hasColumn('projects', 'deleted_by')) {
                $table->dropColumn('deleted_by');
            }
            if (Schema::hasColumn('projects', 'created_by')) {
                $table->dropColumn('created_by');
            }
            if (Schema::hasColumn('projects', 'updated_by')) {
                $table->dropColumn('updated_by');
            }
        });

        // Revert 'devid' back to NOT NULL (best-effort; matches current type/length)
        if (Schema::hasColumn('projects', 'devid')) {
            try {
                $col = DB::selectOne("SELECT DATA_TYPE AS dt, CHARACTER_MAXIMUM_LENGTH AS len FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'devid'");
                if ($col) {
                    $type = strtolower($col->dt ?? 'varchar');
                    $len  = $col->len ? (int) $col->len : 255;
                    $definition = in_array($type, ['varchar','char']) ? strtoupper($type)."(".$len.")" : strtoupper($type);
                    DB::statement("ALTER TABLE projects MODIFY devid $definition NOT NULL");
                }
            } catch (\Throwable $e) {
                // ignore in down if unsupported
            }
        }
    }
};
