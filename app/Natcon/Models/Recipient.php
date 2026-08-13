<?php

namespace App\Natcon\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class Recipient extends Model implements Auditable
{
    // The class name drops the module prefix, so Eloquent would otherwise
    // infer `recipients` from it. The table keeps the natcon_ prefix because it
    // shares a schema with the rest of the product.
    protected $table = 'natcon_recipients';

    use SoftDeletes;
    use LogsActivity;

    protected string $auditCategory = 'natcon';
    protected array $auditLabelAttributes = ['email'];

    // Status is a string column with constants rather than a DB enum. The codebase
    // already carries a migration that exists ONLY because someone had to
    // ALTER TABLE ... MODIFY ENUM in raw SQL; adding a status in Phase 2 should be
    // a zero-migration change.
    public const STATUS_PENDING          = 'pending';
    public const STATUS_QUEUED           = 'queued';
    public const STATUS_INVITED          = 'invited';
    public const STATUS_REMINDED         = 'reminded';
    public const STATUS_RESPONDED_RETAIN = 'responded_retain';
    public const STATUS_RESPONDED_CHANGE = 'responded_change';
    public const STATUS_PHOTO_UPLOADED   = 'photo_uploaded';
    public const STATUS_COMPLETED        = 'completed';
    public const STATUS_FAILED           = 'failed';
    public const STATUS_EXCLUDED         = 'excluded';

    public const RESPONSE_RETAIN = 'retain';
    public const RESPONSE_CHANGE = 'change';

    public const LR_PENDING   = 'pending';
    public const LR_FOUND     = 'found';
    public const LR_NOT_FOUND = 'not_found';
    public const LR_ERROR     = 'error';

    /** Statuses that are still eligible for a reminder. */
    public const REMINDABLE = [self::STATUS_INVITED, self::STATUS_REMINDED];

    protected $fillable = [
        'natcon_event_id', 'email',
        'lr_awardee_id', 'reg_id', 'first_name', 'last_name', 'phone', 'team',
        'owner_name', 'seat_number', 'lr_polo_shirt_size', 'lr_approved',
        'lr_photos', 'lr_primary_photo', 'lr_qr_code', 'lr_payload',
        'lr_fetched_at', 'lr_lookup_status', 'lr_last_error',
        'source', 'imported_batch_id', 'created_by',
        'status', 'notes',
    ];

    protected $casts = [
        'lr_photos'         => 'array',
        'lr_payload'        => 'array',
        'lr_approved'       => 'boolean',
        'lr_fetched_at'     => 'datetime',
        'token_issued_at'   => 'datetime',
        'token_expires_at'  => 'datetime',
        'invited_at'        => 'datetime',
        'last_reminded_at'  => 'datetime',
        'first_opened_at'   => 'datetime',
        'responded_at'      => 'datetime',
        'photo_uploaded_at' => 'datetime',
        'form_submitted_at' => 'datetime',
    ];

    // The invite token hash is a credential-adjacent value. Keep it out of any
    // accidental toArray()/JSON serialization.
    protected $hidden = ['invite_token_hash'];

    /**
     * Normalize at the MODEL level, not just in the controller. The unique index
     * on (event, email) won't catch John@x.com vs john@x.com when the two arrive
     * through different code paths — manual add, paste import, and the eventual
     * LR bulk sync are three such paths.
     */
    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = strtolower(trim((string) $value));
    }

    public function event()
    {
        return $this->belongsTo(NatconEvent::class, 'natcon_event_id');
    }

    // Every relationship below states its foreign key explicitly. hasMany/hasOne
    // derive the key from the PARENT class name, and this class dropped the
    // module prefix — so Eloquent would look for `recipient_id` instead of
    // `natcon_recipient_id` and fail at runtime, not at boot.
    public function photoSubmissions()
    {
        return $this->hasMany(PhotoSubmission::class, 'natcon_recipient_id');
    }

    public function activePhoto()
    {
        return $this->hasOne(PhotoSubmission::class, 'natcon_recipient_id')
            ->where('status', PhotoSubmission::STATUS_ACTIVE)
            ->latestOfMany();
    }

    public function sends()
    {
        return $this->hasMany(Outbox::class, 'natcon_recipient_id');
    }

    public function formSubmission()
    {
        return $this->hasOne(FormSubmission::class, 'natcon_recipient_id');
    }

    public function displayName(): string
    {
        $name = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));

        return $name !== '' ? $name : ($this->owner_name ?: $this->email);
    }

    public function hasResponded(): bool
    {
        return $this->responded_at !== null;
    }

    /**
     * LR photos to show the awardee. `photo` is normally photos[0], so dedupe —
     * showing the same face twice reads as a bug.
     */
    public function displayPhotos(): array
    {
        $photos = array_values(array_filter(
            is_array($this->lr_photos) ? $this->lr_photos : [],
            fn ($u) => is_string($u) && $u !== '',
        ));

        if ($this->lr_primary_photo) {
            array_unshift($photos, $this->lr_primary_photo);
        }

        return array_values(array_unique($photos));
    }

    /**
     * The photo the events team should actually print, in priority order:
     * an upload they made, else the LR photo they explicitly retained, else the
     * LR default. Null when we have nothing.
     */
    public function finalPhotoUrl(): ?string
    {
        return $this->current_photo_url
            ?: $this->retained_photo_url
            ?: $this->lr_primary_photo
            ?: ($this->displayPhotos()[0] ?? null);
    }

    public function finalPhotoSource(): string
    {
        if ($this->current_photo_url)  return 'uploaded';
        if ($this->retained_photo_url) return 'retained';
        if ($this->finalPhotoUrl())    return 'lr_default';

        return 'none';
    }
}
