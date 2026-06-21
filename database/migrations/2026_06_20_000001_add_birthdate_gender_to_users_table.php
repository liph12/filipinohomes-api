<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Client demographics. Agents already store these on the
            // `agents` table; clients are plain `users` rows (role
            // 'client') with no agents row, so demographics live here.
            // Both nullable — existing rows are unaffected and the stats
            // surface a "Not provided" bucket until a client fills them.
            $table->date('birthdate')->nullable()->after('mobile_no');
            $table->string('gender')->nullable()->after('birthdate');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['birthdate', 'gender']);
        });
    }
};
