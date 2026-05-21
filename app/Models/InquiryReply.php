<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Auditing\LogsActivity;
use OwenIt\Auditing\Contracts\Auditable;

class InquiryReply extends Model implements Auditable
{
    use LogsActivity;

    protected string $auditCategory = 'inquiries';
    protected array $auditLabelAttributes = ['subject'];

    protected $fillable = [
        'inquiry_id',
        'admin_user_id',
        'subject',
        'body',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function inquiry()
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
