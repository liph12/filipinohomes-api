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
        'city'
    ];

    protected $casts = [
        'device' => 'json'
    ];

    public function replies()
    {
        return $this->hasMany(InquiryReply::class)->orderBy('created_at');
    }
}
