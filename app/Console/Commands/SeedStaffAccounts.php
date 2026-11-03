<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Audit;
use App\Models\Role;
use App\Models\User;
use App\Support\OfficeRegionMap;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One password-login account per FH role, for in-house staff who need to see
 * the site as each role (admin, agent, client, editor, secretary, and any role
 * added later) without the email-OTP flow. Staff sign in at
 * {FRONTEND_URL}/staff-login, which posts to the existing POST /login.
 *
 * Roles are read from the `roles` table, not hard-coded. Each account is
 * built the way the real ones come out of LR signup, so every screen works:
 *   - admin / agent / secretary get an agents row (an admin without one 500s
 *     on POST /full-listing; a secretary without a region is 403'd almost
 *     everywhere). The agent and secretary share --region, so the staff
 *     secretary can see the staff agent.
 *   - client / editor / anything newer get the user row only.
 *   - role_locked (no LR sync may move them), email verified, admin
 *     notification emails muted for the admin.
 *
 * Passwords are random per account — or, with --password, one shared password
 * the team picks (it is passed at run time, never kept in this code). Either
 * way they are printed ONCE in the table at the end, and never logged, stored
 * in plain text or written into an audit row. Every write happens in one
 * transaction and the table prints only after it commits, so a failure never
 * leaves an account whose password nobody saw. A shared password on the admin
 * account means anyone who learns it is an admin, so on production keep the
 * admin on its own random password (see --help).
 *
 * Re-running is safe: existing staff accounts are reported and left alone.
 * --reset issues new passwords instead, signs those accounts out of every
 * device, and revives a staff agent the 45-day dormancy rule deactivated
 * (an admin's "inactive" / "resigned" is left as it is). An email that
 * already belongs to a different role, or that is another user's Leuterio
 * Realty login email, is skipped and never taken over.
 *
 * Side effects of having these accounts — tell the staff using them:
 *   - The admin account receives the nightly admin digests
 *     (reports:send-activity, reports:send-birthdays ignore the mute), so its
 *     mailbox must exist or those bounce. Every admin is also added to each
 *     new listing-inquiry conversation.
 *   - The agent account is a real agent: anything it publishes is public.
 *   - The client account's inquiries reach real agents.
 *
 * Production needs --force (it mints an admin password); --dry-run never does.
 *
 *   php artisan staff:seed-accounts --dry-run
 *   php artisan staff:seed-accounts --force
 *   php artisan staff:seed-accounts --role=agent --reset --force
 *
 * Audit: console model-auditing is off by default (config('audit.console')),
 * so handle() flips it on before any User/Agent model boots — same as
 * AutoApproveInquiries — and every row is labelled source 'staff_seeder'.
 */
class SeedStaffAccounts extends Command
{
    // No braces inside option defaults or descriptions: the signature parser
    // ends each option at the first "}", so {role} has to live in $help.
    protected $signature = 'staff:seed-accounts
        {--role=*        : Only these roles, by name or id (repeatable). Default: every role}
        {--email-pattern= : Login email template, see --help. Default: staff.ROLE@filipinohomes.com}
        {--region=cebu   : Office region for the staff agent and secretary}
        {--password=     : One shared password for every account this run creates or resets (min 8 characters). Default: a random password per account}
        {--reset         : Issue new passwords for existing staff accounts and sign them out everywhere}
        {--dry-run       : Show what would happen; write nothing, print no passwords}
        {--force         : Required to write in production}';

    protected $description = 'Create one password-login staff account per FH role and print the credentials once.';

    protected $help = <<<'HELP'
--email-pattern is the login email with {role} where the role name goes, exactly
once. Quote it so the shell leaves the braces alone:

  php artisan staff:seed-accounts --email-pattern="qa+{role}@example.com"

--password sets one shared password instead of a random one per account. To
move existing accounts onto it, add --reset. On production, keep the admin on
its own random password:

  php artisan staff:seed-accounts --role=agent --role=client --role=editor --role=secretary --reset --password="..."

Staff sign in at FRONTEND_URL/staff-login. Passwords are printed once.
HELP;

    private const MIN_SHARED_PASSWORD_LENGTH = 8;

    /** --password, when given: used instead of a random password per account. */
    private ?string $sharedPassword = null;

    /** Where staff sign in, under config('services.frontend.url'). */
    public const LOGIN_PATH = '/staff-login';

    private const DEFAULT_EMAIL_PATTERN = 'staff.{role}@filipinohomes.com';

    private const AUDIT_SOURCE = 'staff_seeder';

    /** Roles that come out of LR signup with an agents row. */
    private const ROLES_WITH_AGENT_PROFILE = ['admin', 'agent', 'secretary'];

    /** Roles whose agents row carries the office region. */
    private const ROLES_WITH_REGION = ['agent', 'secretary'];

    /** No 0/O/o, 1/l/I — these get read off a screen and typed. */
    private const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    public function handle(): int
    {
        // Must come before any User or Agent model is touched: owen-it decides
        // whether to audit a model class once, when that class first boots.
        config(['audit.console' => true]);

        $dryRun = (bool) $this->option('dry-run');
        $reset = (bool) $this->option('reset');

        if (! $dryRun && app()->environment('production') && ! $this->option('force')) {
            $this->warn('Refusing to run in production: this mints an admin password. Pass --force to continue, or --dry-run to preview.');

            return self::FAILURE;
        }

        // --quiet or --silent: the table is the only place the passwords ever
        // appear, so refuse rather than mint ones nobody sees.
        if (! $dryRun && $this->output->getVerbosity() < OutputInterface::VERBOSITY_NORMAL) {
            return self::FAILURE;
        }

        $roles = $this->resolveRoles();
        if ($roles === null) {
            return self::FAILURE;
        }

        $pattern = (string) ($this->option('email-pattern') ?: self::DEFAULT_EMAIL_PATTERN);
        if (substr_count($pattern, '{role}') !== 1) {
            $this->error('--email-pattern must contain {role} exactly once, e.g. --email-pattern="staff.{role}@filipinohomes.com" (quoted).');

            return self::FAILURE;
        }

        $region = Str::slug((string) $this->option('region'));
        if (! OfficeRegionMap::isValid($region)) {
            $this->error("Unknown office region '{$this->option('region')}'. Use one of: " . implode(', ', OfficeRegionMap::REGIONS));

            return self::FAILURE;
        }

        $this->sharedPassword = $this->option('password');
        if ($this->sharedPassword !== null && strlen($this->sharedPassword) < self::MIN_SHARED_PASSWORD_LENGTH) {
            $this->error('--password must be at least ' . self::MIN_SHARED_PASSWORD_LENGTH . ' characters.');

            return self::FAILURE;
        }

        // Plan every role before anything is written.
        $plans = [];
        foreach ($roles as $role) {
            $email = Str::lower(str_replace('{role}', Str::slug($role->name), $pattern));

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->error("'{$email}' (role {$role->name}) is not a valid email address — check --email-pattern.");

                return self::FAILURE;
            }
            if (isset($plans[$email])) {
                $this->error("Two roles would share '{$email}' — check --email-pattern.");

                return self::FAILURE;
            }

            $plans[$email] = $this->plan($role, $email, $reset);
        }

        $skipped = collect($plans)->contains(fn (array $plan) => $plan['action'] === 'skip');

        if ($dryRun) {
            $this->info('[DRY RUN] Nothing written, no passwords generated.');
            $this->table(['Role', 'Email', 'Would'], array_map(
                fn (array $plan) => [$plan['role']->name, $plan['email'], $plan['preview']],
                array_values($plans),
            ));

            return $skipped ? self::FAILURE : self::SUCCESS;
        }

        $rows = DB::transaction(function () use ($plans, $region) {
            $rows = [];
            foreach ($plans as $plan) {
                $rows[] = match ($plan['action']) {
                    'create' => $this->createAccount($plan['role'], $plan['email'], $region),
                    'reset'  => $this->resetAccount($plan['user'], $plan['role'], $region),
                    default  => [$plan['role']->name, $plan['email'], '—', $plan['preview']],
                };
            }

            return $rows;
        });

        $this->table(['Role', 'Email', 'Password', 'Status'], $rows);
        $this->newLine();
        $this->info('Sign in at ' . rtrim((string) config('services.frontend.url'), '/') . self::LOGIN_PATH);

        if (collect($rows)->contains(fn (array $row) => $row[2] !== '—')) {
            $this->warn($this->sharedPassword !== null
                ? 'Every account issued a password above uses the one passed with --password. Anyone who has it can sign in as those roles — admin included, if it was in this run.'
                : "Store these passwords in the team password manager now — they can't be shown again. Rerun with --reset to issue new ones.");
        }

        return $skipped ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Every role, or the ones named/numbered in --role. Null (after printing
     * why) when a requested role doesn't exist or there are none at all.
     *
     * @return Collection<int, Role>|null
     */
    private function resolveRoles(): ?Collection
    {
        $all = Role::orderBy('id')->get();
        $wanted = array_values(array_filter(array_map('trim', (array) $this->option('role'))));

        if ($wanted === []) {
            if ($all->isEmpty()) {
                $this->error('The roles table is empty — nothing to seed.');

                return null;
            }

            return $all;
        }

        $picked = collect();
        foreach ($wanted as $name) {
            $role = $all->first(fn (Role $r) => (string) $r->id === $name || strcasecmp($r->name, $name) === 0);

            if (! $role) {
                $this->error("Unknown role '{$name}'. Roles: " . $all->map(fn (Role $r) => "{$r->id} {$r->name}")->implode(', '));

                return null;
            }

            $picked->put($role->id, $role);
        }

        return $picked->sortKeys()->values();
    }

    /**
     * What to do for one role: create, exists (left alone), reset, or skip.
     *
     * @return array{role: Role, email: string, action: string, user: ?User, preview: string}
     */
    private function plan(Role $role, string $email, bool $reset): array
    {
        $user = User::with('role')->where('email', $email)->first();
        $plan = ['role' => $role, 'email' => $email, 'user' => $user];

        // POST /login matches users.email OR agents.lr_email and takes the
        // first hit, so an address that is someone's LR email is unusable.
        $lrEmailTaken = Agent::withTrashed()
            ->where('lr_email', $email)
            ->when($user, fn ($q) => $q->where('user_id', '!=', $user->id))
            ->exists();

        if ($lrEmailTaken) {
            return $plan + ['action' => 'skip', 'preview' => "skipped: another user's Leuterio Realty login email"];
        }

        if (! $user) {
            $profile = in_array($role->name, self::ROLES_WITH_AGENT_PROFILE, true) ? ' + agent profile' : '';

            return $plan + ['action' => 'create', 'preview' => "create{$profile}"];
        }

        if ((int) $user->role_id !== (int) $role->id) {
            $current = $user->role?->name ?? "role #{$user->role_id}";

            return $plan + ['action' => 'skip', 'preview' => "skipped: already a {$current} account — left untouched"];
        }

        if ($reset) {
            return $plan + ['action' => 'reset', 'preview' => 'new password, signed out everywhere'];
        }

        $preview = 'exists — password unchanged (use --reset)';
        if (in_array($role->name, self::ROLES_WITH_AGENT_PROFILE, true)) {
            $status = Agent::where('user_id', $user->id)->value('status');
            if ($status !== 'active') {
                $preview .= '; agent profile is ' . ($status ?? 'missing') . ', --reset revives it';
            }
        }

        return $plan + ['action' => 'exists', 'preview' => $preview];
    }

    /** @return array{string, string, string, string} table row */
    private function createAccount(Role $role, string $email, string $region): array
    {
        $password = $this->sharedPassword ?? $this->generatePassword();

        $user = new User([
            'name'               => 'FH Staff ' . Str::headline($role->name),
            'email'              => $email,
            'password'           => $password,
            'role_id'            => $role->id,
            'role_locked'        => true,
            'verification'       => 'verified',
            'admin_emails_muted' => $role->name === 'admin',
        ]);
        $user->forceFill(['email_verified_at' => now()]);
        // One save, labelled first, so the 'created' audit row carries it.
        $this->label($user, "Staff account created for the {$role->name} role (staff:seed-accounts)");
        $user->save();

        $status = 'created';
        if (in_array($role->name, self::ROLES_WITH_AGENT_PROFILE, true)) {
            $agent = $this->newAgentProfile($user, $role, $region);
            $status .= ' + agent profile' . ($agent->region ? " ({$agent->region})" : '');
        }

        return [$role->name, $email, $password, $status];
    }

    /** @return array{string, string, string, string} table row */
    private function resetAccount(User $user, Role $role, string $region): array
    {
        $password = $this->sharedPassword ?? $this->generatePassword();
        $user->password = $password;
        $user->save();

        // Same as POST /logout-all: a session opened with the old password
        // must not outlive it.
        $user->deviceTokens()->delete();
        $user->tokens()->delete();

        $notes = ['new password, signed out everywhere'];

        if (in_array($role->name, self::ROLES_WITH_AGENT_PROFILE, true)) {
            $agent = Agent::withTrashed()->where('user_id', $user->id)->orderBy('id')->first();

            if (! $agent) {
                $agent = $this->newAgentProfile($user, $role, $region);
                $notes[] = 'agent profile added';
            } else {
                $this->label($agent, 'Staff agent profile revived (staff:seed-accounts --reset)');

                if ($agent->trashed()) {
                    $agent->restore();
                    $notes[] = 'agent profile restored';
                }
                // Only undo the dormancy rule. "inactive" / "resigned" are an
                // admin's decision and stay.
                if ($agent->status === 'deactivated') {
                    $agent->status = 'active';
                    $notes[] = 'reactivated';
                } elseif ($agent->status !== 'active') {
                    $notes[] = "agent status '{$agent->status}' left as is";
                    $this->warn("{$user->email}: agent status is '{$agent->status}' — set by an admin, left as is.");
                }
                if (in_array($role->name, self::ROLES_WITH_REGION, true) && $agent->region === null) {
                    $agent->region = $region;
                }
                $agent->save();
            }

            // The signup date is long past by now, so without a fresh "last
            // seen" the next login would put the dormancy rule right back. An
            // hour ago keeps it out of the five-minute "online now" strip.
            if ($agent->status === 'active') {
                $user->forceFill(['last_online_at' => now()->subHour()])->save();
            }
        }

        $this->recordPasswordReset($user);

        return [$role->name, $user->email, $password, implode('; ', $notes)];
    }

    private function newAgentProfile(User $user, Role $role, string $region): Agent
    {
        $agent = new Agent([
            'user_id'              => $user->id,
            'first_name'           => 'FH Staff',
            'last_name'            => Str::headline($role->name),
            'status'               => 'active',
            'region'               => in_array($role->name, self::ROLES_WITH_REGION, true) ? $region : null,
            // There's no LR record to find; holds off the hourly birthday lookup.
            'birthdate_checked_at' => now(),
        ]);
        $this->label($agent, "Staff agent profile for the {$role->name} role (staff:seed-accounts)");
        $agent->save();

        return $agent;
    }

    /**
     * The password is excluded from model auditing, so a rotation would leave
     * no trace at all. Written directly, like AuditAuthService — no secrets.
     */
    private function recordPasswordReset(User $user): void
    {
        Audit::create([
            'event'          => 'password_reset',
            'category'       => 'auth',
            'source'         => self::AUDIT_SOURCE,
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'subject_label'  => $user->name,
            'description'    => "Staff account password reset; {$user->email} signed out of every device (staff:seed-accounts --reset)",
            'old_values'     => null,
            'new_values'     => null,
        ]);
    }

    private function label(User|Agent $model, string $description): void
    {
        $model->auditSource = self::AUDIT_SOURCE;
        $model->auditDescription = $description;
    }

    /** 4 groups of 5 from a 56-character alphabet (~116 bits), dash-separated. */
    private function generatePassword(): string
    {
        $last = strlen(self::PASSWORD_ALPHABET) - 1;
        $groups = [];

        for ($g = 0; $g < 4; $g++) {
            $group = '';
            for ($i = 0; $i < 5; $i++) {
                $group .= self::PASSWORD_ALPHABET[random_int(0, $last)];
            }
            $groups[] = $group;
        }

        return implode('-', $groups);
    }
}
