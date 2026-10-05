<?php

namespace Tests\Feature;

use App\Models\GalleryAlbum;
use App\Models\GalleryPhoto;
use App\Models\Role;
use App\Models\User;
use App\Natcon\Models\GalleryUploadInvite;
use App\Natcon\Models\NatconEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/admin/natcon/gallery/invites/{invite}/history — one photographer's
 * trail.
 *
 * The load-bearing claim is that the trail SURVIVES THE PHOTO. A history built
 * by joining audits back to gallery_photos.upload_invite_id would go blank
 * exactly when it is wanted — after an admin empties the trash. So the invite
 * is stamped into audits.tags at write time (GalleryPhoto::generateTags) and
 * read from there; these tests hold that line.
 */
class NatconGalleryInviteHistoryTest extends TestCase
{
    private NatconEvent $event;

    private GalleryUploadInvite $invite;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'audit.console' => true,
            'filesystems.disks.s3.url' => 'https://s3.test',
        ]);

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
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('natcon_events', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->nullable();
            $table->integer('year');
            $table->string('name')->nullable();
            $table->string('short_name')->nullable();
            $table->boolean('is_active')->default(false);
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
            $table->string('image_url');
            $table->string('thumb_url')->nullable();
            $table->string('s3_key')->nullable();
            $table->string('caption')->nullable();
            $table->string('status')->default('active');
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable();
            $table->foreignId('upload_invite_id')->nullable();
            $table->text('face_ids')->nullable();
            $table->unsignedInteger('face_count')->default(0); // NOT NULL in production — mirror it
            $table->timestamp('faces_indexed_at')->nullable();
            $table->text('index_error')->nullable();
            $table->timestamps();
        });
        Schema::create('gallery_upload_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->nullable();
            $table->foreignId('root_album_id')->nullable();
            $table->string('label', 120);
            $table->string('status', 20)->default('active');
            $table->boolean('review_required')->default(false);
            $table->char('invite_token_hash', 64)->nullable();
            $table->char('token_nonce', 32)->nullable();
            $table->timestamp('token_issued_at')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event');
            $table->morphs('auditable');
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->text('url')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->string('tags')->nullable();
            $table->string('category', 64)->nullable();
            $table->string('source', 64)->nullable();
            $table->string('subject_label', 255)->nullable();
            $table->string('user_role', 32)->nullable();
            $table->string('user_name', 191)->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        Storage::fake('s3');

        $this->event = NatconEvent::create([
            'slug' => 'natcon-2026',
            'year' => 2026,
            'short_name' => 'NATCON 2026',
            'is_active' => true,
        ]);

        $this->invite = GalleryUploadInvite::create([
            'natcon_event_id' => $this->event->id,
            'label' => 'Johnry Photography',
        ]);

        $roleId = Role::forceCreate(['name' => 'admin'])->id;
        Sanctum::actingAs(User::forceCreate([
            'name' => 'Admin Ann',
            'email' => 'admin@example.com',
            'password' => 'secret',
            'role_id' => $roleId,
        ]));
    }

    private function photo(string $uuid = 'shot', ?GalleryUploadInvite $invite = null): GalleryPhoto
    {
        $prefix = $this->event->s3Prefix('gallery');
        Storage::disk('s3')->put("{$prefix}/{$uuid}.jpg", 'main');

        $album = GalleryAlbum::firstOrCreate(
            ['natcon_event_id' => $this->event->id, 'slug' => 'day-1'],
            ['name' => 'Day 1'],
        );

        $photo = new GalleryPhoto([
            'natcon_event_id' => $this->event->id,
            'album_id' => $album->id,
            'image_url' => "https://s3.test/{$prefix}/{$uuid}.jpg",
            'thumb_url' => "https://s3.test/{$prefix}/{$uuid}-640.jpg",
            's3_key' => "{$prefix}/{$uuid}.jpg",
            'caption' => 'Opening number',
            'status' => GalleryPhoto::STATUS_ACTIVE,
            'upload_invite_id' => ($invite ?? $this->invite)->id,
        ]);
        $photo->auditSource = 'photographer_invite';
        $photo->save();

        return $photo;
    }

    private function history(): array
    {
        return $this->getJson(
            "/api/admin/natcon/gallery/invites/{$this->invite->id}/history?event_id={$this->event->id}"
        )->assertOk()->json('data');
    }

    public function test_an_upload_appears_with_the_link_as_the_actor(): void
    {
        $photo = $this->photo();

        $rows = $this->history();

        // Newest first, and the link's own creation closes the timeline —
        // "where this photographer's access came from" belongs in it.
        $this->assertSame(['uploaded', 'invite_created'], array_column($rows, 'kind'));
        $this->assertSame('Photographer', $rows[0]['actor']);
        $this->assertSame($photo->id, $rows[0]['photo']['id']);
    }

    public function test_a_caption_edit_and_a_removal_are_told_apart(): void
    {
        $photo = $this->photo();

        $photo->auditSource = 'photographer_invite';
        $photo->fill(['caption' => 'Ribbon cutting'])->save();

        $this->deleteJson("/api/admin/natcon/gallery/{$photo->id}?event_id={$this->event->id}")
            ->assertOk();

        $kinds = array_column($this->history(), 'kind');

        // Newest first, with the link's own creation at the end.
        $this->assertSame(['removed', 'caption', 'uploaded', 'invite_created'], $kinds);

        $caption = $this->history()[1];
        $this->assertSame('Opening number', $caption['changes']['caption']['from']);
        $this->assertSame('Ribbon cutting', $caption['changes']['caption']['to']);
    }

    public function test_a_restore_is_its_own_entry(): void
    {
        $photo = $this->photo();
        $this->deleteJson("/api/admin/natcon/gallery/{$photo->id}?event_id={$this->event->id}")->assertOk();

        $this->postJson("/api/admin/natcon/gallery/trash/restore?event_id={$this->event->id}", [
            'ids' => [$photo->id],
        ])->assertOk();

        $rows = $this->history();
        $this->assertSame('restored', $rows[0]['kind']);
        // An admin did this one, and the trail should say so rather than
        // crediting the photographer's link.
        $this->assertSame('Admin Ann', $rows[0]['actor']);
    }

    public function test_the_trail_outlives_the_photo(): void
    {
        $photo = $this->photo();
        $this->deleteJson("/api/admin/natcon/gallery/{$photo->id}?event_id={$this->event->id}")->assertOk();
        $this->postJson("/api/admin/natcon/gallery/trash/purge?event_id={$this->event->id}", [
            'ids' => [$photo->id],
        ])->assertOk();

        $this->assertNull(GalleryPhoto::find($photo->id));

        $rows = $this->history();

        // The upload is still on record — the whole reason the invite is
        // stamped into the audit row instead of being joined to the photo.
        $this->assertSame('purged', $rows[0]['kind']);
        $this->assertNull($rows[0]['photo']);
        $this->assertContains('uploaded', array_column($rows, 'kind'));
    }

    public function test_machine_bookkeeping_is_not_an_entry(): void
    {
        $photo = $this->photo();

        // What Rekognition writes back after indexing a fresh upload. It
        // audits as an ordinary update and used to read "Edited ·
        // Photographer" — an edit nobody made, between every real entry.
        $photo->auditSource = 'photographer_invite';
        $photo->forceFill([
            'face_ids' => ['f1'],
            'face_count' => 1,
            'faces_indexed_at' => now(),
        ])->save();

        // And what every upload does to the link itself.
        $this->invite->forceFill(['last_used_at' => now()])->save();

        $kinds = array_column($this->history(), 'kind');

        $this->assertSame(['uploaded', 'invite_created'], $kinds);
    }

    public function test_an_empty_diff_is_an_object_not_a_list(): void
    {
        $this->photo();

        // An empty `changes` must serialise as {} — a list where the client
        // was promised a map is the module's oldest JSON trap.
        $raw = $this->getJson(
            "/api/admin/natcon/gallery/invites/{$this->invite->id}/history?event_id={$this->event->id}"
        )->assertOk()->content();

        $this->assertStringContainsString('"changes":{}', $raw);
        $this->assertStringNotContainsString('"changes":[]', $raw);
    }

    public function test_another_photographers_work_stays_out(): void
    {
        $this->photo('mine');
        $other = GalleryUploadInvite::create([
            'natcon_event_id' => $this->event->id,
            'label' => 'Another Shooter',
        ]);
        $theirs = $this->photo('theirs', $other);

        $ids = array_column(array_filter(
            array_column($this->history(), 'photo')
        ), 'id');

        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_an_invite_from_another_convention_is_not_found(): void
    {
        $other = NatconEvent::create([
            'slug' => 'natcon-2025',
            'year' => 2025,
            'short_name' => 'NATCON 2025',
        ]);
        $invite = GalleryUploadInvite::create([
            'natcon_event_id' => $other->id,
            'label' => 'Last Year',
        ]);

        $this->getJson("/api/admin/natcon/gallery/invites/{$invite->id}/history?event_id={$this->event->id}")
            ->assertStatus(404);
    }
}
