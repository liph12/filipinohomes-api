<?php

use App\Models\AgentReview;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The public "· edited N ago" label used to compare updated_at to created_at,
// but updated_at also moves on admin moderation (approve / hide), helpful
// votes and edit-window bumps — so an admin approving a 9-day-old review made
// it read "edited just now". edited_at moves ONLY when the reviewer changes
// what readers see (rating, tags, comment); AgentReview's saving hook keeps
// it current from here on.
return new class extends Migration
{
    private const CONTENT_FIELDS = ['overall_rating', 'tags', 'comment'];

    public function up(): void
    {
        Schema::table('agent_reviews', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('edit_window_ends_at');
        });

        $this->backfillFromAudits();
    }

    public function down(): void
    {
        Schema::table('agent_reviews', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
    }

    /**
     * Recover genuine past edits from the audit trail: the latest "updated"
     * audit whose new_values touched a content field. Rows with no such audit
     * stay null (never edited). Plain query-builder writes on purpose — the
     * model's saved hook would recompute rollups and purge agent caches for
     * every row.
     */
    private function backfillFromAudits(): void
    {
        if (!Schema::hasTable('audits')) {
            return;
        }

        $latest = [];
        DB::table('audits')
            ->where('auditable_type', (new AgentReview)->getMorphClass())
            ->where('event', 'updated')
            ->select(['id', 'auditable_id', 'new_values', 'created_at'])
            ->chunkById(500, function ($rows) use (&$latest) {
                foreach ($rows as $row) {
                    $new = json_decode((string) $row->new_values, true);
                    if (!is_array($new) || !array_intersect(self::CONTENT_FIELDS, array_keys($new))) {
                        continue;
                    }
                    $id = (int) $row->auditable_id;
                    if (!isset($latest[$id]) || $row->created_at > $latest[$id]) {
                        $latest[$id] = $row->created_at;
                    }
                }
            });

        foreach ($latest as $reviewId => $editedAt) {
            DB::table('agent_reviews')
                ->where('id', $reviewId)
                ->update(['edited_at' => $editedAt]);
        }
    }
};
