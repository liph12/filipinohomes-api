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
        'visitor_id',
        'mobile_no',
        // Client demographics (parallel to agents.birthdate/gender).
        'birthdate',
        'gender',
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
        'notify_new_inquiry',
        'notify_listing_verified',
        'notify_status_change',
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
            // Date cast so $user->birthdate->age works in the client
            // demographics aggregation. No `age` accessor — age is derived
            // where needed (backend brackets / frontend display).
            'birthdate' => 'date',
            'active_at' => 'datetime',
            'last_online_at' => 'datetime',
            'notify_new_inquiry' => 'boolean',
            'notify_listing_verified' => 'boolean',
            'notify_status_change' => 'boolean',
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

    public function isAdmin(): bool
    {
        return $this->role?->name === 'admin';
    }

    /** A secretary (FH role 5) — admin-like, but scoped to one office region. */
    public function isSecretary(): bool
    {
        return $this->role?->name === 'secretary';
    }

    /**
     * The single office region a secretary oversees (reuses their agent profile's
     * region), or null when the user isn't a secretary / has no region assigned.
     */
    public function secretaryRegion(): ?string
    {
        return $this->isSecretary() ? ($this->agent?->region) : null;
    }

    public function chats()
    {
        return $this->hasMany(Chat::class);
    }

    public function loginLogs()
    {
        return $this->hasMany(LoginLog::class);
    }

    /** Days without a presence heartbeat before an agent counts as dormant. */
    public const DORMANT_DAYS = 45;

    public const DORMANCY_POLICY_START = '2026-07-02';

    public function scopeDormantSince($query, $threshold)
    {
        return $query->where(fn ($q) => $q->whereNull('last_online_at')
            ->orWhere('last_online_at', '<', $threshold));
    }

    public function deactivateAgentIfDormant(): void
    {
        $agent = $this->agent;
        if (!$agent || $agent->status !== 'active') {
            return;
        }

        $floor = \Illuminate\Support\Carbon::parse(self::DORMANCY_POLICY_START);
        $lastSeen = $this->last_online_at
            ? \Illuminate\Support\Carbon::parse($this->last_online_at)->max($floor)
            : $floor;

        // Date-level threshold (today, not now) keeps the grace window exact:
        // its last day still counts as inside at any hour of the day.
        if ($lastSeen->lt(\Illuminate\Support\Carbon::today()->subDays(self::DORMANT_DAYS))) {
            $agent->update(['status' => 'deactivated']);
            \Illuminate\Support\Facades\Log::info('Agent auto-deactivated at login — no activity in 45 days', [
                'agent_id' => $agent->id,
                'user_id'  => $this->id,
                'last_online_at' => $this->last_online_at,
            ]);
        }
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

    /**
     * Whether this user wants a push for the given notification type. Maps each
     * push type onto its Settings category toggle; unknown/uncategorised types
     * (e.g. announcements) are always allowed. The in-app feed row is recorded
     * regardless — this gates only the push delivery.
     */
    public function allowsPushCategory(string $type): bool
    {
        return match ($type) {
            'inquiry', 'listing_inquiry' => (bool) ($this->notify_new_inquiry ?? true),
            'listing_verified', 'listing_flagged', 'listing_fully_verified' => (bool) ($this->notify_listing_verified ?? true),
            'listing_status' => (bool) ($this->notify_status_change ?? true),
            default => true,
        };
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
