<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The old `description` column always held the agent's About Me text — name
// it that, and free `description` up for what it should be: the short copy
// under the page title. `tagline` becomes `heading` (it titles the About
// section on the agent websites).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_builder', function (Blueprint $table) {
            $table->renameColumn('description', 'about_me');
            $table->renameColumn('tagline', 'heading');
        });

        Schema::table('page_builder', function (Blueprint $table) {
            $table->text('description')->nullable()->after('seo_tags');
        });
    }

    public function down(): void
    {
        Schema::table('page_builder', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('page_builder', function (Blueprint $table) {
            $table->renameColumn('about_me', 'description');
            $table->renameColumn('heading', 'tagline');
        });
    }
};
