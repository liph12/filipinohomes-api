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

    /**
     * Every value agents.status can hold, in the enum's order. The column is a
     * MySQL enum altered by raw SQL, so AgentStatusesTest checks this against
     * the latest enum migration — change both together.
     */
    public const STATUSES = ['active', 'inactive', 'resigned', 'deactivated', 'terminated'];

    protected string $auditCategory = 'agents';
    protected array $auditLabelAttributes = ['first_name', 'last_name'];

    protected $fillable = [
        'first_name',
        'middle_name',
        'suffix',
        'last_name',
        'mobile_no',
        'whats_app_no',
        'address',
        'socials',
        "bio",
        'avatar',
        'geo_location',
        // Last save through the profile-edit form (not updated_at — see the
        // 2026_09_09 migration). Drives the dashboard's profile reminder.
        'profile_updated_at',
        'member_since',
        'user_id',
        'status',
        'lr_email',
        'birthdate',
        // When we last ASKED Leuterio Realty for this agent's birthday (see
        // birthdays:backfill-birthdates) — distinct from having one, because
        // "LR doesn't know" has to stop the next run re-asking forever.
        'birthdate_checked_at',
        'gender',
        'region',
        'lr_state',
    ];

    /**
     * The LR sync's bookkeeping (agents:sync-lr-status). Never serialised:
     * FullListingController::show returns the whole agent to any agent or
     * admin, and LR's view of who is employed is nobody else's business.
     */
    protected $hidden = [
        'lr_employment_lookup',
        'lr_employment_status',
        'lr_employment_checked_at',
        'lr_employment_changed_at',
    ];

    protected $casts = [
        'socials' => 'array',
        'avatar' => 'array',
        'geo_location' => 'array',
        'profile_updated_at' => 'datetime',
        'birthdate' => 'date',
        'birthdate_checked_at' => 'datetime',
        'lr_employment_checked_at' => 'datetime',
        'lr_employment_changed_at' => 'datetime',
        'response_metrics_updated_at' => 'datetime',
        'within_1h_response_pct' => 'float',
        'unanswered_response_pct' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Whether a status switches the account off: anything but `active`, so a
     * status added later is blocked by default instead of silently let
     * through. No status at all (a user with no agent row) is not blocked.
     */
    public static function isBlockedStatus(?string $status): bool
    {
        return $status !== null && $status !== 'active';
    }

    /**
     * This agent is switched off (resigned, terminated, inactive, deactivated):
     * no platform access, no public listings, and nothing worth emailing.
     * Admins are never blocked — they also work as agents, and a stray status
     * on an admin's own agent row must not lock them out (same rule as
     * EnsureAgentActive).
     */
    public function isBlocked(): bool
    {
        return self::isBlockedStatus($this->status) && ! ($this->user?->isAdmin() ?? false);
    }

    public function listings()
    {
        return $this->hasMany(Listing::class, 'agent_id');
    }

    /**
     * Who may appear on the PUBLIC agent pages — the /agents directory, office
     * pages, the "N agents online" pill and the agent's own profile page:
     * every agent, plus any admin with at least one publicly-listed listing.
     *
     * Admins also work as agents, so their posted listings should put them in
     * front of clients like anyone else — but an admin with nothing posted has
     * no business in the directory, so the listing is the condition. "Posted"
     * means publicly listed (Listing::publiclyListed), so an admin whose only
     * listings are private doesn't surface with a profile reading
     * "0 listings".
     *
     * The single definition: keep every public surface on this scope (and the
     * profile page on isEligibleForPublicDirectory()) so they cannot drift.
     */
    public function scopeEligibleForPublicDirectory($query)
    {
        return $query->where(function ($q) {
            $q->whereHas('user.role', fn ($r) => $r->where('name', 'agent'))
                ->orWhere(function ($admin) {
                    $admin->whereHas('user.role', fn ($r) => $r->where('name', 'admin'))
                        ->whereHas('listings', fn ($l) => $l->publiclyListed());
                });
        });
    }

    /** Instance form of scopeEligibleForPublicDirectory(), for a single agent. */
    public function isEligibleForPublicDirectory(): bool
    {
        return static::query()->whereKey($this->getKey())->eligibleForPublicDirectory()->exists();
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
