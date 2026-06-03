<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('lr_email')->nullable()->after('user_id')->index();
            $table->date('birthdate')->nullable()->after('lr_email');
            $table->string('gender')->nullable()->after('birthdate');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex(['lr_email']);
            $table->dropColumn(['lr_email', 'birthdate', 'gender']);
        });
    }
};
