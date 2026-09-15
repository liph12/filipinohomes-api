<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sign-up on a company event's public page: who is coming and how to
 * reach them. PII — so no audit trail (which would copy the row into the
 * audits table) and a HARD delete from the admin list: nothing else points
 * at a registration, and a removed one should be gone.
 *
 * `phone` is stored normalised (09XXXXXXXXX) and is unique per event; the
 * controller turns the duplicate into a friendly 422.
 */
class CompanyEventRegistration extends Model
{
    protected $table = 'company_event_registrations';

    protected $fillable = [
        'company_event_id',
        'name',
        'phone',
        'email',
        'address',
        'notes',
        'ip_hash',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CompanyEvent::class, 'company_event_id');
    }
}
