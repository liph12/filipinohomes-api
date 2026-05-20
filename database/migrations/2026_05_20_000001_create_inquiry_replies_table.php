<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')
                ->constrained('inquiries')
                ->cascadeOnDelete();
            $table->foreignId('admin_user_id')
                ->constrained('users');
            $table->string('subject', 255)->nullable();
            $table->text('body');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['inquiry_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_replies');
    }
};
