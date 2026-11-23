<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-admin read state for the Contact Inbox. A row with read_at set = that admin has read the
// submission; a row with read_at null = they marked it unread. The old shared inquiries.read_at is
// kept as-is and only counts for submissions read before this table existed (for an admin with no row).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->constrained('inquiries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['inquiry_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_reads');
    }
};
