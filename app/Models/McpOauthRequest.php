<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sign-in that has started: what Claude asked for (its return address, the
 * PKCE challenge, the state it wants back), the handle the website is given to
 * show the person, and, once they answer, the one-time code Claude trades for
 * tokens. Short lived (App\Services\McpOAuth\OAuthAuthorizations).
 */
class McpOauthRequest extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'handle_hash',
        'client_id',
        'redirect_uri',
        'state',
        'code_challenge',
        'scope',
        'resource',
        'user_id',
        'role',
        'decision',
        'code_hash',
        'code_expires_at',
        'code_used_at',
        'mcp_connection_id',
        'expires_at',
    ];

    protected $hidden = ['handle_hash', 'code_hash', 'code_challenge'];

    protected $casts = [
        'code_expires_at' => 'datetime',
        'code_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
