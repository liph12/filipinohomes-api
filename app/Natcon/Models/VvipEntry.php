<?php

namespace App\Natcon\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One VVIP honouree row from the awards sheet: who (email, name), under which
 * award category, at which rank. Imported per convention; the gallery's VVIP
 * frames are matched to people through this table.
 */
class VvipEntry extends Model
{
    protected $table = 'natcon_vvip_entries';

    protected $fillable = ['natcon_event_id', 'category', 'rank', 'name', 'email', 'types'];

    protected $casts = ['rank' => 'integer', 'types' => 'array'];

    /** The logo types a person can carry (any number): key => label. */
    public const TYPES = [
        'elite_circle' => 'Elite Circle',
        'rm_pro' => 'RM Pro',
        'global_partners' => 'Global Partners',
        'fhi_dubai' => 'FHI Dubai',
    ];

    /** Categories are free text, stored as typed; two match only if they are equal ignoring case and extra spaces. */
    public static function categoryKey(?string $category): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(trim((string) $category))));
    }

    public function setEmailAttribute(?string $value): void
    {
        $value = $value === null ? '' : mb_strtolower(trim($value));
        $this->attributes['email'] = $value === '' ? null : $value;
    }
}
