<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->enum('verification_status', ['verified', 'fully_verified', 'flagged'])
                  ->nullable()->after('is_featured');
            $table->text('audit_notes')->nullable()->after('verification_status');
            $table->json('audit_checklist')->nullable()->after('audit_notes');
            $table->foreignId('audited_by')->nullable()->constrained('users')->nullOnDelete()->after('audit_checklist');
            $table->timestamp('audited_at')->nullable()->after('audited_by');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropForeign(['audited_by']);
            $table->dropColumn(['verification_status', 'audit_notes', 'audit_checklist', 'audited_by', 'audited_at']);
        });
    }
};
