<?php

namespace Tests\Feature;

use App\Mail\Transport\FailoverTransport;
use App\Mail\Transport\MailFailoverException;
use App\Models\User;
use App\Services\AuditMailService;
use App\Services\ExpoPushService;
use App\Services\MailHealthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
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
        // This server sends through Gmail.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.googlemail.com', 'mail.mailers.smtp.username' => 'it@gmail.com']);
        FailoverTransport::$last = ['via' => null, 'failures' => []];
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->longText('value')->nullable();
            $t->timestamps();
        });
    }

    /**
     * MAIL_MAILER=failover with two fake accounts: Gmail and Hostinger, each
     * delivering (array transport) or refusing with the given reply.
     */
    private function failover(?string $gmailRefuses = null, ?string $hostingerRefuses = null): void
    {
        Mail::extend('refuse', fn (array $config) => new class($config['reply']) implements TransportInterface
        {
            public function __construct(private string $reply) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException($this->reply);
            }

            public function __toString(): string
            {
                return 'refuse://';
            }
        });
        $account = fn (?string $refuses, string $host, string $user, string $label) => [
            'transport' => $refuses === null ? 'array' : 'refuse', 'reply' => $refuses,
            'host' => $host, 'username' => $user, 'label' => $label,
        ];
        config([
            'mail.default' => 'failover',
            'mail.mailers.gmail' => $account($gmailRefuses, 'smtp.googlemail.com', 'it@gmail.com', 'Gmail'),
            'mail.mailers.hostinger' => $account($hostingerRefuses, 'smtp.hostinger.com', 'info@filipinohomes.com', 'Hostinger'),
            'mail.mailers.failover' => ['transport' => 'fh_failover', 'mailers' => ['gmail', 'hostinger'], 'retry_after' => 60],
        ]);
        Mail::purge('failover');
    }

    /** Sends one email the way the app does: failures are caught and logged by the call site. */
    private function send(): void
    {
        try {
            Mail::raw('hi', fn ($m) => $m->to('a@example.com')->subject('Hi'));
        } catch (TransportException $e) {
            app(AuditMailService::class)->recordFailure($e, 'App\Mail\LoginOtpMailer', ['a@example.com'], 'Hi');
        }
    }

    private function lastRow(string $event): array
    {
        return json_decode(DB::table('audits')->where('event', $event)->orderByDesc('id')->value('new_values'), true);
    }

    private const GMAIL = 'smtp.googlemail.com - it@gmail.com';

    private const HOSTINGER = 'smtp.hostinger.com - info@filipinohomes.com';

    private function sent(int $minutesAgo, ?string $smtp = self::GMAIL): void
    {
        DB::table('audits')->insert(['event' => 'mailer_sent', 'category' => 'mailer', 'source' => 'LoginOtpMailer',
            'new_values' => json_encode($smtp === null ? [] : ['smtp' => $smtp]),
            'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()]);
    }

    private function failed(int $minutesAgo, string $reply, ?string $smtp = self::GMAIL): void
    {
        DB::table('audits')->insert(['event' => 'mailer_failed', 'category' => 'mailer', 'source' => 'App\Mail\LoginOtpMailer',
            'new_values' => json_encode(array_filter(['smtp_response' => $reply, 'mailable_class' => 'App\Mail\LoginOtpMailer', 'smtp' => $smtp])),
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
            'Expected response code "250" but got code "553", with message "553 5.7.1 <me@gmail.com>: Sender address rejected: not owned by user info@filipinohomes.com".' => 'sender',
            'Connection to "ssl://smtp.hostinger.com:465" has been closed unexpectedly.' => 'unreachable',
            'Connection could not be established with host "ssl://smtp.hostinger.com:465": stream_socket_client(): Unable to connect' => 'unreachable',
            'Expected response code "250/251/252" but got code "504", with message "504 5.5.2 <bob>: Recipient address rejected: need fully-qualified address".' => 'recipient',
            'Something new went wrong' => 'other',
        ];
        foreach ($cases as $reply => $reason) {
            $this->assertSame($reason, $health->classify($reply), $reply);
        }
    }

    /** Two servers, two .env accounts, one log: the status is this server's; the other is reported beside it. */
    public function test_another_servers_account_does_not_colour_this_ones_status(): void
    {
        $this->sent(5);
        $this->sent(3);
        $this->failed(2, '554 5.7.1 Outbound sending is disabled for this account', self::HOSTINGER);
        $this->sent(1, self::HOSTINGER);
        // From before the account was logged: ignored.
        $this->failed(1, '554 5.7.1 Outbound sending is disabled for this account', null);

        $this->getJson('/api/admin/mail-health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('sent', 2)
            ->assertJsonPath('failed', 0)
            ->assertJsonPath('reasons', [])
            ->assertJsonPath('other_accounts.0.smtp', self::HOSTINGER)
            ->assertJsonPath('other_accounts.0.sent', 1)
            ->assertJsonPath('other_accounts.0.failed', 1)
            ->assertJsonPath('other_accounts.0.last_reason', 'blocked')
            ->assertJsonPath('other_accounts.0.last_reply', '554 5.7.1 Outbound sending is disabled for this account');
    }

    public function test_every_logged_email_records_its_smtp_account(): void
    {
        config(['mail.mailers.smtp.transport' => 'array']);
        Mail::raw('hi', fn ($m) => $m->to('a@example.com')->subject('Hi'));

        $row = DB::table('audits')->where('event', 'mailer_sent')->first();
        $this->assertSame(self::GMAIL, json_decode($row->new_values, true)['smtp']);
    }

    public function test_it_lists_the_one_smtp_account_this_server_sends_with(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.username' => 'it@example.com', 'mail.mailers.smtp.host' => 'smtp.example.com']);

        $this->getJson('/api/admin/mail-health')->assertOk()
            ->assertJsonPath('failover.enabled', false)
            ->assertJsonPath('failover.can_choose_first', false)
            ->assertJsonCount(1, 'accounts')
            ->assertJsonPath('accounts.0.smtp', 'smtp.example.com - it@example.com');
        $this->putJson('/api/admin/mail-health/first', ['mailer' => 'smtp'])->assertStatus(422);
    }

    public function test_blocked_with_nothing_sent_since_means_emails_are_down(): void
    {
        $this->sent(300);
        $this->failed(20, 'Expected response code "250" but got code "554", with message "554 5.7.1 Outbound sending is disabled for this account".');
        $this->failed(10, 'Expected response code "250" but got code "554", with message "554 5.7.1 Outbound sending is disabled for this account".');

        $this->getJson('/api/admin/mail-health')->assertOk()
            ->assertJsonPath('status', 'down')
            ->assertJsonPath('accounts.0.status', 'blocked')
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

    public function test_when_gmail_fails_the_email_goes_out_through_hostinger_and_both_are_logged(): void
    {
        $this->failover(gmailRefuses: 'Expected response code "250" but got code "550", with message "550-5.4.5 Daily user sending limit exceeded."');

        $this->send();

        $row = $this->lastRow('mailer_sent');
        $this->assertSame('smtp.hostinger.com - info@filipinohomes.com', $row['smtp']);
        $this->assertSame('smtp.googlemail.com - it@gmail.com', $row['failed_over'][0]['smtp']);
        $this->assertSame(0, DB::table('audits')->where('event', 'mailer_failed')->count());

        $this->getJson('/api/admin/mail-health')->assertOk()
            ->assertJsonPath('status', 'fallback')
            ->assertJsonPath('cause.account', 'Gmail')
            ->assertJsonPath('cause.reason', 'limit')
            ->assertJsonPath('sent', 1)
            ->assertJsonPath('failed', 0)
            ->assertJsonPath('accounts.0.label', 'Gmail')
            ->assertJsonPath('accounts.0.status', 'limit')
            ->assertJsonPath('accounts.0.rescued', 1)
            ->assertJsonPath('accounts.1.label', 'Hostinger')
            ->assertJsonPath('accounts.1.status', 'ok')
            ->assertJsonPath('reasons.0.account', 'Gmail')
            ->assertJsonPath('reasons.0.rescued', 1)
            ->assertJsonPath('other_accounts', []);

        // Gmail just failed: the next email goes straight to Hostinger for a minute.
        $this->send();
        $this->assertArrayNotHasKey('failed_over', $this->lastRow('mailer_sent'));
    }

    public function test_when_every_account_fails_emails_are_down_with_each_accounts_reason_and_admins_are_alerted_once(): void
    {
        $this->failover(
            gmailRefuses: '550-5.4.5 Daily user sending limit exceeded.',
            hostingerRefuses: 'Expected response code "250" but got code "554", with message "554 5.7.1 Outbound sending is disabled for this account".',
        );
        $this->mock(ExpoPushService::class)->shouldReceive('notify')
            ->once()
            ->withArgs(fn ($user, $type, $title, $body) => $type === 'mail_down'
                && str_contains($body, 'Gmail: sending limit reached')
                && str_contains($body, 'Hostinger: blocked by the email provider'));

        $this->send();
        $this->send(); // a second failure doesn't alert again
        app(DeferredCallbackCollection::class)->invoke();

        $row = $this->lastRow('mailer_failed');
        $this->assertSame(MailFailoverException::class, $row['exception_class']);
        $this->assertCount(2, $row['attempts']);

        $this->getJson('/api/admin/mail-health')->assertOk()
            ->assertJsonPath('status', 'down')
            ->assertJsonPath('failed', 2)
            ->assertJsonPath('cause.tries.0.account', 'Gmail')
            ->assertJsonPath('cause.tries.0.reason', 'limit')
            ->assertJsonPath('cause.tries.1.account', 'Hostinger')
            ->assertJsonPath('cause.tries.1.reason', 'blocked')
            ->assertJsonPath('accounts.0.status', 'limit')
            ->assertJsonPath('accounts.1.status', 'blocked');
    }

    public function test_a_bad_recipient_on_every_account_does_not_alert_admins(): void
    {
        $this->failover(gmailRefuses: '550 5.1.1 user unknown', hostingerRefuses: '550 5.1.1 user unknown');
        $this->mock(ExpoPushService::class)->shouldNotReceive('notify');

        $this->send();
        app(DeferredCallbackCollection::class)->invoke();

        $this->getJson('/api/admin/mail-health')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_admins_choose_which_account_goes_first(): void
    {
        $this->failover();
        $this->getJson('/api/admin/mail-health')->assertOk()
            ->assertJsonPath('failover.can_choose_first', true)
            ->assertJsonPath('failover.chosen', false)
            ->assertJsonPath('accounts.0.name', 'gmail');

        $this->putJson('/api/admin/mail-health/first', ['mailer' => 'hostinger'])->assertOk()
            ->assertJsonPath('failover.chosen', true)
            ->assertJsonPath('accounts.0.name', 'hostinger')
            ->assertJsonPath('accounts.1.name', 'gmail');
        $this->putJson('/api/admin/mail-health/first', ['mailer' => 'log'])->assertUnprocessable();

        $this->send();
        $this->assertSame('smtp.hostinger.com - info@filipinohomes.com', $this->lastRow('mailer_sent')['smtp']);

        Sanctum::actingAs(User::forceCreate(['name' => 'Client', 'email' => 'c2@example.com', 'password' => 'x', 'role_id' => 3]));
        $this->putJson('/api/admin/mail-health/first', ['mailer' => 'gmail'])->assertForbidden();
    }
}
