<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `inquiries.message` was varchar(255) while both endpoints that write it
 * validate `max:5000` — a twenty-fold mismatch that silently cost real leads.
 *
 * Validation passed, the admin email went out, and then the INSERT was
 * rejected with SQLSTATE[22001] and the visitor got a 500. So the person who
 * wrote a detailed brief about what they wanted to buy was told the form
 * failed, and nothing about them reached the database.
 *
 * ⚠️ sendContactUs() makes the ceiling lower than it looks: it PREPENDS
 *    "Inquiry Type: …", "Subject: …" and "Phone: …" to the body before saving,
 *    so a ~180-character message was already enough to overflow. The longest
 *    inquiry anybody could file was roughly two sentences.
 *
 * TEXT (65,535 bytes) against a 5,000-character cap leaves room for the meta
 * header and for multi-byte characters — the lost submission used typographic
 * apostrophes, and those are 3 bytes each in utf8mb4.
 *
 * ⚠️ MySQL forbids a DEFAULT on TEXT, so the column's `default(null)` (which
 *    MySQL had resolved to NOT NULL with no default anyway) cannot be carried
 *    over. NOT NULL is kept — `message` is `required` in both validators.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->text('message')->change();
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            // ⚠️ Rolling back TRUNCATES anything longer than 255 characters,
            //    which is the whole point of the change. Only safe while no
            //    long message has been stored yet.
            $table->string('message')->change();
        });
    }
};
