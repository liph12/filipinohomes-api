<?php

namespace App\Natcon\Models;

use App\Auditing\LogsActivity;
use App\Models\GalleryAlbum;
use App\Models\GalleryPhoto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A photographer's upload invite: the DB half of a tokenized link that lets a
 * hired photographer create albums and upload photos into one gallery scope
 * WITHOUT an account. Token mechanics live in GalleryInviteService and mirror
 * Recipient's (derived HMAC, nonce rotation revokes every outstanding link).
 *
 * Lives in the Natcon module namespace — invites are minted from the NATCON
 * admin and share natcon.link_secret — but points at the renamed shared
 * gallery tables, so a public-scope (/albums) invite needs no schema change.
 */
class GalleryUploadInvite extends Model implements Auditable
{
    use LogsActivity;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'gallery_upload_invites';

    protected string $auditCategory = 'gallery';

    protected array $auditLabelAttributes = ['label'];

    /**
     * Token fields are deliberately NOT fillable — they are set via forceFill
     * by GalleryInviteService only, exactly like Recipient's.
     */
    protected $fillable = [
        'natcon_event_id', 'root_album_id', 'label', 'status',
        'review_required', 'created_by',
    ];

    protected $hidden = ['invite_token_hash', 'token_nonce'];

    protected $casts = [
        'review_required' => 'boolean',
        'token_issued_at' => 'datetime',
        'token_expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(NatconEvent::class, 'natcon_event_id');
    }

    public function rootAlbum(): BelongsTo
    {
        return $this->belongsTo(GalleryAlbum::class, 'root_album_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(GalleryPhoto::class, 'upload_invite_id');
    }

    public function albums(): HasMany
    {
        return $this->hasMany(GalleryAlbum::class, 'upload_invite_id');
    }

    /** Same scope rule as the gallery tables: null event = the public gallery. */
    public function scopeForEvent(Builder $query, ?NatconEvent $event): Builder
    {
        return $event
            ? $query->where('natcon_event_id', $event->id)
            : $query->whereNull('natcon_event_id');
    }
}
