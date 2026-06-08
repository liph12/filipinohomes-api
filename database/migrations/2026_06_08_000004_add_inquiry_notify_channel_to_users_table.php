<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user channel preference for listing-inquiry alerts: 'push' (default)
     * delivers via the mobile app push + in-app feed, 'email' via the inquiry
     * email instead. 'push' falls back to email automatically when the user has
     * no registered device (see User::prefersInquiryPush).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('inquiry_notify_channel', 16)->default('push')->after('verification');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('inquiry_notify_channel');
        });
    }
};
