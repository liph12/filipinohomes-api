<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use App\Auditing\LogsActivity;
use OwenIt\Auditing\Contracts\Auditable;
class PropertyAttribute extends Model implements Auditable
{
    use HasFactory;
    use SoftDeletes;
    use LogsActivity;

    protected string $auditCategory = 'listings';

    /** See note on Property::$auditEvents — same reasoning. */
    protected $auditEvents = ['updated', 'deleted', 'restored'];

    protected $fillable = [
        'bedroom_count',
        'bathroom_count',
        'garage_count',
        'lot_area',
        'floor_area',
        'property_subtype_id',
    ];

    protected $casts = [
        'lot_area'          => 'decimal:2',
        'floor_area'         => 'decimal:2',
    ];

    public function subtype()
    {
        return $this->belongsTo(PropertySubtype::class, 'property_subtype_id');
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (Auth::check()) {
                $model->created_by = Auth::id();
                $model->updated_by = Auth::id();
            }
        });

        static::updating(function ($model) {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });

        static::deleting(function ($model) {
            $usesSoftDeletes = in_array(SoftDeletes::class, class_uses($model) ?: []);
            if ($usesSoftDeletes && Auth::check()) {
                $model->deleted_by = Auth::id();
                $model->save();
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
