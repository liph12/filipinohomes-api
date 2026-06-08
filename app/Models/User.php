<?php
namespace App\Models;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Auditing\LogsActivity;
use OwenIt\Auditing\Contracts\Auditable;
class User extends Authenticatable implements Auditable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;
    use LogsActivity;

    protected string $auditCategory = 'users';
    protected array $auditLabelAttributes = ['name', 'email'];

    /**
     * Don't audit fields that are noise / sensitive
     */
    protected $auditExclude = [
        'password',
        'remember_token',
        'last_online_at',
        'updated_at',
        'active_at',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'google_id',
        'mobile_no',
        'password',
        'avatar',
        // Editorial / Person-schema author fields. Optional on every
        // user — only populated for staff writers whose posts need
        // BlogPosting `author: Person` schema for E-E-A-T.
        'bio',
        'slug',
        'credentials',
        "role_id",
        'verification',
        'active_at',
        'inquiry_notify_channel',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'avatar' => 'string',
            'active_at' => 'datetime',
            'last_online_at' => 'datetime',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function scopeAdmin($q)
    {
        return $q->whereHas('role', function($sub){
            $sub->where('name', 'admin');
        });
    }

    public function scopeClient($q)
    {
        return $q->whereHas('role', function($sub){
            $sub->where('name', 'client');
        });
    }

    public function agent()
    {
        return $this->hasOne(Agent::class, 'user_id');
    }

    public function chats()
    {
        return $this->hasMany(Chat::class);
    }

    public function loginLogs()
    {
        return $this->hasMany(LoginLog::class);
    }

    public function blockedClients()
    {
        return $this->hasMany(BlockedUser::class, 'agent_user_id');
    }

    public function blockedByAgents()
    {
        return $this->hasMany(BlockedUser::class, 'blocked_user_id');
    }

    public function reviewsAsAgent()
    {
        return $this->hasMany(AgentReview::class, 'agent_user_id');
    }

    public function reviewsAsClient()
    {
        return $this->hasMany(AgentReview::class, 'client_user_id');
    }

    public function reviewResponses()
    {
        return $this->hasMany(AgentReviewResponse::class, 'agent_user_id');
    }

    public function conversations()
    {
        return $this->belongsToMany(Conversation::class, 'conversation_users')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function deviceTokens()
    {
        return $this->hasMany(DeviceToken::class);
    }

    /** True when the user is signed in on at least one device (has a push token). */
    public function hasRegisteredDevice(): bool
    {
        return $this->deviceTokens()->exists();
    }

    /**
     * Whether listing-inquiry alerts should reach this user by push rather than
     * email. Requires both the 'push' preference AND a registered device — a
     * user who chose push but isn't signed in on a phone falls back to email.
     */
    public function prefersInquiryPush(): bool
    {
        return ($this->inquiry_notify_channel ?? 'push') === 'push' && $this->hasRegisteredDevice();
    }

    public function appNotifications()
    {
        return $this->hasMany(AppNotification::class);
    }

    public function userInfo()
    {
        return $this->hasOne(UserInfo::class);
    }

    public function createdProjects()
    {
        return $this->hasMany(Project::class, 'created_by');
    }

    public function addedProjects()
    {
        return $this->hasMany(Project::class, 'added_by', 'email');
    }

    public function updatedProjects()
    {
        return $this->hasMany(Project::class, 'updated_by');
    }

    public function deletedProjects()
    {
        return $this->hasMany(Project::class, 'deleted_by');
    }
}
