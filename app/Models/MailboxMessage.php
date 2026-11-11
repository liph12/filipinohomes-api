<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One website email in the shared mailbox, as indexed by `mailbox:sync`
 * (see MailboxService::sync()). A row per server copy; `is_primary` marks the
 * one shown when an email arrived several times.
 */
class MailboxMessage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'to' => 'array',
        'detail' => 'array',
        'is_primary' => 'boolean',
        'seen' => 'boolean',
        'flagged' => 'boolean',
        'answered' => 'boolean',
        'sent_at' => 'datetime',
        'local_change_at' => 'datetime',
        'uid' => 'integer',
        'uidvalidity' => 'integer',
    ];
}
