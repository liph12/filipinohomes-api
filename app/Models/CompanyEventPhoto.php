<?php

namespace App\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One picture on a company event. URLs are computed once at upload time and
 * stored absolute alongside the s3_key — no accessor rebuilds them (gallery
 * convention). `deleted` rows are kept: the row is the only pointer to the
 * S3 object.
 */
class CompanyEventPhoto extends Model implements Auditable
{
    use LogsActivity;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DELETED = 'deleted';

    protected $table = 'company_event_photos';

    protected string $auditCategory = 'company_events';

    protected $fillable = [
        'company_event_id',
        'image_url',
        'thumb_url',
        's3_key',
        'width',
        'height',
        'byte_size',
        'sort_order',
        'status',
        'created_by',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'byte_size' => 'integer',
        'sort_order' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CompanyEvent::class, 'company_event_id');
    }
}
