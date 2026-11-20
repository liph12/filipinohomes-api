<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MailHealthService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/** System Settings → Email Health: are emails going out, and if not, why. */
class MailHealthTest extends TestCase
{
    use CreatesAuthTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthTables();
        DB::table('roles')->insert([['id' => 1, 'name' => 'admin'], ['id' => 3, 'name' => 'client']]);
        Sanctum::actingAs(User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'role_id' => 1]));
    }

    private function sent(int $minutesAgo): void
    {
        DB::table('audits')->insert(['event' => 'mailer_sent', 'category' => 'mailer', 'source' => 'LoginOtpMailer', 'new_values' => '{}',
            'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()]);
    }

    private function failed(int $minutesAgo, string $reply): void
    {
        DB::table('audits')->insert(['event' => 'mailer_failed', 'category' => 'mailer', 'source' => 'App\Mail\LoginOtpMailer',
            'new_values' => json_encode(['smtp_response' => $reply, 'mailable_class' => 'App\Mail\LoginOtpMailer']),
            'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()]);
    }

    /** The real replies seen on production, each to its reason. */
    public function test_it_names_the_reason_from_the_servers_reply(): void
    {
        $health = new MailHealthService;
        $cases = [
            'Expected response code "250" but got code "554", with message "554 5.7.1 Outbound sending is disabled for this account".' => 'blocked',
            'Expected response code "250" but got code "451", with message "451 4.7.1 Ratelimit "hostinger_out_ratelimit" exceeded for key "x"".' => 'limit',
            '550-5.4.5 Daily user sending limit exceeded.' => 'limit',
            'Failed to authenticate on SMTP server with username "x" using 2 possible authenticators. Authenticator LOGIN returned Expected response code "235" but got code "535", with message "535 5.7.8 Error: authentication failed".' => 'auth',
            'Connection to "ssl://smtp.hostinger.com:465" has been closed unexpectedly.' => 'unreachable',
            'Connection could not be established with host "ssl://smtp.hostinger.com:465": stream_socket_client(): Unable to connect' => 'unreachable',
            'Expected response code "250/251/252" but got code "504", with message "504 5.5.2 <bob>: Recipient address rejected: need fully-qualified address".' => 'recipient',
            'Something new went wrong' => 'other',
        ];
        foreach ($cases as $reply => $reason) {
            $this->assertSame($reason, $health->classify($reply), $reply);
        }
    }

    public function test_it_says_which_smtp_account_sends(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.username' => 'it@example.com', 'mail.mailers.smtp.host' => 'smtp.example.com']);

        $this->getJson('/api/admin/mail-health')->assertOk()
            ->assertJsonPath('sending_via.account', 'it@example.com')
            ->assertJsonPath('sending_via.host', 'smtp.example.com');
    }

    public function test_blocked_with_nothing_sent_since_is_the_status(): void
    {
        $this->sent(300);
        $this->failed(20, 'Expected response code "250" but got code "554", with message "554 5.7.1 Outbound sending is disabled for this account".');
        $this->failed(10, 'Expected response code "250" but got code "554", with message "554 5.7.1 Outbound sending is disabled for this account".');

        $this->getJson('/api/admin/mail-health')->assertOk()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('cause.reason', 'blocked')
            ->assertJsonPath('cause.reply', '554 5.7.1 Outbound sending is disabled for this account')
            ->assertJsonPath('cause.email', 'LoginOtpMailer')
            ->assertJsonPath('sent', 1)
            ->assertJsonPath('failed', 2)
            ->assertJsonPath('reasons.0.reason', 'blocked')
            ->assertJsonPath('reasons.0.count', 2);
    }

    public function test_sends_after_a_recent_failure_read_as_partial_then_ok_once_it_is_older(): void
    {
        $this->failed(30, '451 4.7.1 Ratelimit "hostinger_out_ratelimit" exceeded');
        $this->sent(5);
        $this->getJson('/api/admin/mail-health')->assertOk()->assertJsonPath('status', 'partial')->assertJsonPath('cause.reason', 'limit');

        $this->travel(2)->hours();
        $this->sent(1);
        $this->getJson('/api/admin/mail-health')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('cause', null);
    }

    public function test_a_bad_recipient_alone_is_not_an_account_problem(): void
    {
        $this->sent(60);
        $this->failed(1, '504 5.5.2 <bob>: Recipient address rejected: need fully-qualified address');

        $this->getJson('/api/admin/mail-health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reasons.0.reason', 'recipient');
    }

    public function test_nothing_in_24_hours_is_idle_and_only_admins_can_look(): void
    {
        $this->sent(60 * 30);
        $this->getJson('/api/admin/mail-health')->assertOk()->assertJsonPath('status', 'idle');

        Sanctum::actingAs(User::forceCreate(['name' => 'Client', 'email' => 'c@example.com', 'password' => 'x', 'role_id' => 3]));
        $this->getJson('/api/admin/mail-health')->assertForbidden();
    }
}
