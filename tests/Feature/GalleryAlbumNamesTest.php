<?php

namespace Tests\Feature;

use App\Models\GalleryAlbum;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * /api/admin/albums — album names are unique among SIBLINGS, not across the
 * whole gallery, matching the (event, parent_id, name) unique index.
 *
 * The admin create/rename check was left gallery-wide when the index was
 * narrowed, so "2026" under Thailand › Staff was refused because Vietnam
 * already held a "2026".
 *
 * Builds its own minimal tables; the full migration suite is MySQL-only.
 */
class GalleryAlbumNamesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('role_id')->nullable();
            $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('natcon_events', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->nullable();
            $table->integer('year');
            $table->timestamps();
        });
        Schema::create('gallery_albums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->nullable();
            $table->foreignId('parent_id')->nullable();
            $table->string('slug');
            $table->string('name');
            $table->string('section')->default('event');
            $table->date('album_date')->nullable();
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable();
            $table->foreignId('upload_invite_id')->nullable();
            $table->timestamps();
        });
        Schema::create('gallery_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->nullable();
            $table->foreignId('album_id')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        $roleId = Role::forceCreate(['name' => 'admin'])->id;
        Sanctum::actingAs(User::forceCreate([
            'name' => 'Admin Ann',
            'email' => 'admin@example.com',
            'password' => 'secret',
            'role_id' => $roleId,
        ]));
    }

    private function album(string $name, ?GalleryAlbum $parent = null): GalleryAlbum
    {
        return GalleryAlbum::create([
            'natcon_event_id' => null,
            'parent_id' => $parent?->id,
            'slug' => GalleryAlbum::uniqueSlug($name),
            'name' => $name,
        ]);
    }

    public function test_a_name_used_under_another_parent_is_accepted(): void
    {
        $vietnam = $this->album('Vietnam');
        $this->album('2026', $vietnam);
        $staff = $this->album('Staff', $this->album('Thailand'));

        $this->postJson('/api/admin/albums', ['name' => '2026', 'parent_id' => $staff->id])
            ->assertCreated()
            ->assertJsonPath('data.name', '2026');

        $this->assertSame(2, GalleryAlbum::where('name', '2026')->count());
    }

    public function test_a_name_used_by_a_sibling_is_refused(): void
    {
        $staff = $this->album('Staff', $this->album('Thailand'));
        $this->album('2026', $staff);

        $this->postJson('/api/admin/albums', ['name' => ' 2026 ', 'parent_id' => $staff->id])
            ->assertStatus(422)
            ->assertJsonPath('message', '"Staff" already has an album called "2026".');
    }

    public function test_top_level_albums_are_siblings_of_each_other(): void
    {
        $this->album('Vietnam');
        $this->album('Vietnam', $this->album('Archive'));

        $this->postJson('/api/admin/albums', ['name' => 'Vietnam'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'There is already a top-level album called "Vietnam".');
    }

    public function test_rename_checks_siblings_only(): void
    {
        $vietnam = $this->album('Vietnam');
        $this->album('2026', $vietnam);
        $staff = $this->album('Staff', $this->album('Thailand'));
        $draft = $this->album('Draft', $staff);
        $this->album('2025', $staff);

        $this->patchJson("/api/admin/albums/{$draft->id}", ['name' => '2026'])
            ->assertOk()
            ->assertJsonPath('data.name', '2026');

        $this->patchJson("/api/admin/albums/{$draft->id}", ['name' => '2025'])
            ->assertStatus(422)
            ->assertJsonPath('message', '"Staff" already has an album called "2025".');

        // Renaming to its own current name is not a clash with itself.
        $this->patchJson("/api/admin/albums/{$draft->id}", ['name' => '2026'])
            ->assertOk();
    }
}
