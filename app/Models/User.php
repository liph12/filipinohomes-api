<?php
namespace App\Models;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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
        "role_id",
        'verification',
        'active_at',
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

    public function blockedClients()
    {
        return $this->hasMany(BlockedUser::class, 'agent_user_id');
    }

    public function blockedByAgents()
    {
        return $this->hasMany(BlockedUser::class, 'blocked_user_id');
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
