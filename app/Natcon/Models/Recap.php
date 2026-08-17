<?php

namespace App\Natcon\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A recording of one past convention.
 *
 * Not tied to natcon_events on purpose — the conventions run back to 2012 and
 * none of those years has an event row. See the migration for why inventing them
 * would be the wrong trade.
 */
class Recap extends Model implements Auditable
{
    use LogsActivity;

    // The class name drops the module prefix, so Eloquent would infer `recaps`.
    protected $table = 'natcon_recaps';

    protected string $auditCategory = 'natcon';
    protected array $auditLabelAttributes = ['title'];

    protected $fillable = [
        'year', 'title', 'video_url', 'thumbnail_url',
        'is_published', 'sort_order', 'created_by',
    ];

    protected $casts = [
        'year'         => 'integer',
        'is_published' => 'boolean',
        'sort_order'   => 'integer',
    ];

    /**
     * Newest convention first, unless someone has hand-ordered the list.
     *
     * sort_order defaults to 0 for every row, so until anyone touches it this is
     * simply year-descending — which is what the sketch shows.
     */
    public function scopeLive($query)
    {
        return $query->where('is_published', true)
            ->orderByDesc('sort_order')
            ->orderByDesc('year');
    }

    /**
     * The embeddable form of whatever URL was pasted in.
     *
     * Editors paste what is in their address bar — a watch link, a share link,
     * sometimes a full embed — and an <iframe> pointed at a watch URL renders
     * YouTube's "refused to connect" box rather than a video. Normalising here
     * means the admin can paste anything and the page still plays it.
     *
     * Returns null for anything that is not YouTube, which the frontend renders
     * in a <video> tag instead.
     */
    public function youtubeEmbedUrl(): ?string
    {
        $url = trim((string) $this->video_url);

        if (preg_match('~youtu\.be/([A-Za-z0-9_-]{6,})~', $url, $m)
            || preg_match('~youtube\.com/(?:watch\?v=|embed/|shorts/|live/)([A-Za-z0-9_-]{6,})~', $url, $m)) {
            // No autoplay: a modal that starts making noise on open is hostile,
            // and iOS blocks it anyway.
            return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0';
        }

        return null;
    }
}
