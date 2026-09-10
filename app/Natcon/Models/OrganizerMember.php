<?php

namespace App\Natcon\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One person on a committee card: a head or an assistant, with an optional
 * portrait. Saved together with the card (the controller syncs the whole
 * list on every committee write), so there is no standalone endpoint.
 */
class OrganizerMember extends Model implements Auditable
{
    use LogsActivity;

    public const ROLES = ['head', 'assistant'];

    protected $table = 'natcon_organizer_members';

    protected string $auditCategory = 'natcon';

    protected array $auditLabelAttributes = ['name', 'role'];

    protected $fillable = [
        'committee_id', 'role', 'name', 'description', 'photo_url', 'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function committee(): BelongsTo
    {
        return $this->belongsTo(OrganizerCommittee::class, 'committee_id');
    }
}
