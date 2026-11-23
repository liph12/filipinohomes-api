<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The MCP server (Claude reading FH's numbers, /mcp/filipinohomes, see docs/mcp.md).
//
// mcp_keys: a key belongs to one person and works on that endpoint only. It is a table of its own,
// not Sanctum's, so a key pasted into a connector can never sign in to the rest of the API. Only the
// SHA-256 of the key is stored.
//
// mcp_calls: one row for every tool a key ran, so what Claude was asked to do, by whom, is on record.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // The first characters of the key, to tell keys apart in a list. Never enough to use.
            $table->string('prefix', 16);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('mcp_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcp_key_id')->nullable()->constrained('mcp_keys')->nullOnDelete();
            // Kept even when the key row is gone, so the record outlives it.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('role', 16);
            $table->string('tool', 80);
            $table->json('arguments')->nullable();
            // ok | error | denied
            $table->string('outcome', 16);
            $table->string('error', 300)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedInteger('bytes')->default(0);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_calls');
        Schema::dropIfExists('mcp_keys');
    }
};
