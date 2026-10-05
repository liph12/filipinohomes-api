<?php

namespace Tests\Feature;

use App\Models\GalleryAlbum;
use App\Models\GalleryPhoto;
use App\Natcon\Models\GalleryUploadInvite;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Services\GalleryInviteService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * POST /api/natcon/upload-invite/photos/bulk-delete — culling a selection.
 *
 * Event day produces hundreds of near-identical frames, and the portal's
 * one-at-a-time delete made clearing them a confirmation per photo.
 *
 * The fences are the single delete's, and they are what this covers: a
 * photographer reaches their OWN photos and nothing else, a foreign id is
 * skipped rather than refused (a wrong id must not confirm the photo exists),
 * and a request that owns nothing at all is a 404 — never a 401, which the
 * frontend reads as a dead session.
 */
class NatconPhotographerBulkDeleteTest extends TestCase
{
    private NatconEvent $event;

    private GalleryUploadInvite $invite;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'audit.console' => true,
            'natcon.link_secret' => 'test-secret',
            'app.guest_api_secret' => 'guest-secret',
        ]);

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
        $this->token = app(GalleryInviteService::class)->mintToken($this->invite);
    }

    /**
     * The portal is public, so its routes sit behind the site-wide guest
     * token (VerifyGuestToken) as well as the invite token in `t`. The
     * frontend mints one for every visitor; here it is signed by hand.
     */
    private function cull(array $body): \Illuminate\Testing\TestResponse
    {
        $payload = json_encode(['exp' => time() + 600]);

        return $this->withHeader(
            'X-Guest-Token',
            base64_encode($payload).'.'.hash_hmac('sha256', $payload, 'guest-secret'),
        )->postJson('/api/natcon/upload-invite/photos/bulk-delete', $body);
    }

    private function album(): GalleryAlbum
    {
        return GalleryAlbum::create([
            'natcon_event_id' => $this->event->id,
            'slug' => 'day-1',
            'name' => 'Day 1',
        ]);
    }

    private function photo(?GalleryUploadInvite $invite, string $status = GalleryPhoto::STATUS_ACTIVE): GalleryPhoto
    {
        return GalleryPhoto::create([
            'natcon_event_id' => $this->event->id,
            'album_id' => $this->album()->id,
            'image_url' => 'https://s3.test/x.jpg',
            's3_key' => 'filipinohomes-new/natcon-2026/gallery/x.jpg',
            'status' => $status,
            'upload_invite_id' => $invite?->id,
        ]);
    }

    public function test_a_photographer_removes_their_own_selection_in_one_request(): void
    {
        $a = $this->photo($this->invite);
        $b = $this->photo($this->invite);

        $this->cull([
            't' => $this->token,
            'ids' => [$a->id, $b->id],
        ])->assertOk()
            ->assertJsonCount(2, 'data.deleted')
            ->assertJsonCount(0, 'data.skipped');

        $this->assertSame(GalleryPhoto::STATUS_DELETED, $a->refresh()->status);
        $this->assertSame(GalleryPhoto::STATUS_DELETED, $b->refresh()->status);
    }

    public function test_somebody_elses_photo_is_skipped_not_refused(): void
    {
        $mine = $this->photo($this->invite);
        $theirs = $this->photo(GalleryUploadInvite::create([
            'natcon_event_id' => $this->event->id,
            'label' => 'Another Shooter',
        ]));
        $adminUpload = $this->photo(null);

        $this->cull([
            't' => $this->token,
            'ids' => [$mine->id, $theirs->id, $adminUpload->id],
        ])->assertOk()
            ->assertJsonPath('data.deleted', [$mine->id])
            ->assertJsonPath('data.skipped', [$theirs->id, $adminUpload->id]);

        $this->assertSame(GalleryPhoto::STATUS_ACTIVE, $theirs->refresh()->status);
        $this->assertSame(GalleryPhoto::STATUS_ACTIVE, $adminUpload->refresh()->status);
    }

    public function test_an_already_deleted_photo_is_skipped(): void
    {
        $gone = $this->photo($this->invite, GalleryPhoto::STATUS_DELETED);
        $live = $this->photo($this->invite);

        $this->cull([
            't' => $this->token,
            'ids' => [$gone->id, $live->id],
        ])->assertOk()
            ->assertJsonPath('data.deleted', [$live->id])
            ->assertJsonPath('data.skipped', [$gone->id]);
    }

    public function test_owning_none_of_the_ids_is_a_404(): void
    {
        $theirs = $this->photo(null);

        $this->cull([
            't' => $this->token,
            'ids' => [$theirs->id],
        ])->assertStatus(404);
    }

    public function test_a_bad_token_is_404_never_401(): void
    {
        $this->cull([
            't' => str_repeat('a', 40).'.nope',
            'ids' => [1],
        ])->assertStatus(404);
    }

    public function test_the_batch_is_capped(): void
    {
        $this->cull([
            't' => $this->token,
            'ids' => range(1, 201),
        ])->assertStatus(422);
    }

    public function test_the_removal_is_tagged_with_the_invite_for_the_history(): void
    {
        $photo = $this->photo($this->invite);

        $this->cull([
            't' => $this->token,
            'ids' => [$photo->id],
        ])->assertOk();

        $this->assertDatabaseHas('audits', [
            'auditable_type' => GalleryPhoto::class,
            'auditable_id' => $photo->id,
            'tags' => 'invite:'.$this->invite->id,
            'source' => 'photographer_invite',
        ]);
    }
}
