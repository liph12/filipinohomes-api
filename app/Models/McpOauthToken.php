<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pair of tokens (access and refresh) given to a connection. Only their
 * SHA-256 is kept. A refresh spends the pair's refresh token and makes the next
 * pair (parent_id), and that chain is how a retry is told from a stolen token
 * (App\Services\McpOAuth\OAuthTokens).
 */
class McpOauthToken extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'mcp_connection_id',
        'parent_id',
        'access_hash',
        'access_expires_at',
        'refresh_hash',
        'refresh_expires_at',
        'used_at',
        'rotated_at',
    ];

    protected $hidden = ['access_hash', 'refresh_hash'];

    protected $casts = [
        'access_expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'used_at' => 'datetime',
        'rotated_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class, 'mcp_connection_id');
    }
}
