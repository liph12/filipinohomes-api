<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Auditing\LogsActivity;
use OwenIt\Auditing\Contracts\Auditable;

class Inquiry extends Model implements Auditable
{
    use LogsActivity;

    protected string $auditCategory = 'inquiries';
    protected array $auditLabelAttributes = ['name', 'email'];

    protected $fillable = [
        'name',
        'email',
        'message',
        'source',
        'device',
        'country',
        'state',
        'city',
        'read_at',
        'done_at',
        'done_by',
    ];

    // read_at here is the old shared flag, frozen since per-admin read state moved to inquiry_reads
    // (see InquiryReadState). It still marks pre-existing submissions as read for admins with no row.
    protected $casts = [
        'device' => 'json',
        'read_at' => 'datetime',
        'done_at' => 'datetime',
    ];

    /** The admin who marked it done (Contact Inbox). */
    public function doneBy()
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function replies()
    {
        return $this->hasMany(InquiryReply::class)->orderBy('created_at');
    }
}
