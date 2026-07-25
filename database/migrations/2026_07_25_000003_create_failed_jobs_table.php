<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard Laravel failed-jobs table, previously missing from this app even
 * though QUEUE_CONNECTION=database. Without it, any job the worker marks
 * failed (timeout kill, max attempts exceeded after a mid-reservation crash)
 * throws a QueryException from the failure recorder itself — noisy for every
 * queued job (mail, IndexNow, SEO computes), and it suppressed failed()
 * hooks' bookkeeping context. Guarded with hasTable in case an environment
 * created it out-of-band.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('failed_jobs')) {
            return;
        }

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};
