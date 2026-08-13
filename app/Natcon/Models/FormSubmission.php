<?php

namespace App\Natcon\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One awardee's answers to the convention form.
 *
 * Two JSON columns, answering different questions:
 *   `answers`          — keyed by field key. What you QUERY.
 *   `answers_snapshot` — labels frozen as shown. What you DISPLAY and export.
 *
 * ⚠️ `answers` must never carry a key whose value is empty. "Has this field been
 *    answered?" is a JSON_CONTAINS_PATH check, and a stored null would count as
 *    answered — which would then block deleting a field nobody actually filled
 *    in. FormService omits empty keys rather than writing null.
 */
class FormSubmission extends Model implements Auditable
{
    // The class name drops the module prefix, so Eloquent would otherwise
    // infer `form_submissions` from it. The table keeps the natcon_ prefix because it
    // shares a schema with the rest of the product.
    protected $table = 'natcon_form_submissions';

    use LogsActivity;

    protected string $auditCategory = 'natcon';

    protected $fillable = [
        'natcon_event_id', 'natcon_recipient_id', 'answers', 'answers_snapshot',
        'submitted_ip', 'submitted_user_agent',
    ];

    protected $casts = [
        'answers'          => 'array',
        'answers_snapshot' => 'array',
    ];

    public function recipient()
    {
        return $this->belongsTo(Recipient::class, 'natcon_recipient_id');
    }

    public function event()
    {
        return $this->belongsTo(NatconEvent::class, 'natcon_event_id');
    }

    /**
     * Raw answers keyed by field key — the machine values.
     *
     * @return array<string,mixed>
     */
    public function answerMap(): array
    {
        return (array) ($this->answers ?? []);
    }

    /**
     * Answers keyed by field key, using the human label frozen at submit time.
     * Renaming a field or retiring a choice later cannot rewrite this.
     *
     * @return array<string,mixed>
     */
    public function displayMap(): array
    {
        $out = [];

        foreach ((array) ($this->answers_snapshot ?? []) as $row) {
            if (is_array($row) && isset($row['key'])) {
                $out[$row['key']] = $row['display_value'] ?? $row['value'] ?? null;
            }
        }

        return $out;
    }
}
