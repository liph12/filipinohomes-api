<?php

namespace App\Models;

use App\Mcp\Support\McpUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person's Claude, signed in to their FH account (docs/mcp.md, "Sign-in").
 * Like a key it belongs to one person and opens /mcp/filipinohomes and nothing
 * else, and the person's role is judged again on every request; unlike a key,
 * nobody copies it anywhere: Claude holds the tokens (McpOauthToken) and the
 * person can end it from their portal, an admin from System Settings.
 */
class McpConnection extends Model
{
    protected $fillable = [
        'user_id',
        'client_id',
        'client_kind',
        'role',
        'resource',
        'scope',
        'last_used_at',
        'expires_at',
        'revoked_at',
        'revoked_by',
        'revoked_reason',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(McpOauthToken::class, 'mcp_connection_id');
    }

    /** Not ended and not past the end of its refresh token. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** active, expired or revoked: the one word the lists show. */
    public function state(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->isActive() => 'active',
            default => 'expired',
        };
    }

    /** Whether the address it was made for is one this API still answers to (the current one, or a former one still listed). */
    public function addressIsAccepted(): bool
    {
        return McpUrl::isAccepted($this->resource);
    }

    /** Whether it was made for an address other than the current one, so its person is asked to connect again. */
    public function needsReconnect(): bool
    {
        return ! McpUrl::same($this->resource, McpUrl::server());
    }
}
