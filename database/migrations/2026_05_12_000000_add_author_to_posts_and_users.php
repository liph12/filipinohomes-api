<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the columns needed to attach a named human author (Person) to
 * every blog post. The frontend generateBlogJsonLd currently emits
 * `author` as `@type: Organization`, which Google's Sept 2025 QRG
 * treats as an E-E-A-T weakness for YMYL content (estate / financing
 * / legal guides on the blog). After this migration the API can
 * return a populated `author` relation and the frontend flips to
 * `@type: Person` with bio + credentials + sameAs.
 *
 * Two-table change kept in one migration so rollback is atomic:
 *
 *   users:
 *     + bio          TEXT      NULL  – short author biography
 *     + slug         VARCHAR(160) NULL UNIQUE – author profile URL slug
 *     + credentials  VARCHAR(255) NULL – PRC license, broker number,
 *                                        bar / CPA designations etc.
 *
 *   posts:
 *     + author_id    BIGINT UNSIGNED NULL  FK → users(id)
 *                    nullSafe + ON DELETE SET NULL so deleting a staff
 *                    writer keeps their posts indexed instead of
 *                    cascading and breaking historical URLs.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'bio')) {
                $table->text('bio')->nullable()->after('avatar');
            }
            if (!Schema::hasColumn('users', 'slug')) {
                $table->string('slug', 160)->nullable()->unique()->after('bio');
            }
            if (!Schema::hasColumn('users', 'credentials')) {
                $table->string('credentials', 255)->nullable()->after('slug');
            }
        });

        Schema::table('posts', function (Blueprint $table) {
            if (!Schema::hasColumn('posts', 'author_id')) {
                $table->foreignId('author_id')
                    ->nullable()
                    ->after('category_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            if (Schema::hasColumn('posts', 'author_id')) {
                $table->dropConstrainedForeignId('author_id');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'credentials')) {
                $table->dropColumn('credentials');
            }
            if (Schema::hasColumn('users', 'slug')) {
                $table->dropUnique(['slug']);
                $table->dropColumn('slug');
            }
            if (Schema::hasColumn('users', 'bio')) {
                $table->dropColumn('bio');
            }
        });
    }
};
