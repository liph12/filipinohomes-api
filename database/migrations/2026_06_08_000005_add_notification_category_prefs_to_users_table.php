<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user push category toggles, enforced server-side in ExpoPushService.
     * When a toggle is off the push is muted but the in-app feed row is still
     * recorded. Default true so existing users keep all notifications.
     *   - notify_new_inquiry      → chat messages + listing-inquiry reviews
     *   - notify_listing_verified → listing audit outcomes (verified / flagged)
     *   - notify_status_change    → listing marked sold / rented / leased
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_new_inquiry')->default(true)->after('inquiry_notify_channel');
            $table->boolean('notify_listing_verified')->default(true)->after('notify_new_inquiry');
            $table->boolean('notify_status_change')->default(true)->after('notify_listing_verified');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notify_new_inquiry', 'notify_listing_verified', 'notify_status_change']);
        });
    }
};
