<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A key for the MCP server (see App\Mcp\Support\McpKeys and docs/mcp.md): one
 * person's, good for /mcp/filipinohomes and nothing else. Only the hash of the
 * key is kept.
 */
class McpKey extends Model
{
    protected $fillable = [
        'user_id',
        'created_by',
        'revoked_by',
        'name',
        'prefix',
        'token_hash',
        'expires_at',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The admin who made it from the dashboard (null: made with the artisan command). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Not revoked and not past its last moment (a permanent key has none: its expires_at is empty). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $open) => $open->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** The one word the dashboard and the commands show for it: active, expired or revoked. */
    public function state(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->isActive() => 'active',
            default => 'expired',
        };
    }
}
