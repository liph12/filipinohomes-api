<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/** Contact Inbox read/unread is per admin: one admin opening a submission doesn't clear it for the others. */
class InquiryReadStateTest extends TestCase
{
    use CreatesAuthTables;

    private User $ana;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        DB::table('roles')->insert([['id' => 1, 'name' => 'admin']]);
        Schema::table('users', fn (Blueprint $t) => $t->string('avatar')->nullable());
        Schema::create('inquiries', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->text('message');
            $t->string('source')->nullable();
            $t->json('device')->nullable();
            $t->string('country')->nullable();
            $t->string('state')->nullable();
            $t->string('city')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('inquiry_replies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('inquiry_id');
            $t->unsignedBigInteger('admin_user_id');
            $t->timestamps();
        });
        (require database_path('migrations/2026_11_23_000001_create_inquiry_reads_table.php'))->up();
        (require database_path('migrations/2026_11_23_000002_add_done_to_inquiries_table.php'))->up();

        $this->ana = User::forceCreate(['name' => 'Ana Admin', 'email' => 'ana@example.com', 'password' => 'x', 'role_id' => 1]);
        $this->ben = User::forceCreate(['name' => 'Ben Admin', 'email' => 'ben@example.com', 'password' => 'x', 'role_id' => 1]);
    }

    private function inquiry(array $attrs = []): Inquiry
    {
        return Inquiry::withoutAuditing(fn () => Inquiry::create($attrs + ['name' => 'Maria', 'email' => 'maria@example.com', 'message' => 'Hi']));
    }

    private function unreadFor(User $user): int
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/admin/inquiries-unread-count')->assertOk()->json('unread_count');
    }

    public function test_opening_a_submission_only_marks_it_read_for_that_admin(): void
    {
        $first = $this->inquiry();
        $this->inquiry();

        Sanctum::actingAs($this->ana);
        $this->getJson("/api/admin/inquiries/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.read_by.0.name', 'Ana Admin')
            ->assertJsonPath('data.read_at', fn ($v) => $v !== null);

        $this->assertSame(1, $this->unreadFor($this->ana));
        $this->assertSame(2, $this->unreadFor($this->ben));

        // Ben sees it unread for himself, but can see Ana already read it.
        Sanctum::actingAs($this->ben);
        $row = collect($this->getJson('/api/admin/inquiries')->json('data'))->firstWhere('id', $first->id);
        $this->assertNull($row['read_at']);
        $this->assertSame(['Ana Admin'], array_column($row['read_by'], 'name'));
    }

    public function test_mark_all_read_and_mark_unread_are_per_admin(): void
    {
        $one = $this->inquiry();
        $this->inquiry();

        Sanctum::actingAs($this->ana);
        $this->postJson('/api/admin/inquiries/mark-all-read')->assertOk();
        $this->assertSame(0, $this->unreadFor($this->ana));
        $this->assertSame(2, $this->unreadFor($this->ben));

        Sanctum::actingAs($this->ana);
        $this->patchJson("/api/admin/inquiries/{$one->id}/read", ['read' => false])
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.read_at', null)
            ->assertJsonPath('data.read_by', []);

        // Re-opening keeps working after a mark-unread.
        $this->getJson("/api/admin/inquiries/{$one->id}")->assertOk();
        $this->assertSame(0, $this->unreadFor($this->ana));
    }

    public function test_bulk_mark_read_and_unread_only_touch_the_selected_rows_for_this_admin(): void
    {
        $a = $this->inquiry();
        $b = $this->inquiry();
        $this->inquiry();

        Sanctum::actingAs($this->ana);
        $this->getJson("/api/admin/inquiries/{$a->id}")->assertOk();
        $firstRead = DB::table('inquiry_reads')->where(['inquiry_id' => $a->id, 'user_id' => $this->ana->id])->value('read_at');

        $this->travel(5)->minutes();
        $this->postJson('/api/admin/inquiries/read', ['ids' => [$a->id, $b->id], 'read' => true])
            ->assertOk()
            ->assertJsonPath('unread_count', 1);
        // Already-read rows keep their first read time.
        $this->assertSame($firstRead, DB::table('inquiry_reads')->where(['inquiry_id' => $a->id, 'user_id' => $this->ana->id])->value('read_at'));
        $this->assertSame(3, $this->unreadFor($this->ben));

        Sanctum::actingAs($this->ana);
        $this->postJson('/api/admin/inquiries/read', ['ids' => [$a->id, $b->id], 'read' => false])
            ->assertOk()
            ->assertJsonPath('unread_count', 3);

        $this->postJson('/api/admin/inquiries/read', ['ids' => [999], 'read' => true])->assertUnprocessable();
    }

    public function test_unread_count_reports_recent_unread_submissions_for_the_topbar_notice(): void
    {
        $this->travelTo(now()->subDays(10));
        $this->inquiry(['name' => 'Old Sender']);
        $this->travelBack();
        $this->travelTo(now()->subDays(3));
        $older = $this->inquiry(['name' => 'Pedro']);
        $this->travelBack();
        $newest = $this->inquiry(['name' => 'Maria']);

        Sanctum::actingAs($this->ana);
        $this->getJson('/api/admin/inquiries-unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 3)
            ->assertJsonPath('recent.count', 2)
            ->assertJsonPath('recent.days', 5)
            ->assertJsonPath('recent.latest.id', $newest->id)
            ->assertJsonPath('recent.latest.name', 'Maria');

        // Once Ana reads the newest, the notice points at the next unread recent one.
        $this->getJson("/api/admin/inquiries/{$newest->id}")->assertOk();
        $this->getJson('/api/admin/inquiries-unread-count')
            ->assertJsonPath('recent.count', 1)
            ->assertJsonPath('recent.latest.id', $older->id);

        $this->postJson('/api/admin/inquiries/mark-all-read')->assertOk();
        $this->getJson('/api/admin/inquiries-unread-count')
            ->assertJsonPath('recent.count', 0)
            ->assertJsonPath('recent.latest', null);
    }

    public function test_mark_as_done_is_shared_and_records_who_did_it(): void
    {
        $inquiry = $this->inquiry();

        Sanctum::actingAs($this->ana);
        $this->patchJson("/api/admin/inquiries/{$inquiry->id}/done", ['done' => true])
            ->assertOk()
            ->assertJsonPath('data.done_by.name', 'Ana Admin')
            ->assertJsonPath('data.done_at', fn ($v) => $v !== null);

        // Ben sees it done by Ana, in the list and on the detail page; marking again keeps Ana.
        Sanctum::actingAs($this->ben);
        $this->assertSame('Ana Admin', $this->getJson('/api/admin/inquiries')->json('data.0.done_by.name'));
        $this->getJson("/api/admin/inquiries/{$inquiry->id}")->assertJsonPath('data.done_by.name', 'Ana Admin');
        $this->patchJson("/api/admin/inquiries/{$inquiry->id}/done", ['done' => true])->assertJsonPath('data.done_by.name', 'Ana Admin');

        $this->patchJson("/api/admin/inquiries/{$inquiry->id}/done", ['done' => false])
            ->assertOk()
            ->assertJsonPath('data.done_at', null)
            ->assertJsonPath('data.done_by', null);
    }

    public function test_submissions_read_before_per_admin_tracking_stay_read_until_marked_unread(): void
    {
        $old = $this->inquiry(['read_at' => now()->subDay()]);
        $this->inquiry();

        $this->assertSame(1, $this->unreadFor($this->ana));
        $this->assertSame(1, $this->unreadFor($this->ben));

        Sanctum::actingAs($this->ben);
        $this->patchJson("/api/admin/inquiries/{$old->id}/read", ['read' => false])->assertOk();
        $this->assertSame(2, $this->unreadFor($this->ben));
        $this->assertSame(1, $this->unreadFor($this->ana));

        // Mark all read covers the legacy row Ben marked unread too.
        Sanctum::actingAs($this->ben);
        $this->postJson('/api/admin/inquiries/mark-all-read')->assertOk();
        $this->assertSame(0, $this->unreadFor($this->ben));
    }
}
