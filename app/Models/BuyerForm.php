<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Auditing\LogsActivity;
use OwenIt\Auditing\Contracts\Auditable;

class BuyerForm extends Model implements Auditable
{
    use HasFactory, SoftDeletes;
    use LogsActivity;

    protected string $auditCategory = 'content';
    protected array $auditLabelAttributes = ['title'];

    protected $fillable = [
        'slug',
        'title',
        'description',
        'location',
        'property_type_id',
        'project_id',
        'agent_id',
    ];

    protected static function booted()
    {
        static::creating(function ($form) {
            if (empty($form->slug)) {
                do {
                    $candidate = Str::lower(Str::random(12));
                } while (self::where('slug', $candidate)->doesntExist() === false);

                $form->slug = $candidate;
            }
        });
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function propertyType()
    {
        return $this->belongsTo(PropertyType::class, 'property_type_id');
    }

    public function registrations()
    {
        return $this->hasMany(BuyerFormRegistration::class);
    }
}
