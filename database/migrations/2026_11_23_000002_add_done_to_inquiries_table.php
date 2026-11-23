<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Contact Inbox "Mark as done": shared by all admins (a submission is handled or it isn't),
// with who marked it and when.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->timestamp('done_at')->nullable()->after('read_at');
            $table->foreignId('done_by')->nullable()->after('done_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('done_by');
            $table->dropColumn('done_at');
        });
    }
};
