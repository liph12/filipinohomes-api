<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiry_replies', function (Blueprint $table) {
            // Array of already-hosted S3 image URLs rendered inline in the
            // reply email. Stored as JSON, cast to array on the model.
            $table->json('attachments')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('inquiry_replies', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }
};
