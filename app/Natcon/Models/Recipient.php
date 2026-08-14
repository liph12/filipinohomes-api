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
    /**
     * Assembling their set — at least one photo in, not yet enough of them.
     *
     * Its own status rather than reusing RESPONDED_CHANGE, because a set can now
     * be built from kept photos as well as new ones: someone holding two kept
     * photos and no uploads is part-way, and labelling that "change" would have
     * `response` and `status` telling the admin two different stories. No
     * migration — status is a string column precisely so this is cheap.
     */
    public const STATUS_PHOTOS_PARTIAL   = 'photos_partial';
    public const STATUS_COMPLETED        = 'completed';
    public const STATUS_FAILED           = 'failed';
    public const STATUS_EXCLUDED         = 'excluded';

    public const RESPONSE_RETAIN = 'retain';
    public const RESPONSE_CHANGE = 'change';

    public const LR_PENDING   = 'pending';
    public const LR_FOUND     = 'found';
    public const LR_NOT_FOUND = 'not_found';
    public const LR_ERROR     = 'error';

    /**
     * Statuses still eligible for a reminder.
     *
     * ⚠️ RESPONDED_CHANGE belongs here, and leaving it out was a bug caught in
     *    testing. Now that the event asks for several photos, that status is what
     *    a PARTIAL submitter sits at — one or two photos in, not finished. They
     *    are precisely who a reminder is for, and without this they were filtered
     *    out of reminderTargets() and silently stopped being chased while holding
     *    two of three photos.
     *
     *    `responded_at IS NULL` in reminderTargets() is what still excludes the
     *    ones who actually finished; this list only decides who is in the running.
     */
    public const REMINDABLE = [
        self::STATUS_INVITED,
        self::STATUS_REMINDED,
        self::STATUS_RESPONDED_CHANGE,
        self::STATUS_PHOTOS_PARTIAL,
    ];

    protected $fillable = [
        'natcon_event_id', 'email',
        'lr_awardee_id', 'reg_id', 'first_name', 'last_name', 'phone', 'team',
        'owner_name', 'seat_number', 'lr_polo_shirt_size', 'lr_approved',
        'lr_photos', 'lr_primary_photo', 'lr_qr_code', 'lr_payload',
        'lr_fetched_at', 'lr_lookup_status', 'lr_last_error',
        'source', 'imported_batch_id', 'created_by',
        'status', 'notes',
        // From LR's qualifiers list. Mass-assignable because the import service
        // merges them straight into Recipient::create().
        'display_name', 'total_sales', 'lr_confirmation_status', 'qualifier_payload',
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
        'requires_new_photo'    => 'boolean',
        'requires_new_photo_at' => 'datetime',
        'qualifier_payload'     => 'array',
        'total_sales'           => 'decimal:2',
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

    /**
     * Every photo this awardee currently has standing, oldest first.
     *
     * Oldest first so the tray on their page doesn't reshuffle when they add a
     * photo — the slot a photo sits in is stable for as long as it exists.
     */
    public function activePhotos()
    {
        return $this->hasMany(PhotoSubmission::class, 'natcon_recipient_id')
            ->where('status', PhotoSubmission::STATUS_ACTIVE)
            ->orderBy('id');
    }

    /**
     * Who ruled this awardee's existing photo unusable.
     *
     * Fully-qualified rather than imported: `User` is not in this namespace. A
     * bare `User::class` here resolves to App\Natcon\Models\User and throws —
     * which is exactly what Outbox::requester() did until it was fixed alongside
     * this, having never been called.
     */
    public function requiresNewPhotoBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'requires_new_photo_by');
    }

    /** The submission the organizers picked, if they have picked one. */
    public function chosenPhoto()
    {
        return $this->hasOne(PhotoSubmission::class, 'natcon_recipient_id')
            ->where('status', PhotoSubmission::STATUS_ACTIVE)
            ->where('review_status', PhotoSubmission::REVIEW_APPROVED)
            ->latestOfMany();
    }

    /**
     * True once they have sent everything the event asks for.
     *
     * This — not "has uploaded anything" — is what completion means now, and it
     * is the rule PhotoService::syncResponseState() writes into responded_at.
     * Reading it anywhere else is fine; writing the state anywhere else is not.
     */
    public function hasAllRequiredPhotos(): bool
    {
        return $this->activePhotos()->count() >= self::requiredPhotoCount();
    }

    public static function requiredPhotoCount(): int
    {
        return max(1, (int) config('natcon.photo.required_count', 3));
    }

    public static function maxPhotoCount(): int
    {
        return max(self::requiredPhotoCount(), (int) config('natcon.photo.max_count', 3));
    }

    public function sends()
    {
        return $this->hasMany(Outbox::class, 'natcon_recipient_id');
    }

    public function formSubmission()
    {
        return $this->hasOne(FormSubmission::class, 'natcon_recipient_id');
    }

    /**
     * What to call this person.
     *
     * ⚠️ `display_name` wins, and the order matters.
     *
     * It comes from LR's qualifiers list, where 116 of 285 names are couples —
     * "Jo-ann and Albert Maranian". first_name/last_name come from a DIFFERENT
     * endpoint (get-awardee, via AwardeeService::mapAwardee) which knows one
     * person per record, and hydration runs AFTER a sync. Without this
     * precedence, hydrating would quietly replace the couple with whichever half
     * of it LR's awardee record happens to name, and nobody would notice until an
     * email went out addressed to one of two people.
     */
    public function displayName(): string
    {
        if ($this->display_name) {
            return $this->display_name;
        }

        $name = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));

        return $name !== '' ? $name : ($this->owner_name ?: $this->email);
    }

    /**
     * Tidy a name that arrived from an external list.
     *
     * Two problems, measured on the live qualifiers payload:
     *
     *   · 66 of 285 contain double spaces ("Jo-ann and Albert  Maranian").
     *   · 3 arrive shouting ("KIRBY CYRL MAGDOSA FERNANDEZ").
     *
     * The obvious fix — ucwords(strtolower($name)) — would repair those 3 and
     * DAMAGE the other 282, which are already correctly cased: "Jo-ann and
     * Albert" becomes "Jo-Ann And Albert". So re-casing is conditional on the
     * name actually being all-caps, and everything else is left exactly as the
     * list has it.
     */
    public static function tidyName(?string $raw): ?string
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $raw));

        if ($name === '') {
            return null;
        }

        if ($name === mb_strtoupper($name, 'UTF-8')) {
            $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
        }

        return mb_substr($name, 0, 191);
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
    /**
     * What the events team should actually print.
     *
     * ⚠️ The fallback chain STOPS at the upload when a reviewer has ruled the
     *    photo on file unusable. Without that guard, a flagged awardee who never
     *    sends a replacement still resolves to their retained photo — or to the LR
     *    default — and the pipeline quietly hands the design team the exact image
     *    somebody rejected. Returning null instead makes the gap visible in the
     *    admin and in the CSV export, which is the only way it gets chased.
     */
    public function finalPhotoUrl(): ?string
    {
        if ($this->requires_new_photo) {
            return $this->current_photo_url;
        }

        return $this->current_photo_url
            ?: $this->retained_photo_url
            ?: $this->lr_primary_photo
            ?: ($this->displayPhotos()[0] ?? null);
    }

    public function finalPhotoSource(): string
    {
        if ($this->current_photo_url) {
            // A kept photo is a submission too now, so "there is a
            // current_photo_url" no longer implies they sent us a file. Answer
            // from the row it came from rather than from its existence.
            $chosen = $this->activePhotos()
                ->where('photo_url', $this->current_photo_url)
                ->first();

            return $chosen?->source === PhotoSubmission::SOURCE_LR_RETAINED
                ? 'retained'
                : 'uploaded';
        }

        // Same guard: neither the retained photo nor the LR default counts as a
        // source once it has been rejected.
        if ($this->requires_new_photo) return 'none';
        if ($this->retained_photo_url) return 'retained';
        if ($this->finalPhotoUrl())    return 'lr_default';

        return 'none';
    }
}
