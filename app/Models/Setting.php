<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, $default = null)
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * System Settings kill switch for the admin email fan-outs (listing-
     * inquiry pending-review, Get In Touch, Contact Us). Submissions still
     * persist to the dashboard inboxes; only the admin BCC emails are
     * skipped. Agent / team-leader / client emails are unaffected.
     */
    public static function adminEmailsMuted(): bool
    {
        return static::get('admin_emails_muted') === 'true';
    }
}
