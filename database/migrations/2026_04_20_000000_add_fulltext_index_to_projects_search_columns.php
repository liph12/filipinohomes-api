<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'projects_name_complete_address_fulltext';

    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->fullText(['name', 'complete_address'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropFullText(self::INDEX_NAME);
        });
    }
};
