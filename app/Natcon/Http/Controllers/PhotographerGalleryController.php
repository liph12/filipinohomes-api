<?php

namespace App\Natcon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GalleryAlbum;
use App\Models\GalleryPhoto;
use App\Natcon\Exceptions\ExpiredLinkException;
use App\Natcon\Exceptions\InvalidLinkException;
use App\Natcon\Models\GalleryUploadInvite;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Services\FaceRecognitionService;
use App\Natcon\Services\GalleryInviteService;
use App\Natcon\Services\GalleryService;
use App\Natcon\Services\LandingCachePurger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The photographer upload portal's API: everything /natcon/upload does.
 *
 * Identity is the upload-invite token in `t` (query on GET, body on writes) —
 * no account, no login. The rules are PublicController's, because the same
 * infrastructure sits in front of both:
 *
 * ⚠️ No route here may answer 401. The frontend axios interceptor treats a
 *    message-keyed 401 as a dead session and logs the user out — and admins
 *    testing a photographer link are logged in. Link problems are 404/410,
 *    validation is 422.
 * ⚠️ No GET may mutate. Chat apps and mail scanners prefetch every shared
 *    URL, so state() must stay a pure read; last_used_at moves on writes only.
 *
 * Photographers can CREATE albums anywhere in their scope but only rename
 * albums and delete/re-caption photos that carry THEIR upload_invite_id —
 * ownership misses are 404s (never 403: don't confirm what exists). Album
 * delete stays admin-only: an album may hold another invite's photos.
 */
class PhotographerGalleryController extends Controller
{
    public function __construct(
        private GalleryInviteService $invites,
        private GalleryService $gallery,
        private FaceRecognitionService $faces,
    ) {}

    // ── Read ─────────────────────────────────────────────────────────────────

    /**
     * Everything the portal needs in one round trip: who the invite is, the
     * album tree in scope, and the photos in scope. Other invites' photos
     * appear only when live; the photographer's own include hidden ones
     * (pending review) so their queue never looks like it lost files.
     */
    public function state(Request $request): JsonResponse
    {
        $request->validate(['t' => 'required|string|min:16|max:160']);

        return $this->withInvite($request->input('t'), function (GalleryUploadInvite $invite) {
            $event = $invite->event;
            $albums = $this->scopeAlbums($invite);
            $albumIds = $albums->pluck('id')->all();

            $photoCounts = GalleryPhoto::whereIn('album_id', $albumIds === [] ? [0] : $albumIds)
                ->where('status', '!=', GalleryPhoto::STATUS_DELETED)
                ->selectRaw('album_id, count(*) as n')
                ->groupBy('album_id')
                ->pluck('n', 'album_id');

            $photos = GalleryPhoto::whereIn('album_id', $albumIds === [] ? [0] : $albumIds)
                ->where('status', '!=', GalleryPhoto::STATUS_DELETED)
                ->where(function ($q) use ($invite) {
                    $q->where('status', GalleryPhoto::STATUS_ACTIVE)
                        ->orWhere('upload_invite_id', $invite->id);
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            return response()->json(['data' => [
                'invite' => [
                    'label' => $invite->label,
                    'review_required' => (bool) $invite->review_required,
                    'expires_at' => $invite->token_expires_at?->toIso8601String(),
                    'root_album' => $invite->rootAlbum ? [
                        'id' => $invite->rootAlbum->id,
                        'name' => $invite->rootAlbum->name,
                        'path' => $invite->rootAlbum->path(),
                    ] : null,
                ],
                'event' => $event ? [
                    'year' => $event->year,
                    'name' => $event->name,
                    'short_name' => $event->short_name,
                    'date_label' => $event->dateLabel(),
                    'venue' => $event->venue,
                ] : null,
                'albums' => $albums->map(fn (GalleryAlbum $a) => $this->presentAlbum($a, $invite, (int) ($photoCounts[$a->id] ?? 0)))->values(),
                'photos' => $photos->map(fn (GalleryPhoto $p) => $this->presentPhoto($p, $invite))->values(),
            ]]);
        });
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    public function storeAlbum(Request $request): JsonResponse
    {
        $data = $request->validate([
            't' => 'required|string|min:16|max:160',
            'name' => 'required|string|max:120',
            'parent_id' => 'sometimes|nullable|integer',
        ]);

        return $this->withInvite($data['t'], function (GalleryUploadInvite $invite) use ($data) {
            $parent = $this->resolveScopedAlbum($invite, $data['parent_id'] ?? null);

            // A scoped invite's "top level" IS its root album — a null parent
            // must never plant an album outside the fence.
            if (! $parent && $invite->root_album_id) {
                $parent = $invite->rootAlbum;
                abort_unless($parent, 404, 'Album not found for this convention.');
            }

            // Human-made trees stay navigable. The read paths cap their walks
            // at 20; hitting that here means corrupt data, and the same 422
            // answers both.
            $maxDepth = (int) config('natcon.gallery.max_album_depth', 6);
            if ($parent && $this->albumDepth($parent, $invite) + 1 > $maxDepth) {
                return response()->json(['message' => "Albums can only nest {$maxDepth} levels deep."], 422);
            }

            // Names are unique among SIBLINGS, so "Day 1" inside one
            // photographer's album no longer collides with "Day 1" inside
            // another's. A genuine sibling clash still auto-suffixes rather
            // than erroring — on event day a rejected album is a stalled
            // upload queue. The response carries the actual name used.
            $name = $this->availableName($invite->event, trim($data['name']), $parent?->id);
            if ($name === null) {
                return response()->json(['message' => 'Too many albums share that name — pick a different one.'], 422);
            }

            $album = new GalleryAlbum([
                'natcon_event_id' => $invite->natcon_event_id,
                'parent_id' => $parent?->id,
                // Convention albums carry no slug; a public-scope invite's
                // albums are URL-addressable like any public album.
                'slug' => $invite->natcon_event_id ? null : GalleryAlbum::uniqueSlug($name),
                'name' => $name,
                'created_by' => null,
                'upload_invite_id' => $invite->id,
            ]);
            $album->auditSource = 'photographer_invite';

            try {
                $album->save();
            } catch (\Illuminate\Database\QueryException) {
                // The unique index caught a concurrent twin — one retry with a
                // fresh suffix, then give up honestly.
                $name = $this->availableName($invite->event, trim($data['name']), $parent?->id);
                if ($name === null) {
                    return response()->json(['message' => 'Too many albums share that name — pick a different one.'], 422);
                }
                $album->name = $name;
                $album->save();
            }

            $this->touch($invite);
            $this->purgeDebounced($invite);

            return response()->json(['data' => $this->presentAlbum($album, $invite, 0)], 201);
        });
    }

    public function updateAlbum(Request $request, GalleryAlbum $album): JsonResponse
    {
        $data = $request->validate([
            't' => 'required|string|min:16|max:160',
            'name' => 'required|string|max:120',
        ]);

        return $this->withInvite($data['t'], function (GalleryUploadInvite $invite) use ($album, $data) {
            // TWO questions, not one. "Did you create it" is ownership; "is it
            // still inside your fence" is authorisation, and they come apart
            // the moment an admin narrows an invite's root_album_id — the
            // photographer kept rename rights on albums that had just been
            // moved out of reach.
            abort_unless($album->upload_invite_id === $invite->id, 404, 'Album not found.');
            abort_unless(
                $this->scopeAlbums($invite)->contains(fn (GalleryAlbum $a) => $a->id === $album->id),
                404,
                'Album not found.',
            );

            $name = trim($data['name']);

            /**
             * A rename is deliberate — silently renaming a rename would
             * gaslight the photographer, so collisions get a friendly 422.
             *
             * Among SIBLINGS only. Scope-wide, this refused a perfectly good
             * name because of an album in another photographer's fence, and
             * the message named it — telling someone about the existence and
             * title of something they are fenced out of.
             */
            $duplicate = GalleryAlbum::forEvent($invite->event)
                ->where('parent_id', $album->parent_id)
                ->where('name', $name)
                ->where('id', '!=', $album->id)
                ->exists();
            if ($duplicate) {
                return response()->json(['message' => "An album called \"{$name}\" is already here."], 422);
            }

            $album->auditSource = 'photographer_invite';
            $album->fill(['name' => $name])->save();

            $this->touch($invite);
            $this->purgeDebounced($invite);

            return response()->json(['data' => $this->presentAlbum($album->fresh(), $invite)]);
        });
    }

    public function storePhoto(Request $request): JsonResponse
    {
        $data = $request->validate([
            't' => 'required|string|min:16|max:160',
            'photo' => 'required|file|mimes:jpeg,jpg,png,webp|max:'.(int) config('natcon.gallery.max_upload_kb', 15360),
            'caption' => 'sometimes|nullable|string|max:255',
            'album_id' => 'required|integer',
        ], [
            'photo.mimes' => 'Please upload a JPG, PNG or WEBP image.',
            'photo.max' => 'That image is too large. Please keep it under 15MB.',
            'album_id.required' => 'Choose an album to upload into.',
        ]);

        return $this->withInvite($data['t'], function (GalleryUploadInvite $invite) use ($request, $data) {
            // resolveScopedAlbum aborts on a miss and album_id is required, so
            // there is no null to guard against here. A second abort_unless
            // read as though the fence were optional; it never was.
            $album = $this->resolveScopedAlbum($invite, $data['album_id']);

            $hidden = (bool) $invite->review_required;

            try {
                $row = $this->gallery->store(
                    $invite->event,
                    $request->file('photo'),
                    null,
                    $data['caption'] ?? null,
                    $album,
                    $invite->id,
                    $hidden ? GalleryPhoto::STATUS_HIDDEN : GalleryPhoto::STATUS_ACTIVE,
                    'photographer_invite',
                );
            } catch (RuntimeException $e) {
                // The megapixel / decode gate — user-fixable, so 422.
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $this->touch($invite);

            // A hidden upload changed nothing public — skip even the
            // debounced purge.
            if (! $hidden) {
                $this->purgeDebounced($invite);
            }

            return response()->json(['data' => $this->presentPhoto($row, $invite)], 201);
        });
    }

    public function updatePhoto(Request $request, GalleryPhoto $photo): JsonResponse
    {
        $data = $request->validate([
            't' => 'required|string|min:16|max:160',
            'caption' => 'sometimes|nullable|string|max:255',
        ]);

        return $this->withInvite($data['t'], function (GalleryUploadInvite $invite) use ($photo, $data) {
            $this->guardOwnPhoto($invite, $photo);

            $photo->auditSource = 'photographer_invite';
            $photo->fill(['caption' => $data['caption'] ?? null])->save();

            $this->touch($invite);
            $this->purgeDebounced($invite);

            return response()->json(['data' => $this->presentPhoto($photo->fresh(), $invite)]);
        });
    }

    public function destroyPhoto(Request $request, GalleryPhoto $photo): JsonResponse
    {
        $data = $request->validate(['t' => 'required|string|min:16|max:160']);

        return $this->withInvite($data['t'], function (GalleryUploadInvite $invite) use ($photo) {
            $this->guardOwnPhoto($invite, $photo);

            // A status flip, never delete() — the row is the only pointer to
            // the S3 object (same rule as the admin path).
            $photo->auditSource = 'photographer_invite';
            $photo->forceFill(['status' => GalleryPhoto::STATUS_DELETED])->save();

            $this->forgetFaces($photo);
            $this->touch($invite);
            $this->purgeDebounced($invite);

            return response()->json(['message' => 'Photo removed.']);
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Resolve-or-refuse, mapped to the awardee links' status contract:
     * 404 link_invalid / 410 link_expired, NEVER 401.
     */
    private function withInvite(string $token, callable $callback)
    {
        try {
            $invite = $this->invites->resolveToken($token);
        } catch (ExpiredLinkException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'link_expired'], 410);
        } catch (InvalidLinkException) {
            return response()->json(['message' => 'Invalid link.', 'code' => 'link_invalid'], 404);
        }

        return $callback($invite);
    }

    /**
     * The albums this invite may see and build under: the whole scope, or the
     * root album's subtree (root included) when the invite is fenced.
     *
     * @return \Illuminate\Support\Collection<int, GalleryAlbum>
     */
    private function scopeAlbums(GalleryUploadInvite $invite): \Illuminate\Support\Collection
    {
        $all = GalleryAlbum::forEvent($invite->event)->orderBy('name')->get();

        if (! $invite->root_album_id) {
            return $all;
        }

        // BFS over the already-loaded scope — trees are tens of rows.
        $byParent = $all->groupBy('parent_id');
        $keep = [];
        $queue = [$invite->root_album_id];
        while ($queue !== [] && count($keep) < 10_000) {
            $id = array_shift($queue);
            $keep[$id] = true;
            foreach ($byParent->get($id, collect()) as $child) {
                $queue[] = $child->id;
            }
        }

        return $all->filter(fn (GalleryAlbum $a) => isset($keep[$a->id]))->values();
    }

    /** Null stays null; an id must be an album inside the invite's scope. */
    private function resolveScopedAlbum(GalleryUploadInvite $invite, mixed $albumId): ?GalleryAlbum
    {
        if ($albumId === null || $albumId === '') {
            return null;
        }

        $album = $this->scopeAlbums($invite)->firstWhere('id', (int) $albumId);

        abort_unless($album, 404, 'Album not found for this convention.');

        return $album;
    }

    /**
     * How deep an album sits, counted from the photographer's own top level.
     *
     * For a fenced invite that top level is its root album, NOT the gallery's.
     * Measuring absolutely spent the budget on ancestors the photographer
     * cannot see or navigate to: an invite rooted four levels down left them
     * two of the six, refused with a message about a limit that made no sense
     * from where they were standing. Everyone now gets the same allowance
     * wherever their album happens to hang.
     *
     * The 20-step cap treats corrupt self-referencing data as "too deep",
     * which refuses safely. Reaching the fence root ends the walk the same
     * way reaching a null parent does.
     */
    private function albumDepth(GalleryAlbum $album, ?GalleryUploadInvite $invite = null): int
    {
        $rootId = $invite?->root_album_id;

        if ($rootId && $album->id === $rootId) {
            return 1;
        }

        $depth = 1;
        $node = $album;
        for ($i = 0; $i < 20 && $node->parent; $i++) {
            $node = $node->parent;
            $depth++;

            if ($rootId && $node->id === $rootId) {
                break;
            }
        }

        return $depth;
    }

    /**
     * First free name among SIBLINGS: "Name", then "Name (2)" … "(20)".
     *
     * Sibling-scoped to match the unique index, which was widened from
     * (event, name) to (event, parent_id, name). Before that, a fenced
     * photographer's "Day 1" was silently suffixed because of an album in
     * somebody else's fence — invisible to them, unexplainable, and their
     * folder was called "Day 1 (3)" for reasons nobody could reconstruct.
     *
     * Null parent is a real value here, not "any parent": top-level albums
     * are siblings of each other.
     */
    private function availableName(?NatconEvent $event, string $base, ?int $parentId): ?string
    {
        $base = trim($base) !== '' ? trim($base) : 'Album';

        for ($i = 1; $i <= 20; $i++) {
            $candidate = $i === 1 ? $base : "{$base} ({$i})";
            $taken = GalleryAlbum::forEvent($event)
                ->where('parent_id', $parentId)
                ->where('name', $candidate)
                ->exists();
            if (! $taken) {
                return $candidate;
            }
        }

        return null;
    }

    private function guardOwnPhoto(GalleryUploadInvite $invite, GalleryPhoto $photo): void
    {
        // 404, not 403 — a wrong id must not confirm the photo exists. Also
        // covers already-deleted rows.
        abort_unless(
            $photo->upload_invite_id === $invite->id
                && $photo->status !== GalleryPhoto::STATUS_DELETED,
            404,
            'Photo not found.',
        );
    }

    /** Writes only — a prefetched GET must not look like activity. */
    private function touch(GalleryUploadInvite $invite): void
    {
        $invite->forceFill(['last_used_at' => Carbon::now()])->save();
    }

    /**
     * At most one purge per scope per minute: a 400-photo dump must not fire
     * 400 frontend revalidations — the page's 30s ISR window covers the gaps
     * between debounced purges. Cache::add is atomic, so concurrent uploads
     * race safely.
     */
    private function purgeDebounced(GalleryUploadInvite $invite): void
    {
        $key = $invite->event
            ? 'natcon:gallery:invite-purge:'.$invite->event->year
            : 'natcon:gallery:invite-purge:public';

        if (! Cache::add($key, 1, 60)) {
            return;
        }

        $purger = app(LandingCachePurger::class);

        if ($invite->event) {
            $purger->purgeYear($invite->event->year);

            return;
        }

        $purger->purgeAlbums(null);
    }

    /** Best-effort face eviction — copy of GalleryController::forgetFaces. */
    private function forgetFaces(GalleryPhoto $photo): void
    {
        try {
            $this->faces->forgetPhoto($photo);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('gallery face eviction failed', [
                'photo_id' => $photo->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function presentAlbum(GalleryAlbum $a, GalleryUploadInvite $invite, ?int $photoCount = null): array
    {
        return [
            'id' => $a->id,
            'parent_id' => $a->parent_id,
            'name' => $a->name,
            'path' => $a->path(),
            'sort_order' => $a->sort_order,
            'photo_count' => $photoCount ?? (int) ($a->photos_count ?? 0),
            'mine' => $a->upload_invite_id === $invite->id,
        ];
    }

    private function presentPhoto(GalleryPhoto $p, GalleryUploadInvite $invite): array
    {
        return [
            'id' => $p->id,
            'image_url' => $p->image_url,
            'thumb_url' => $p->thumb_url,
            'caption' => $p->caption,
            'width' => $p->width,
            'height' => $p->height,
            'album_id' => $p->album_id,
            'status' => $p->status,
            'mine' => $p->upload_invite_id === $invite->id,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
