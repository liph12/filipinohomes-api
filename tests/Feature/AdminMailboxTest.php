<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\MailboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
}
