<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use App\Services\AuditAuthService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * php artisan staff:seed-accounts — one password-login account per FH role,
 * credentials printed once.
 *
 * setUp() inserts the roles with DB::table and never touches the User or
 * Agent model: owen-it decides whether to audit a model class when it first
 * boots, and the command can only switch console auditing on if it gets
 * there first. Tests that pre-seed users use DB::table for the same reason.
 */
class SeedStaffAccountsTest extends TestCase
{
    use CreatesAuthTables;

    private const ROLES = [1 => 'admin', 2 => 'agent', 3 => 'client', 4 => 'editor', 5 => 'secretary'];

    private const PASSWORD_SHAPE = '/[A-Za-z0-9]{5}-[A-Za-z0-9]{5}-[A-Za-z0-9]{5}-[A-Za-z0-9]{5}/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        foreach (self::ROLES as $id => $name) {
            DB::table('roles')->insert(['id' => $id, 'name' => $name]);
        }
    }

    /** @return array{int, string} exit code, output */
    private function seedAccounts(array $options = []): array
    {
        $exit = Artisan::call('staff:seed-accounts', $options);

        return [$exit, Artisan::output()];
    }

    private function email(string $role): string
    {
        return "staff.{$role}@filipinohomes.com";
    }

    private function passwordFor(string $output, string $email): string
    {
        $found = preg_match('/\|\s*' . preg_quote($email, '/') . '\s*\|\s*(\S+)\s*\|/', $output, $m);
        $this->assertSame(1, $found, "No table row for {$email}");

        return $m[1];
    }

    private function login(string $email, string $password)
    {
        return $this->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    public function test_it_creates_one_account_per_role_with_a_working_printed_password(): void
    {
        [$exit, $output] = $this->seedAccounts();

        $this->assertSame(0, $exit, $output);
        foreach (self::ROLES as $id => $role) {
            $user = User::where('email', $this->email($role))->first();
            $this->assertNotNull($user, "{$role} account missing");
            $this->assertSame($id, (int) $user->role_id);

            $password = $this->passwordFor($output, $this->email($role));
            $this->assertMatchesRegularExpression(self::PASSWORD_SHAPE, $password);
            $this->assertTrue(Hash::check($password, $user->password));
        }
        $this->assertStringContainsString('/staff-login', $output);
    }

    public function test_each_account_gets_what_its_role_needs(): void
    {
        $this->seedAccounts();

        $users = User::with('agent')->get()->keyBy(fn (User $u) => self::ROLES[$u->role_id]);

        $this->assertTrue($users['admin']->admin_emails_muted);
        $this->assertNotNull($users['admin']->agent);
        $this->assertNull($users['admin']->agent->region);
        $this->assertSame('cebu', $users['agent']->agent->region);
        $this->assertSame('cebu', $users['secretary']->agent->region);
        $this->assertNull($users['client']->agent);
        $this->assertNull($users['editor']->agent);

        foreach ($users as $role => $user) {
            $this->assertTrue($user->role_locked, $role);
            $this->assertSame('verified', $user->verification, $role);
            $this->assertNotNull($user->email_verified_at, $role);
            $this->assertNull($user->last_online_at, $role);
            $this->assertFalse($role !== 'admin' && $user->admin_emails_muted, $role);

            if ($user->agent) {
                $this->assertSame('active', $user->agent->status, $role);
                $this->assertNotNull($user->agent->birthdate_checked_at, $role);
                $this->assertNull($user->agent->member_since, $role);
                $this->assertNull($user->agent->lr_email, $role);
            }
        }
    }

    public function test_a_rerun_leaves_existing_accounts_and_passwords_alone(): void
    {
        $this->seedAccounts();
        $hashes = User::pluck('password', 'email');

        [$exit, $output] = $this->seedAccounts();

        $this->assertSame(0, $exit);
        $this->assertSame(5, User::count());
        $this->assertSame(3, Agent::count());
        $this->assertEquals($hashes, User::pluck('password', 'email'));
        $this->assertStringContainsString('password unchanged', $output);
        $this->assertDoesNotMatchRegularExpression(self::PASSWORD_SHAPE, $output);
    }

    public function test_reset_issues_a_new_password_and_signs_the_account_out_everywhere(): void
    {
        [, $first] = $this->seedAccounts();
        $client = User::where('email', $this->email('client'))->first();
        $client->createToken('Chrome on Mac');
        DB::table('device_tokens')->insert(['user_id' => $client->id, 'expo_token' => 'ExponentPushToken[abc]']);
        $adminHash = User::where('email', $this->email('admin'))->value('password');

        [$exit, $output] = $this->seedAccounts(['--role' => ['client'], '--reset' => true]);

        $this->assertSame(0, $exit, $output);
        $old = $this->passwordFor($first, $this->email('client'));
        $new = $this->passwordFor($output, $this->email('client'));
        $this->assertNotSame($old, $new);
        $this->assertTrue(Hash::check($new, $client->fresh()->password));
        $this->assertFalse(Hash::check($old, $client->fresh()->password));
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(0, DB::table('device_tokens')->count());
        $this->assertSame(1, DB::table('audits')->where('event', 'password_reset')->where('auditable_id', $client->id)->count());
        $this->assertSame($adminHash, User::where('email', $this->email('admin'))->value('password'));
    }

    public function test_reset_revives_a_staff_agent_the_dormancy_rule_deactivated(): void
    {
        [, $first] = $this->seedAccounts(['--role' => ['agent']]);
        $email = $this->email('agent');

        $this->travel(50)->days();
        $this->login($email, $this->passwordFor($first, $email))->assertOk();
        $this->assertSame('deactivated', Agent::value('status'));

        [$exit, $output] = $this->seedAccounts(['--role' => ['agent'], '--reset' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertSame('active', Agent::value('status'));
        $this->assertStringContainsString('reactivated', $output);
        $this->assertTrue(User::where('email', $email)->value('last_online_at')->between(now()->subHours(2), now()->subMinutes(30)));

        $this->login($email, $this->passwordFor($output, $email))->assertOk();
        $this->assertSame('active', Agent::value('status'));
    }

    public function test_reset_respects_admin_decisions_on_the_agent_profile(): void
    {
        $this->seedAccounts();
        $agentUserId = User::where('email', $this->email('agent'))->value('id');
        $secretaryUserId = User::where('email', $this->email('secretary'))->value('id');
        DB::table('agents')->where('user_id', $agentUserId)->update(['status' => 'resigned']);
        DB::table('agents')->where('user_id', $secretaryUserId)->update(['region' => 'davao']);

        [$exit, $output] = $this->seedAccounts(['--role' => ['agent', 'secretary'], '--reset' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertSame('resigned', Agent::where('user_id', $agentUserId)->value('status'));
        $this->assertSame('davao', Agent::where('user_id', $secretaryUserId)->value('region'));
        $this->assertStringContainsString("agent status 'resigned' left as is", $output);
    }

    public function test_an_email_that_belongs_to_another_role_is_never_taken_over(): void
    {
        $hash = Hash::make('their-own-password');
        DB::table('users')->insert([
            'name' => 'Someone Else', 'email' => $this->email('agent'), 'password' => $hash, 'role_id' => 3,
        ]);

        [$exit, $output] = $this->seedAccounts(['--reset' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('already a client account', $output);
        $row = DB::table('users')->where('email', $this->email('agent'))->first();
        $this->assertSame(3, (int) $row->role_id);
        $this->assertSame($hash, $row->password);
        $this->assertSame(2, DB::table('agents')->count(), 'admin + secretary profiles only');
        $this->assertSame(5, User::count());
    }

    public function test_an_email_that_is_someone_elses_lr_login_is_refused(): void
    {
        DB::table('users')->insert(['id' => 99, 'name' => 'Real Agent', 'email' => 'real@example.com', 'password' => 'x', 'role_id' => 2]);
        DB::table('agents')->insert([
            'user_id' => 99, 'first_name' => 'Real', 'last_name' => 'Agent', 'lr_email' => $this->email('admin'),
        ]);

        [$exit, $output] = $this->seedAccounts();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("another user's Leuterio Realty login email", $output);
        $this->assertNull(User::where('email', $this->email('admin'))->first());
        $this->assertNotNull(User::where('email', $this->email('client'))->first());
    }

    public function test_production_needs_force_but_a_dry_run_does_not(): void
    {
        $this->app['env'] = 'production';

        [$exit, $output] = $this->seedAccounts();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Refusing to run in production', $output);
        $this->assertSame(0, DB::table('users')->count());

        [$exit] = $this->seedAccounts(['--dry-run' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame(0, DB::table('users')->count());

        [$exit, $output] = $this->seedAccounts(['--force' => true]);
        $this->assertSame(0, $exit, $output);
        $this->assertSame(5, DB::table('users')->count());
    }

    public function test_a_dry_run_writes_nothing_and_prints_no_password(): void
    {
        [$exit, $output] = $this->seedAccounts(['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[DRY RUN]', $output);
        $this->assertStringContainsString('create + agent profile', $output);
        $this->assertDoesNotMatchRegularExpression(self::PASSWORD_SHAPE, $output);
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('agents')->count());
        $this->assertSame(0, DB::table('audits')->count());
    }

    public function test_bad_options_fail_before_anything_is_written(): void
    {
        $bad = [
            ['--role' => ['superuser']],
            ['--email-pattern' => 'staff@filipinohomes.com'],
            ['--email-pattern' => '{role}.{role}@filipinohomes.com'],
            ['--email-pattern' => 'staff {role}@filipinohomes'],
            ['--region' => 'atlantis'],
        ];

        foreach ($bad as $options) {
            [$exit] = $this->seedAccounts($options);
            $this->assertSame(1, $exit, json_encode($options));
        }

        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_a_custom_email_pattern_and_region(): void
    {
        [$exit, $output] = $this->seedAccounts([
            '--email-pattern' => 'QA+{role}@Example.org',
            '--region'        => 'Metro Manila',
            '--role'          => ['secretary', '1'],
        ]);

        $this->assertSame(0, $exit, $output);
        $this->assertSame(['qa+admin@example.org', 'qa+secretary@example.org'], User::orderBy('id')->pluck('email')->all());
        $this->assertSame('metro-manila', User::where('email', 'qa+secretary@example.org')->first()->agent->region);
    }

    public function test_audit_rows_are_labelled_and_never_hold_a_password(): void
    {
        [, $output] = $this->seedAccounts();

        $rows = DB::table('audits')->get();
        $this->assertCount(8, $rows->where('source', 'staff_seeder')->where('event', 'created'), 'five users + three agent profiles');

        preg_match_all(self::PASSWORD_SHAPE, $output, $printed);
        $this->assertCount(5, $printed[0]);
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('password', (string) $row->new_values);
            foreach ($printed[0] as $password) {
                $this->assertStringNotContainsString($password, json_encode($row));
            }
        }
    }

    public function test_seeded_accounts_sign_in_and_the_login_response_names_the_role(): void
    {
        [, $output] = $this->seedAccounts();

        foreach (['admin', 'agent', 'secretary'] as $role) {
            $this->login($this->email($role), $this->passwordFor($output, $this->email($role)))
                ->assertOk()
                ->assertJsonPath('user.role.name', $role)
                ->assertJsonMissingPath('user.verification')
                ->assertJsonStructure(['token']);
        }
    }

    public function test_the_login_response_names_the_role_without_the_audit_side_effect(): void
    {
        [, $output] = $this->seedAccounts(['--role' => ['editor']]);
        // AuditAuthService used to be the only thing loading `role`.
        $this->mock(AuditAuthService::class)->shouldReceive('recordLogin');

        $this->login($this->email('editor'), $this->passwordFor($output, $this->email('editor')))
            ->assertOk()
            ->assertJsonPath('user.role.name', 'editor');
    }

    public function test_a_shared_password_applies_to_every_account(): void
    {
        [$exit, $output] = $this->seedAccounts(['--password' => 'example-shared-pw']);

        $this->assertSame(0, $exit, $output);
        foreach (self::ROLES as $role) {
            $this->assertSame('example-shared-pw', $this->passwordFor($output, $this->email($role)));
            $this->assertTrue(Hash::check('example-shared-pw', User::where('email', $this->email($role))->value('password')), $role);
        }
        $this->assertStringContainsString('uses the one passed with --password', $output);
    }

    public function test_reset_moves_existing_accounts_onto_the_shared_password(): void
    {
        $this->seedAccounts();
        User::where('email', $this->email('agent'))->first()->createToken('Chrome on Mac');

        [$exit, $output] = $this->seedAccounts(['--reset' => true, '--password' => 'example-shared-pw']);

        $this->assertSame(0, $exit, $output);
        foreach (self::ROLES as $role) {
            $this->assertTrue(Hash::check('example-shared-pw', User::where('email', $this->email($role))->value('password')), $role);
        }
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_a_too_short_shared_password_is_refused(): void
    {
        foreach (['short', ''] as $password) {
            [$exit] = $this->seedAccounts(['--password' => $password]);
            $this->assertSame(1, $exit, "'{$password}'");
        }

        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_it_refuses_to_print_passwords_nobody_will_see(): void
    {
        try {
            foreach (['--quiet', '--silent'] as $flag) {
                [$exit] = $this->seedAccounts([$flag => true]);
                $this->assertSame(1, $exit, $flag);
            }
        } finally {
            // Symfony keeps the verbosity in SHELL_VERBOSITY for the rest of
            // the process; every later Artisan::call() would come back silent.
            putenv('SHELL_VERBOSITY=0');
            $_ENV['SHELL_VERBOSITY'] = $_SERVER['SHELL_VERBOSITY'] = 0;
        }

        $this->assertSame(0, DB::table('users')->count());
    }
}
