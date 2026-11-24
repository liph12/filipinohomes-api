<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sign-in for Claude (docs/mcp.md): a person connects their own Claude to their FH account.
//
// mcp_connections: one row for each connection (a person's Claude, signed in). It is not an mcp_keys
// row: keys keep their own limit, list and meaning. The role it was authorized for is kept, so a
// person whose role later differs has to connect again and see what they are now allowing.
//
// mcp_oauth_tokens: one row for each pair of tokens a connection has been given (access and refresh,
// only their SHA-256 kept). A refresh makes the next pair; the chain is how a repeated or stolen
// refresh token is told from a harmless retry.
//
// mcp_oauth_requests: a sign-in that has started (the handle the website is given), and once it is
// answered, the one-time code Claude trades for tokens.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // claude (the Claude apps) or claude-code (a program on someone's computer)
            $table->string('client_id', 32);
            $table->string('client_kind', 16);
            $table->string('role', 16);
            $table->string('resource', 255);
            $table->string('scope', 120)->default('fh:read');
            $table->timestamp('last_used_at')->nullable();
            // The end of the refresh token, pushed forward each time it is used.
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoked_reason', 40)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index('created_at');
        });

        Schema::create('mcp_oauth_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mcp_connection_id')->constrained('mcp_connections')->cascadeOnDelete();
            // The pair this one was made from by a refresh (null for the first).
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->char('access_hash', 64)->unique();
            $table->timestamp('access_expires_at');
            $table->char('refresh_hash', 64)->unique();
            $table->timestamp('refresh_expires_at');
            // First time either token of the pair did anything, and when the refresh was spent.
            $table->timestamp('used_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('mcp_oauth_requests', function (Blueprint $table) {
            $table->id();
            $table->char('handle_hash', 64)->unique();
            $table->string('client_id', 32);
            $table->string('redirect_uri', 512);
            $table->string('state', 1024)->nullable();
            $table->char('code_challenge', 43);
            $table->string('scope', 120)->default('fh:read');
            $table->string('resource', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role', 16)->nullable();
            // approved | denied
            $table->string('decision', 12)->nullable();
            $table->char('code_hash', 64)->nullable()->unique();
            $table->timestamp('code_expires_at')->nullable();
            $table->timestamp('code_used_at')->nullable();
            $table->foreignId('mcp_connection_id')->nullable()->constrained('mcp_connections')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('mcp_calls', function (Blueprint $table) {
            // A call made through a connection rather than a key. Kept when the connection is gone.
            $table->unsignedBigInteger('mcp_connection_id')->nullable()->after('mcp_key_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mcp_calls', function (Blueprint $table) {
            $table->dropColumn('mcp_connection_id');
        });

        Schema::dropIfExists('mcp_oauth_requests');
        Schema::dropIfExists('mcp_oauth_tokens');
        Schema::dropIfExists('mcp_connections');
    }
};
