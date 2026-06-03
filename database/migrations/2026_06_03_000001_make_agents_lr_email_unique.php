<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Replace the plain index with a unique one so lr_email can be used
            // as a login identifier. MySQL allows multiple NULLs under a unique
            // index, so agents without an lr_email are unaffected.
            $table->dropIndex(['lr_email']);
            $table->unique('lr_email');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropUnique(['lr_email']);
            $table->index('lr_email');
        });
    }
};
