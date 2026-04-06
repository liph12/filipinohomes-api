<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('page_builder', function (Blueprint $table) {
            if (!Schema::hasColumn('page_builder', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('page_builder', function (Blueprint $table) {
            if (Schema::hasColumn('page_builder', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
