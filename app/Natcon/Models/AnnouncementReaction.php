<?php

namespace App\Natcon\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One visitor's reaction to one announcement.
 *
 * Not Auditable, unlike the rest of the module. An audit row per tap would bury
 * the genuinely interesting NATCON entries — sends, photo reviews, slug changes —
 * under thousands of anonymous clicks. The row itself is the record.
 */
class AnnouncementReaction extends Model
{
    // The class name would infer `announcement_reactions`.
    protected $table = 'natcon_announcement_reactions';

    /**
     * The reaction set, in display order.
     *
     * ⚠️ Deliberately all positive. The Facebook set this mirrors includes Sad
     *    and Angry; this page is public, indexed, and sells seats to a
     *    convention, so a one-tap way to put a visible negative number on the
     *    organisers' own announcements was declined. Care and Congrats take
     *    those two slots.
     *
     * Keys are stored, not emoji — the glyphs live on the frontend so changing
     * artwork never needs a data migration. Adding a key here is safe; renaming
     * one orphans every stored row.
     */
    public const KEYS = ['like', 'love', 'care', 'haha', 'wow', 'celebrate', 'congrats'];

    protected $fillable = [
        'natcon_announcement_id', 'visitor_id', 'reaction', 'ip',
    ];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(NatconAnnouncement::class, 'natcon_announcement_id');
    }

    /**
     * Counts per reaction key for a set of announcements, in one query.
     *
     * Shaped as [announcement_id => ['like' => 3, 'love' => 5]] so the feed can
     * be rendered without a query per post. Announcements with no reactions are
     * simply absent — the caller supplies the zero, because sending a full set
     * of zeroes for every key on every post is mostly payload.
     *
     * @param  array<int>  $announcementIds
     * @return array<int, array<string, int>>
     */
    public static function tallyFor(array $announcementIds): array
    {
        if ($announcementIds === []) {
            return [];
        }

        $rows = static::query()
            ->selectRaw('natcon_announcement_id, reaction, COUNT(*) as total')
            ->whereIn('natcon_announcement_id', $announcementIds)
            ->groupBy('natcon_announcement_id', 'reaction')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->natcon_announcement_id][(string) $row->reaction] = (int) $row->total;
        }

        return $out;
    }
}
