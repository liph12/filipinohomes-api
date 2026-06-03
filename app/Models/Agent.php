<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Auditing\LogsActivity;
use OwenIt\Auditing\Contracts\Auditable;
class Agent extends Model implements Auditable
{
    use HasFactory, SoftDeletes;
    use LogsActivity;

    protected string $auditCategory = 'agents';
    protected array $auditLabelAttributes = ['first_name', 'last_name'];

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'mobile_no',
        'whats_app_no',
        'address',
        'socials',
        "bio",
        'avatar',
        'geo_location',
        'member_since',
        'user_id',
        'status',
        'lr_email',
        'birthdate',
        'gender',
    ];

    protected $casts = [
        'socials' => 'array',
        'avatar' => 'array',
        'geo_location' => 'array',
        'birthdate' => 'date',
        'response_metrics_updated_at' => 'datetime',
        'within_1h_response_pct' => 'float',
        'unanswered_response_pct' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function listings()
    {
        return $this->hasMany(Listing::class, 'agent_id');
    }

    public function pageBuilder()
    {
        return $this->hasOne(PageBuilder::class, 'agent_id');
    }

    public function teamMembers()
    {
        return $this->hasMany(TeamAgent::class, 'agent_id');
    }

    public function teamLeader()
    {
        return $this->hasOne(TeamAgent::class, 'agent_id')
            ->where('is_leader', true);
    }
}
