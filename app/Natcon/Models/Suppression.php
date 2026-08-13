<?php

namespace App\Natcon\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Do-not-send list. Checked before every NATCON send.
 *
 * Not scoped to an event — a hard bounce or a spam complaint is a fact about the
 * address, not about NATCON 2026.
 */
class Suppression extends Model
{
    // The class name drops the module prefix, so Eloquent would otherwise
    // infer `suppressions` from it. The table keeps the natcon_ prefix because it
    // shares a schema with the rest of the product.
    protected $table = 'natcon_suppressions';

    public const REASON_BOUNCE         = 'bounce';
    public const REASON_COMPLAINT      = 'complaint';
    public const REASON_UNSUBSCRIBE    = 'unsubscribe';
    public const REASON_MANUAL         = 'manual';
    public const REASON_INVALID_DOMAIN = 'invalid_domain';

    protected $fillable = ['email', 'reason', 'detail', 'created_by'];

    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = strtolower(trim((string) $value));
    }

    public static function suppresses(string $email): bool
    {
        return static::where('email', strtolower(trim($email)))->exists();
    }

    /**
     * Bulk membership test for a chunk of addresses, so the drain command does one
     * query per batch rather than one per recipient.
     *
     * @param  array<int,string>  $emails
     * @return array<string,true>
     */
    public static function lookup(array $emails): array
    {
        $normalized = array_map(fn ($e) => strtolower(trim((string) $e)), $emails);

        $hits = static::whereIn('email', $normalized)->pluck('email')->all();

        return array_fill_keys($hits, true);
    }
}
