<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relax `messages.body` to nullable.
     *
     * The original messages table declared body as a NOT NULL text
     * column, but MessageController::store legitimately inserts a
     * null body when the user sends an image-only message (the
     * controller validates body as nullable, then normalizes empty
     * strings to null at line 56-58, and finally inserts null at
     * line 100). MySQL then rejects the insert with SQLSTATE[23000]
     * Integrity constraint violation: Column 'body' cannot be null.
     *
     * Reported on 2026-06-02: attaching an image with no caption
     * crashed the send. The controller logic already handles null,
     * so the fix is just to make the column match the controller's
     * contract.
     *
     * Forward-only — there are no existing null rows to worry about
     * (they couldn't have been inserted before this migration), so
     * the reverse would only matter if someone manually NULLed a row
     * post-migration.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->text('body')->nullable()->change();
        });
    }

    public function down(): void
    {
        //
    }
};
