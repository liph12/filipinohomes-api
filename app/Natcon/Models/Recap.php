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
     * "refused to connect" rather than a video. Normalising here means the admin
     * can paste what they have and the page still plays it.
     *
     * ─── Why Facebook is supported as well as YouTube ───────────────────────
     *
     * The conventions run back to 2012 and the early recaps were only ever
     * published to the official Facebook pages — 2016, 2017 and 2018 are there
     * and nowhere else. Embedding them needs no upload access to anything,
     * which matters: the brand's own YouTube channel is not reachable by the
     * people maintaining this page.
     *
     * ⚠️ Only these two hosts, matched on the parsed HOST and not by substring.
     *    `str_contains($url, 'facebook.com')` would happily turn
     *    `https://evil.example/?x=facebook.com` into an embed of a domain we do
     *    not control, inside an iframe on an indexed page.
     *
     * ⚠️ The output hosts must stay inside the frontend's CSP `frame-src`, which
     *    today allows youtube-nocookie.com and www.facebook.com. A host added
     *    here without the matching CSP entry renders an empty box in production
     *    and nothing at all in local dev.
     *
     * Returns null for anything else — a direct MP4, say — which the frontend
     * renders in a <video> tag instead.
     */
    public function embedUrl(): ?string
    {
        $url = trim((string) $this->video_url);

        if ($url === '') {
            return null;
        }

        if (preg_match('~youtu\.be/([A-Za-z0-9_-]{6,})~', $url, $m)
            || preg_match('~youtube\.com/(?:watch\?v=|embed/|shorts/|live/)([A-Za-z0-9_-]{6,})~', $url, $m)) {
            // No autoplay: a modal that starts making noise on open is hostile,
            // and iOS blocks it anyway.
            return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host !== '' && preg_match('~(^|\.)(facebook\.com|fb\.watch)$~', $host)) {
            // The plugin takes the whole post URL rather than an id, so there is
            // nothing to extract — but the subdomain has to be normalised:
            // m.facebook.com serves a mobile page inside the frame, and
            // web./business. are not in the CSP even though they resolve.
            $canonical = preg_replace(
                '~^https?://(?:web|m|mobile|business|[a-z]{2}-[a-z]{2})\.facebook\.com~i',
                'https://www.facebook.com',
                $url,
            );

            // show_text=false keeps the post's caption out of the frame; the
            // dialog already shows the title and the year.
            return 'https://www.facebook.com/plugins/video.php?href='
                . rawurlencode($canonical)
                . '&show_text=false';
        }

        return null;
    }
}
