<?php

namespace App\Natcon\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One draw of the live raffle: who (name, contact) won what (prize), when.
 * Tied to a convention, so next year's page can still show this year's.
 * Soft-deleted: a removed winner is hidden, never gone.
 */
class RaffleWinner extends Model implements Auditable
{
    use LogsActivity;
    use SoftDeletes;

    // The class name drops the module prefix, so Eloquent would infer the wrong table.
    protected $table = 'natcon_raffle_winners';

    protected string $auditCategory = 'natcon';

    protected array $auditLabelAttributes = ['name', 'prize'];

    protected $fillable = ['natcon_event_id', 'client_key', 'raffle', 'name', 'contact', 'prize', 'drawn_at', 'deleted_by'];

    protected $casts = [
        'drawn_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(NatconEvent::class, 'natcon_event_id');
    }

    /** Hide the row and say who did it. */
    public function removeBy(?int $userId): void
    {
        $this->deleted_by = $userId;
        $this->save();
        $this->delete();
    }
}
