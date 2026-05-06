<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            if (!Schema::hasColumn('agents', 'median_first_response_seconds')) {
                $table->unsignedInteger('median_first_response_seconds')->nullable()->after('mobile_no');
            }
            if (!Schema::hasColumn('agents', 'within_1h_response_pct')) {
                $table->decimal('within_1h_response_pct', 5, 2)->nullable()->after('median_first_response_seconds');
            }
            if (!Schema::hasColumn('agents', 'unanswered_response_pct')) {
                $table->decimal('unanswered_response_pct', 5, 2)->nullable()->after('within_1h_response_pct');
            }
            if (!Schema::hasColumn('agents', 'response_sample_size')) {
                $table->unsignedInteger('response_sample_size')->nullable()->after('unanswered_response_pct');
            }
            if (!Schema::hasColumn('agents', 'response_metrics_window_days')) {
                $table->unsignedTinyInteger('response_metrics_window_days')->default(30)->after('response_sample_size');
            }
            if (!Schema::hasColumn('agents', 'response_metrics_updated_at')) {
                $table->timestamp('response_metrics_updated_at')->nullable()->after('response_metrics_window_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $columns = [
                'median_first_response_seconds',
                'within_1h_response_pct',
                'unanswered_response_pct',
                'response_sample_size',
                'response_metrics_window_days',
                'response_metrics_updated_at',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('agents', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
