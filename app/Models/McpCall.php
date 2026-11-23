<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tool a key ran through the MCP server: who, which tool, with what, and
 * how it went (ok, error, or denied). Written by App\Mcp\Support\McpAudit.
 */
class McpCall extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'mcp_key_id',
        'user_id',
        'role',
        'tool',
        'arguments',
        'outcome',
        'error',
        'duration_ms',
        'bytes',
        'ip',
    ];

    protected $casts = [
        'arguments' => 'array',
    ];

    public function key(): BelongsTo
    {
        return $this->belongsTo(McpKey::class, 'mcp_key_id');
    }
}
