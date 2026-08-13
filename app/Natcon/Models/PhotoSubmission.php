<?php

namespace App\Natcon\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class PhotoSubmission extends Model implements Auditable
{
    // The class name drops the module prefix, so Eloquent would otherwise
    // infer `photo_submissions` from it. The table keeps the natcon_ prefix because it
    // shares a schema with the rest of the product.
    protected $table = 'natcon_photo_submissions';

    use LogsActivity;

    protected string $auditCategory = 'natcon';
    protected array $auditLabelAttributes = ['photo_url'];

    public const STATUS_ACTIVE     = 'active';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_DELETED    = 'deleted';

    public const REVIEW_PENDING  = 'pending';
    public const REVIEW_APPROVED = 'approved';
    public const REVIEW_REJECTED = 'rejected';

    protected $fillable = [
        'natcon_recipient_id', 'natcon_event_id',
        'photo_url', 's3_key', 'original_filename', 'mime_type',
        'byte_size', 'width', 'height',
        'status', 'review_status', 'review_note', 'reviewed_by', 'reviewed_at',
        'uploaded_ip', 'uploaded_user_agent',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'byte_size'   => 'integer',
        'width'       => 'integer',
        'height'      => 'integer',
    ];

    public function recipient()
    {
        return $this->belongsTo(Recipient::class, 'natcon_recipient_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
