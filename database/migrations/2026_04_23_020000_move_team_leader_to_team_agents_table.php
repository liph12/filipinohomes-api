<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('team_agents', function (Blueprint $table) {
            $table->boolean('is_leader')->default(false)->after('agent_id');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['leader_id']);
            $table->dropColumn('leader_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('leader_id')
                ->nullable()
                ->after('name')
                ->constrained('agents')
                ->restrictOnDelete();
        });

        Schema::table('team_agents', function (Blueprint $table) {
            $table->dropColumn('is_leader');
        });
    }
};
