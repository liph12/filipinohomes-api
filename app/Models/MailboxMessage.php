<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One website email in the shared mailbox, as indexed by `mailbox:sync`
 * (see MailboxService::sync()). A row per server copy; `is_primary` marks the
 * one shown when an email arrived several times. `archived_at` set = the
 * email now lives in the S3 archive (`archive_path`), no longer on the mail
 * server (see MailboxService archive section / `mailbox:archive`).
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
        'archived_at' => 'datetime',
        'archive_bytes' => 'integer',
        'uid' => 'integer',
        'uidvalidity' => 'integer',
    ];
}
