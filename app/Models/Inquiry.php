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
    ];

    protected $casts = [
        'device' => 'json',
        'read_at' => 'datetime',
    ];

    public function replies()
    {
        return $this->hasMany(InquiryReply::class)->orderBy('created_at');
    }
}
