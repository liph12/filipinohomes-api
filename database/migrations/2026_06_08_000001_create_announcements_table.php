<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-composed broadcasts (announcement / maintenance / custom) pushed
        // to a segment of the fleet. The per-recipient feed rows live in
        // app_notifications (linked via announcement_id); this table is the
        // single source record + send metadata used for the analytics screen.
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16); // 'announcement' | 'maintenance' | 'custom'
            $table->string('title');
            $table->text('body');
            // Optional deep-link / extra payload carried on the push.
            $table->json('data')->nullable();
            // Targeting at send time, e.g. { "scope": "platform", "platform": "ios" }.
            $table->json('audience')->nullable();
            // Snapshot of how many recipients were notified, set after fan-out.
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
