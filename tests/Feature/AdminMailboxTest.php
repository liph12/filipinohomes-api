<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\MailboxMessage;
use App\Models\User;
use App\Services\Mailbox\MailboxService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * Admin Mailbox (/api/admin/mailbox/*) — the info@ inbox. Admin-only, and it
 * runs on its own MAILBOX_* credentials so switching MAIL_* never breaks it.
 * The IMAP server itself isn't reachable from tests; these cover the gate,
 * the not-configured state and input validation.
 */
class AdminMailboxTest extends TestCase
{
    use CreatesAuthTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'admin'],
            ['id' => 2, 'name' => 'agent'],
            ['id' => 3, 'name' => 'client'],
            ['id' => 4, 'name' => 'editor'],
            ['id' => 5, 'name' => 'secretary'],
        ]);
        config(['mailbox.password' => null]);
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'name' => ucfirst($role).' User',
            'email' => uniqid($role, true).'@example.com',
            'password' => 'secret',
            'role_id' => DB::table('roles')->where('name', $role)->value('id'),
        ]);

        if (in_array($role, ['agent', 'secretary'], true)) {
            Agent::forceCreate([
                'user_id' => $user->id,
                'first_name' => 'Test',
                'last_name' => ucfirst($role),
                'status' => 'active',
                'region' => $role === 'secretary' ? 'cebu' : null,
            ]);
        }

        return $user;
    }

    public function test_guests_cannot_open_the_mailbox(): void
    {
        $this->getJson('/api/admin/mailbox/folders')->assertUnauthorized();
    }

    public function test_only_admins_can_open_the_mailbox(): void
    {
        foreach (['agent', 'client', 'editor', 'secretary'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/admin/mailbox/folders')->assertForbidden();
            $this->postJson('/api/admin/mailbox/send', [])->assertForbidden();
        }
    }

    public function test_admin_sees_not_configured_without_a_password(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->getJson('/api/admin/mailbox/folders')
            ->assertOk()
            ->assertJson(['configured' => false, 'address' => 'info@filipinohomes.com', 'folders' => []]);
    }

    public function test_listing_rejects_unknown_folders(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->getJson('/api/admin/mailbox/messages?folder=../etc')->assertUnprocessable();
    }

    public function test_only_website_inquiry_categories_are_allowed(): void
    {
        Sanctum::actingAs($this->user('admin'));

        foreach (['all', 'other', 'spam'] as $category) {
            $this->getJson('/api/admin/mailbox/messages?folder=inbox&category='.$category)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['category']);
        }
    }

    public function test_send_validates_recipients(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->postJson('/api/admin/mailbox/send', [
            'to' => ['not-an-email'],
            'subject' => 'Re: Hi',
            'mail_body' => 'Hello',
            'mode' => 'reply',
            'ref_folder' => 'inbox',
            'ref_uid' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['to.0']);
    }

    public function test_mailbox_only_sends_replies(): void
    {
        Sanctum::actingAs($this->user('admin'));

        // A brand-new message (no email being answered) is refused…
        $this->postJson('/api/admin/mailbox/send', [
            'to' => ['someone@example.com'],
            'subject' => 'Hello',
            'mail_body' => 'Hi',
            'mode' => 'new',
        ])->assertUnprocessable()->assertJsonValidationErrors(['mode', 'ref_folder', 'ref_uid']);

        // …and so is forwarding.
        $this->postJson('/api/admin/mailbox/send', [
            'to' => ['someone@example.com'],
            'subject' => 'Fwd: Hello',
            'mode' => 'forward',
            'ref_folder' => 'inbox',
            'ref_uid' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['mode']);
    }

    /** Database mode (Option B): fresh index → reads come from mailbox_messages, no mail server. */
    private function seedIndex(): void
    {
        (require base_path('database/migrations/2026_11_10_000001_create_mailbox_messages_table.php'))->up();
        (require base_path('database/migrations/2026_11_10_000002_add_chat_link_to_mailbox_messages.php'))->up();
        (require base_path('database/migrations/2026_11_11_000001_add_archive_to_mailbox_messages.php'))->up();
        config(['mailbox.password' => 'x', 'mailbox.username' => 'info@filipinohomes.com', 'mailbox.imap.host' => 'imap.hostinger.com']);
        $account = substr(md5('info@filipinohomes.com|imap.hostinger.com'), 0, 10);
        Cache::forever('mailbox:'.$account.':synced_at', now()->toIso8601String());
        Cache::forever('mailbox:'.$account.':mailbox:folders:v1', ['inbox' => ['path' => 'INBOX', 'delimiter' => '.']]);

        $row = fn (int $uid, string $subject, string $category, ?string $mid, bool $primary = true, bool $seen = false) => [
            'account' => $account, 'folder' => 'inbox', 'folder_path' => 'INBOX', 'uidvalidity' => 1, 'uid' => $uid,
            'message_id' => $mid, 'is_primary' => $primary, 'category' => $category, 'from_name' => 'FH Support Team',
            'from_email' => 'info@filipinohomes.com', 'to' => '[]', 'subject' => $subject, 'sent_at' => now(),
            'seen' => $seen, 'flagged' => false, 'answered' => false, 'created_at' => now(), 'updated_at' => now(),
        ];
        MailboxMessage::insert([
            $row(10, 'New Contact Us Submission from Kahlil', 'contact_us', '<a@x>'),
            $row(11, 'New Contact Us Submission from Kahlil', 'contact_us', '<a@x>', false), // hidden copy
            $row(12, '[Filipino Homes] New inquiry pending review — Lot', 'listing', '<b@x>', true, true),
        ]);
    }

    public function test_index_mode_lists_counts_and_hides_copies_without_the_mail_server(): void
    {
        $this->seedIndex();
        Sanctum::actingAs($this->user('admin'));

        $this->getJson('/api/admin/mailbox/messages?folder=inbox&category=website')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/mailbox/messages?folder=inbox&category=contact_us&q=Kahlil')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.uid', 10);

        $cats = collect($this->getJson('/api/admin/mailbox/categories')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame(2, $cats['website']['total']);
        $this->assertSame(1, $cats['website']['unread']);
        $this->assertSame(1, $cats['contact_us']['total']);
    }

    public function test_index_mode_marks_read_in_the_database_and_rejects_unknown_mail(): void
    {
        $this->seedIndex();
        Sanctum::actingAs($this->user('admin'));

        $this->postJson('/api/admin/mailbox/flag', ['folder' => 'inbox', 'uids' => [10], 'flag' => 'seen', 'value' => true])->assertOk();
        // The email and its hidden copy are both read now.
        $this->assertSame(2, MailboxMessage::whereIn('uid', [10, 11])->where('seen', true)->count());

        // A UID that isn't an indexed website email is "not found".
        $this->postJson('/api/admin/mailbox/flag', ['folder' => 'inbox', 'uids' => [999], 'flag' => 'seen', 'value' => true])->assertNotFound();
    }

    public function test_mailbox_mailer_ignores_mail_settings(): void
    {
        // The mailbox SMTP must not follow MAIL_HOST / MAIL_USERNAME.
        $mailer = config('mail.mailers.mailbox');

        $this->assertSame('smtp', $mailer['transport']);
        $this->assertSame('smtp.hostinger.com', $mailer['host']);
        $this->assertSame('info@filipinohomes.com', $mailer['username']);
        $this->assertNotSame(config('mail.mailers.smtp.username'), $mailer['username']);
    }

    // ── Archive (old website emails → S3, removed from the mail server) ──

    private function archiveRow(int $uid, string $sentAt, array $extra = []): array
    {
        $account = substr(md5('info@filipinohomes.com|imap.hostinger.com'), 0, 10);

        return $extra + [
            'account' => $account, 'folder' => 'inbox', 'folder_path' => 'INBOX', 'uidvalidity' => 1, 'uid' => $uid,
            'message_id' => '<m'.$uid.'@x>', 'is_primary' => true, 'category' => 'listing', 'from_name' => 'FH',
            'from_email' => 'info@filipinohomes.com', 'to' => '[]', 'subject' => '[Filipino Homes] New inquiry pending review',
            'sent_at' => Carbon::parse($sentAt, 'Asia/Manila')->utc(), 'seen' => false, 'flagged' => false, 'answered' => false,
            'created_at' => now(), 'updated_at' => now(), 'archived_at' => null, 'archive_path' => null,
        ];
    }

    public function test_archive_periods_are_yearly_then_quarterly_one_month_after_the_quarter(): void
    {
        $this->seedIndex();
        $mailbox = app(MailboxService::class);

        $this->assertSame('2025', $mailbox->archivePeriod(Carbon::parse('2025-12-31 23:00', 'Asia/Manila')));
        $this->assertSame('2026-Q1', $mailbox->archivePeriod(Carbon::parse('2026-01-01 00:30', 'Asia/Manila')));
        $this->assertSame('2026-Q3', $mailbox->archivePeriod(Carbon::parse('2026-09-30 23:59', 'Asia/Manila')));
        $this->assertSame('2026-05-01', $mailbox->archiveReadyAt('2026-Q1')->setTimezone('Asia/Manila')->toDateString());
        $this->assertSame('2026-11-01', $mailbox->archiveReadyAt('2026-Q3')->setTimezone('Asia/Manila')->toDateString());
        $this->assertSame('2027-02-01', $mailbox->archiveReadyAt('2026-Q4')->setTimezone('Asia/Manila')->toDateString());
        $this->assertSame('2026-02-01', $mailbox->archiveReadyAt('2025')->setTimezone('Asia/Manila')->toDateString());

        MailboxMessage::query()->delete();
        MailboxMessage::insert([
            $this->archiveRow(1, '2026-05-02 10:00'),               // Q2 → due Aug 1
            $this->archiveRow(2, '2026-09-30 23:30'),               // Q3 (Manila) → due Nov 1
            $this->archiveRow(3, '2026-10-01 08:00'),               // Q4 → due Feb 1 2027
            $this->archiveRow(4, '2026-05-03 10:00', ['archived_at' => now()]), // already archived
            $this->archiveRow(5, '2026-05-02 10:00', ['is_primary' => false, 'message_id' => '<m1@x>']), // copy of 1
        ]);

        $due = fn (string $now) => $mailbox->archivePlan(Carbon::parse($now, 'Asia/Manila'))->pluck('uid')->sort()->values()->all();
        $this->assertSame([1, 5], $due('2026-10-31 23:59'));   // Q3 not yet
        $this->assertSame([1, 2, 5], $due('2026-11-01 00:00')); // Q3 from Nov 1
        $this->assertSame([1, 2, 3, 5], $due('2027-02-01 00:00'));
    }

    public function test_archived_email_opens_from_s3_and_stays_put(): void
    {
        $this->seedIndex();
        Storage::fake('s3');
        $raw = "From: FH <info@filipinohomes.com>\r\nTo: client@example.com\r\nSubject: [Filipino Homes] New inquiry pending review\r\n"
            ."Message-ID: <m20@x>\r\nDate: Sat, 2 May 2026 10:00:00 +0800\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n"
            ."Hello from the archive\r\n";
        Storage::disk('s3')->put('archive/m20.eml.gz', gzencode($raw));
        MailboxMessage::insert([$this->archiveRow(20, '2026-05-02 10:00', ['archived_at' => now(), 'archive_path' => 'archive/m20.eml.gz'])]);
        Sanctum::actingAs($this->user('admin'));

        // Opened from S3 (the fake mail-server login would fail if it were tried).
        $this->getJson('/api/admin/mailbox/messages/20?folder=inbox')
            ->assertOk()->assertJsonPath('data.archived', true);
        $this->assertStringContainsString('Hello from the archive', (string) MailboxMessage::where('uid', 20)->first()->detail['html']);
        $this->getJson('/api/admin/mailbox/messages?folder=inbox&category=website')->assertOk()->assertJsonFragment(['uid' => 20, 'archived' => true]);

        // Read/star live in the database; moving is refused.
        $this->postJson('/api/admin/mailbox/flag', ['folder' => 'inbox', 'uids' => [20], 'flag' => 'flagged', 'value' => true])->assertOk();
        $this->assertTrue((bool) MailboxMessage::where('uid', 20)->value('flagged'));
        $this->postJson('/api/admin/mailbox/move', ['folder' => 'inbox', 'uids' => [20], 'target' => 'archive'])->assertStatus(422);
    }

    public function test_deleting_in_the_mailbox_never_touches_the_s3_archive(): void
    {
        $this->seedIndex();
        Storage::fake('s3');
        $account = substr(md5('info@filipinohomes.com|imap.hostinger.com'), 0, 10);
        Cache::forever('mailbox:'.$account.':mailbox:folders:v1', [
            'inbox' => ['path' => 'INBOX', 'delimiter' => '.'],
            'trash' => ['path' => 'INBOX.Trash', 'delimiter' => '.'],
        ]);
        Storage::disk('s3')->put('archive/m30.eml.gz', gzencode("Subject: x\r\n\r\nx"));
        MailboxMessage::insert([$this->archiveRow(30, '2026-05-02 10:00', [
            'folder' => 'trash', 'folder_path' => 'INBOX.Trash', 'archived_at' => now(), 'archive_path' => 'archive/m30.eml.gz',
        ])]);
        Sanctum::actingAs($this->user('admin'));

        // Delete forever in Trash is refused for archived emails; the S3 file and the row stay.
        $this->postJson('/api/admin/mailbox/delete', ['folder' => 'trash', 'uids' => [30]])->assertStatus(422);
        Storage::disk('s3')->assertExists('archive/m30.eml.gz');
        $this->assertTrue(MailboxMessage::where('uid', 30)->exists());
    }

    public function test_archive_command_does_nothing_unless_switched_on(): void
    {
        $this->seedIndex();
        config(['mailbox.archive.enabled' => false]);

        $this->artisan('mailbox:archive')->expectsOutputToContain('Archive is off')->assertSuccessful();
    }
}
