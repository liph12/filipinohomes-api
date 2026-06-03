<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app notification feed for the mobile app. Named app_notifications
        // (not notifications) to avoid clashing with Laravel's Notifiable
        // trait, which reserves the `notifications` table/relation shape.
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 32); // 'inquiry' | 'listing_flagged'
            $table->string('title');
            $table->text('body')->nullable();
            // Deep-link payload, e.g. { "type": "inquiry", "id": 123 }.
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
