<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buyers & Sellers Link: the link belongs to the signed-in USER, not only to
 * an agents row, so an admin has one too. `agent_id` stays as the owner's
 * agent row when they have one (names, photos and the agent's lists come
 * from it) and becomes nullable for admins who are not agents. Existing
 * rows get their user from the agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brief_links', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
        });
        Schema::table('brief_links', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_id')->nullable()->change();
        });

        Schema::table('client_briefs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('agent_id')->constrained('users')->nullOnDelete();
            $table->index(['user_id', 'kind', 'created_at']);
        });
        Schema::table('client_briefs', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_id')->nullable()->change();
        });

        foreach (['brief_links', 'client_briefs'] as $tableName) {
            DB::table($tableName)
                ->whereNull('user_id')
                ->whereNotNull('agent_id')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($tableName) {
                    $users = DB::table('agents')->whereIn('id', $rows->pluck('agent_id'))->pluck('user_id', 'id');
                    foreach ($rows as $row) {
                        $userId = $users[$row->agent_id] ?? null;
                        if ($userId) {
                            DB::table($tableName)->where('id', $row->id)->update(['user_id' => $userId]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('client_briefs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'kind', 'created_at']);
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('brief_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
