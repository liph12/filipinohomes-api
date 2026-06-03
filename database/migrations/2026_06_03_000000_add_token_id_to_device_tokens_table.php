<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            // Links a push registration to the session (personal_access_token)
            // that created it, so revoking a session from "active devices"
            // also stops pushes to that device. Nullable + nullOnDelete: a
            // pruned/expired token just leaves the row unlinked, never orphaned.
            $table->foreignId('personal_access_token_id')
                ->nullable()
                ->after('user_id')
                ->constrained('personal_access_tokens')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('personal_access_token_id');
        });
    }
};
