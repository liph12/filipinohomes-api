<?php

namespace Tests\Feature;

use App\Http\Controllers\ListingController;
use App\Http\Controllers\ListingInquiryController;
use App\Models\Role;
use App\Models\User;
use App\Support\RolePreview;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * The admin "Change Role" preview: an admin previews another role's dashboard
 * while signed in as themselves, and the data endpoints scope to that role's
 * view of the ADMIN'S OWN account.
 *
 * The property everything rests on is that X-Preview-Role can only NARROW a
 * response. It is honoured for a real admin only, and only for roles that see
 * a strict subset of what an admin sees — so a non-admin sending
 * `X-Preview-Role: admin` must get exactly their own scope back. These tests
 * hold that line; if one goes red, a client-supplied header is influencing
 * scope for someone it shouldn't.
 */
class RolePreviewTest extends TestCase
{
    use CreatesAuthTables;

    /** A request from an in-memory user (no DB), optionally carrying the header. */
    private function requestAs(?string $role, ?string $header = null): Request
    {
        $request = Request::create('/api/user/dashboard', 'GET');

        if ($header !== null) {
            $request->headers->set(RolePreview::HEADER, $header);
        }

        if ($role !== null) {
            $user = new User();
            $user->setRelation('role', (new Role())->forceFill(['name' => $role]));
            $user->setRelation('agent', null);
            $request->setUserResolver(fn () => $user);
        }

        return $request;
    }

    // ── The helper ─────────────────────────────────────────────────────────

    public static function previewableRoles(): array
    {
        return array_map(fn ($r) => [$r], RolePreview::PREVIEWABLE);
    }

    #[DataProvider('previewableRoles')]
    public function test_an_admin_is_scoped_as_the_role_they_preview(string $role): void
    {
        $request = $this->requestAs('admin', $role);

        $this->assertSame($role, RolePreview::scopeRole($request));
        $this->assertTrue(RolePreview::isPreviewing($request));
    }

    /** [real role, header it sends] */
    public static function nonAdminAttempts(): array
    {
        return [
            'agent asks for admin'      => ['agent', 'admin'],
            'agent asks for secretary'  => ['agent', 'secretary'],
            'client asks for agent'     => ['client', 'agent'],
            'secretary asks for admin'  => ['secretary', 'admin'],
            'editor asks for client'    => ['editor', 'client'],
        ];
    }

    #[DataProvider('nonAdminAttempts')]
    public function test_a_non_admin_header_is_ignored_entirely(string $real, string $header): void
    {
        $request = $this->requestAs($real, $header);

        $this->assertSame($real, RolePreview::scopeRole($request));
        $this->assertFalse(RolePreview::isPreviewing($request));
    }

    public function test_an_admin_asking_for_admin_or_an_unknown_role_stays_admin(): void
    {
        foreach (['admin', 'superuser', '', 'agent; drop table'] as $header) {
            $this->assertSame('admin', RolePreview::scopeRole($this->requestAs('admin', $header)), "header: '{$header}'");
        }

        $this->assertSame('admin', RolePreview::scopeRole($this->requestAs('admin')));
    }

    public function test_the_header_tolerates_case_and_whitespace(): void
    {
        $this->assertSame('agent', RolePreview::scopeRole($this->requestAs('admin', '  Agent ')));
    }

    public function test_a_guest_has_no_scope_role(): void
    {
        $this->assertNull(RolePreview::scopeRole($this->requestAs(null, 'agent')));
    }

    // ── /user/dashboard — the reason this exists ───────────────────────────

    public function test_an_admin_previewing_the_agent_dashboard_gets_the_agent_payload(): void
    {
        // An admin with no agents row: before RolePreview this request got
        // the admin payload, which has no `category`, and the agent
        // dashboard's tiles rendered blank. It must now get the agent shape,
        // zeroed — and not a 500 for the missing agents row.
        $response = app(ListingController::class)->dashboard($this->requestAs('admin', 'agent'));
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $data['total']);
        $this->assertSame(0, $data['agent']);
        $this->assertSame(['For Sale' => 0, 'For Rent' => 0, 'Foreclosure' => 0], $data['category']);
        $this->assertArrayNotHasKey('properties', $data, 'Got the admin payload — the preview was ignored.');
    }

    // ── /listing-inquiries — narrowing on real rows ────────────────────────

    private function createInquiryTables(): void
    {
        $this->createAuthTables();
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'admin'],
            ['id' => 2, 'name' => 'agent'],
            ['id' => 3, 'name' => 'client'],
        ]);

        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('featured_photo')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('listing_inquiries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('listing_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->timestamps();
        });
        Schema::create('listing_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inquiry_id');
            $table->timestamps();
        });
    }

    private function dbUser(string $role): User
    {
        return User::forceCreate([
            'name'     => ucfirst($role),
            'email'    => uniqid($role, true) . '@example.com',
            'password' => 'secret',
            'role_id'  => DB::table('roles')->where('name', $role)->value('id'),
        ]);
    }

    private function inquiryIdsFor(User $user, ?string $header = null): array
    {
        $request = Request::create('/api/listing-inquiries', 'GET');
        if ($header !== null) {
            $request->headers->set(RolePreview::HEADER, $header);
        }
        $request->setUserResolver(fn () => $user->fresh());

        $data = (new ListingInquiryController())->index($request)->getData(true);

        return collect($data['data'])->pluck('id')->sort()->values()->all();
    }

    public function test_an_admin_previewing_the_client_inbox_sees_only_their_own_inquiries(): void
    {
        $this->createInquiryTables();
        $admin = $this->dbUser('admin');
        $client = $this->dbUser('client');

        $listingId = DB::table('listings')->insertGetId(['agent_id' => 99, 'name' => 'Lot']);
        $mine = DB::table('listing_inquiries')->insertGetId(['listing_id' => $listingId, 'client_id' => $admin->id]);
        $theirs = DB::table('listing_inquiries')->insertGetId(['listing_id' => $listingId, 'client_id' => $client->id]);

        $this->assertSame([$mine], $this->inquiryIdsFor($admin, 'client'));

        // Control: without the preview the admin sees everything, so the
        // narrowing above is the header's doing.
        $this->assertSame([$mine, $theirs], $this->inquiryIdsFor($admin));
    }

    public function test_an_admin_previewing_the_agent_inbox_without_an_agents_row_gets_nothing_not_a_500(): void
    {
        $this->createInquiryTables();
        $admin = $this->dbUser('admin');

        $listingId = DB::table('listings')->insertGetId(['agent_id' => 99, 'name' => 'Lot']);
        DB::table('listing_inquiries')->insert(['listing_id' => $listingId, 'client_id' => $admin->id, 'agent_id' => 99]);

        $this->assertSame([], $this->inquiryIdsFor($admin, 'agent'));
    }
}
