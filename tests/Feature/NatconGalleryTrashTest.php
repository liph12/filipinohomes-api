<?php

namespace Tests\Feature;

use App\Models\GalleryPhoto;
use App\Models\Role;
use App\Models\User;
use App\Natcon\Models\NatconEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * /api/admin/natcon/gallery/trash/* — the deleted-photo drawer.
 *
 * The rules worth a test are the ones that protect files: a restore returns a
 * photo to the status it actually held (not to "published"), a permanent
 * delete removes the bucket objects this gallery owns and NOTHING ELSE, and a
 * row whose file belongs to another folder — or is still referenced by a
 * second row — loses the row only.
 *
 * Builds its own minimal tables; the full migration suite is MySQL-only.
 */
class NatconGalleryTrashTest extends TestCase
{
    private NatconEvent $event;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            // Audits are the record of WHO deleted a photo and what status it
            // held — and they are off in console by default, which is where
            // PHPUnit runs.
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
            $table->date('starts_on')->nullable();
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
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('byte_size')->nullable();
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

        // ⚠️ FaceRecognitionService is final, so it cannot be mocked. It does
        //    not need to be: its constructor only builds a client object, and
        //    forgetPhoto() returns before any API call when face_ids is empty.
        //    Fixtures therefore never carry face_ids at the moment they are
        //    deleted — see the restore test, which sets them afterwards.

        $this->event = NatconEvent::create([
            'slug' => 'natcon-2026',
            'year' => 2026,
            'name' => 'National Real Estate Convention 2026',
            'short_name' => 'NATCON 2026',
            'starts_on' => '2026-10-18',
            'is_active' => true,
        ]);

        $roleId = Role::forceCreate(['name' => 'admin'])->id;
        Sanctum::actingAs(User::forceCreate([
            'name' => 'Admin Ann',
            'email' => 'admin@example.com',
            'password' => 'secret',
            'role_id' => $roleId,
        ]));
    }

    /** A photo whose files live under THIS convention's own prefix. */
    private function photo(string $uuid, string $status = GalleryPhoto::STATUS_ACTIVE, ?NatconEvent $event = null): GalleryPhoto
    {
        $event ??= $this->event;
        $prefix = $event->s3Prefix('gallery');

        Storage::disk('s3')->put("{$prefix}/{$uuid}.jpg", 'main');
        Storage::disk('s3')->put("{$prefix}/{$uuid}-640.jpg", 'thumb');

        return GalleryPhoto::create([
            'natcon_event_id' => $event->id,
            'image_url' => "https://s3.test/{$prefix}/{$uuid}.jpg",
            'thumb_url' => "https://s3.test/{$prefix}/{$uuid}-640.jpg",
            's3_key' => "{$prefix}/{$uuid}.jpg",
            'caption' => 'Opening number',
            'status' => $status,
        ]);
    }

    /** Delete through the API so the audit trail is the real one. */
    private function softDelete(GalleryPhoto $photo): void
    {
        $this->deleteJson("/api/admin/natcon/gallery/{$photo->id}?event_id={$this->event->id}")
            ->assertOk();
    }

    public function test_trash_lists_only_deleted_photos_with_what_they_restore_as(): void
    {
        $live = $this->photo('live');
        $hidden = $this->photo('hidden', GalleryPhoto::STATUS_HIDDEN);
        $this->softDelete($hidden);

        $rows = $this->getJson("/api/admin/natcon/gallery/trash?event_id={$this->event->id}")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($hidden->id, $rows[0]['id']);
        // It was hidden when it went in, so restoring must not publish it.
        $this->assertSame(GalleryPhoto::STATUS_HIDDEN, $rows[0]['restores_as']);
        $this->assertSame('Admin Ann', $rows[0]['deleted_by']);
        $this->assertTrue($rows[0]['s3_owned']);

        $this->assertNotContains($live->id, array_column($rows, 'id'));
    }

    public function test_restore_returns_the_photo_to_its_previous_status_and_queues_a_reindex(): void
    {
        $photo = $this->photo('restore-me');
        $this->softDelete($photo);

        // What forgetFaces() leaves behind: the vectors are evicted from
        // Rekognition but the row still claims to be indexed. Set after the
        // delete so the eviction itself needs no API call.
        $photo->forceFill([
            'face_ids' => ['face-1'],
            'face_count' => 1,
            'faces_indexed_at' => now(),
        ])->saveQuietly();

        $this->postJson("/api/admin/natcon/gallery/trash/restore?event_id={$this->event->id}", [
            'ids' => [$photo->id],
        ])->assertOk()->assertJsonPath('data.restored.0.status', GalleryPhoto::STATUS_ACTIVE);

        $photo->refresh();
        $this->assertSame(GalleryPhoto::STATUS_ACTIVE, $photo->status);
        // The sweep's work-list is "faces_indexed_at IS NULL" — without this
        // the photo would never be findable by face again.
        $this->assertNull($photo->faces_indexed_at);
        $this->assertNull($photo->face_ids);
        // 0, not null: the column is NOT NULL in production.
        $this->assertSame(0, $photo->face_count);
    }

    public function test_a_photo_with_no_delete_audit_restores_hidden(): void
    {
        $photo = $this->photo('orphan');
        // Flipped without an audit — what a row whose audits have aged out of
        // the log looks like.
        $photo->forceFill(['status' => GalleryPhoto::STATUS_DELETED])->saveQuietly();

        $this->postJson("/api/admin/natcon/gallery/trash/restore?event_id={$this->event->id}", [
            'ids' => [$photo->id],
        ])->assertOk();

        $this->assertSame(GalleryPhoto::STATUS_HIDDEN, $photo->refresh()->status);
    }

    public function test_purge_removes_the_row_and_both_objects(): void
    {
        $photo = $this->photo('gone');
        $prefix = $this->event->s3Prefix('gallery');
        $this->softDelete($photo);

        $this->postJson("/api/admin/natcon/gallery/trash/purge?event_id={$this->event->id}", [
            'ids' => [$photo->id],
        ])->assertOk()
            ->assertJsonPath('data.purged.0.id', $photo->id)
            ->assertJsonPath('data.purged.0.s3_removed', true);

        $this->assertNull(GalleryPhoto::find($photo->id));
        Storage::disk('s3')->assertMissing("{$prefix}/gone.jpg");
        Storage::disk('s3')->assertMissing("{$prefix}/gone-640.jpg");
    }

    public function test_purge_of_an_imported_photo_keeps_the_foreign_file(): void
    {
        Storage::disk('s3')->put('FHI_GLOBAL/shared/keep.jpg', 'not ours');

        $photo = GalleryPhoto::create([
            'natcon_event_id' => $this->event->id,
            'image_url' => 'https://s3.test/FHI_GLOBAL/shared/keep.jpg',
            's3_key' => 'FHI_GLOBAL/shared/keep.jpg',
            'status' => GalleryPhoto::STATUS_ACTIVE,
        ]);
        $this->softDelete($photo);

        $this->postJson("/api/admin/natcon/gallery/trash/purge?event_id={$this->event->id}", [
            'ids' => [$photo->id],
        ])->assertOk()->assertJsonPath('data.purged.0.s3_removed', false);

        $this->assertNull(GalleryPhoto::find($photo->id));
        Storage::disk('s3')->assertExists('FHI_GLOBAL/shared/keep.jpg');
    }

    public function test_purge_keeps_a_file_a_second_row_still_points_at(): void
    {
        $photo = $this->photo('shared');
        $prefix = $this->event->s3Prefix('gallery');

        // A duplicate row on the same object — an import run twice.
        GalleryPhoto::create([
            'natcon_event_id' => $this->event->id,
            'image_url' => $photo->image_url,
            'thumb_url' => $photo->thumb_url,
            's3_key' => $photo->s3_key,
            'status' => GalleryPhoto::STATUS_ACTIVE,
        ]);

        $this->softDelete($photo);
        $this->postJson("/api/admin/natcon/gallery/trash/purge?event_id={$this->event->id}", [
            'ids' => [$photo->id],
        ])->assertOk()->assertJsonPath('data.purged.0.s3_removed', false);

        Storage::disk('s3')->assertExists("{$prefix}/shared.jpg");
    }

    public function test_a_live_photo_cannot_be_purged(): void
    {
        $photo = $this->photo('still-live');

        $this->postJson("/api/admin/natcon/gallery/trash/purge?event_id={$this->event->id}", [
            'ids' => [$photo->id],
        ])->assertOk()->assertJsonCount(0, 'data.purged');

        $this->assertNotNull(GalleryPhoto::find($photo->id));
    }

    public function test_another_conventions_photo_is_out_of_scope(): void
    {
        $other = NatconEvent::create([
            'slug' => 'natcon-2025',
            'year' => 2025,
            'short_name' => 'NATCON 2025',
            'is_active' => false,
        ]);
        $photo = $this->photo('elsewhere', GalleryPhoto::STATUS_DELETED, $other);

        $this->postJson("/api/admin/natcon/gallery/trash/purge?event_id={$this->event->id}", [
            'ids' => [$photo->id],
        ])->assertOk()->assertJsonCount(0, 'data.purged');

        $this->assertNotNull(GalleryPhoto::find($photo->id));
    }

    public function test_empty_trash_needs_the_typed_words_and_the_count_the_admin_saw(): void
    {
        $photo = $this->photo('bulk');
        $this->softDelete($photo);

        // Wrong words.
        $this->postJson("/api/admin/natcon/gallery/trash/empty?event_id={$this->event->id}", [
            'confirm' => 'EMPTY',
            'expected_count' => 1,
        ])->assertStatus(422);

        // Right words, stale count — somebody else changed the drawer.
        $this->postJson("/api/admin/natcon/gallery/trash/empty?event_id={$this->event->id}", [
            'confirm' => 'EMPTY TRASH',
            'expected_count' => 7,
        ])->assertStatus(409);

        $this->assertNotNull(GalleryPhoto::find($photo->id));

        $this->postJson("/api/admin/natcon/gallery/trash/empty?event_id={$this->event->id}", [
            'confirm' => 'EMPTY TRASH',
            'expected_count' => 1,
        ])->assertOk()->assertJsonPath('data.remaining', 0);

        $this->assertNull(GalleryPhoto::find($photo->id));
    }

    public function test_an_agent_cannot_reach_the_trash(): void
    {
        $roleId = Role::forceCreate(['name' => 'agent'])->id;
        Sanctum::actingAs(User::forceCreate([
            'name' => 'Agent Andy',
            'email' => 'agent@example.com',
            'password' => 'secret',
            'role_id' => $roleId,
        ]));

        $this->getJson("/api/admin/natcon/gallery/trash?event_id={$this->event->id}")
            ->assertStatus(403);
    }
}
