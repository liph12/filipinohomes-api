<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the Insights & Analytics queries on the activity tables (they
 * grow every day, so a full scan that is cheap today keeps getting slower).
 * Found by EXPLAIN-ing every query those endpoints run.
 *
 *  - audits (created_at, user_id): "who did something between these dates"
 *    — agent activity (listings/insights/agents/activity) and the dashboard
 *    pulse. The existing indexes all lead with event/category/user_id, so a
 *    plain date range read the whole audit log.
 *  - chats / favorites (created_at, user_id): the executive report's
 *    "active people in the period" union. chats only had (type, created_at);
 *    favorites had no date index at all.
 *  - login_logs (user_id, logged_in_at): "has this agent logged in since X",
 *    checked per conversation by the unanswered-inquiries list. The existing
 *    (logged_in_at, user_id) index leads with the date, so it can't serve a
 *    per-user lookup.
 *  - visits (visitor_id, created_at, user_id): client insights' "first visit
 *    per visitor" (GROUP BY visitor_id, MIN(created_at), MAX(user_id)) over all
 *    time — covering, so it is answered from the index alone. Data is untouched.
 *
 * Adding a secondary index is an online operation on InnoDB (no table lock).
 */
return new class extends Migration
{
    private const INDEXES = [
        'audits' => ['audits_created_at_user_id_index' => ['created_at', 'user_id']],
        'chats' => ['chats_created_at_user_id_index' => ['created_at', 'user_id']],
        'favorites' => ['favorites_created_at_user_id_index' => ['created_at', 'user_id']],
        'login_logs' => ['login_logs_user_id_logged_in_at_index' => ['user_id', 'logged_in_at']],
        'visits' => ['visits_visitor_first_cover_index' => ['visitor_id', 'created_at', 'user_id']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $name)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
