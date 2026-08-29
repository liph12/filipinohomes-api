<?php

namespace App\Natcon\Services;

use App\Natcon\Models\PhotoSubmission;
use App\Natcon\Models\Recipient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Stores an awardee's replacement photo.
 *
 * ─── Why this is NOT ImageUploadController::handleS3Upload ───────────────────
 *
 * That method is tuned for listing thumbnails behind auth:sanctum. Reusing it
 * here would be wrong on four counts, each of which matters:
 *
 *   1. It takes the S3 folder straight from the request body. On an endpoint
 *      anonymous awardees can reach, that is attacker-controlled key prefixing.
 *      Here the key is entirely server-side.
 *   2. It targets 50KB WebP. Fifty kilobytes visibly destroys a face, and these
 *      photos go onto printed backdrops and ID cards.
 *   3. WebP is a poor handoff format for an events/print workflow — plenty of
 *      lanyard and badge software still can't open it. We store JPEG.
 *   4. It fans out to ImageVariantService for a responsive srcset nobody will
 *      build from a headshot — five extra S3 writes per upload, on a public
 *      endpoint.
 *
 * It also decodes straight into GD with a 50MB ceiling. A flat-colour
 * 12000x12000 PNG is ~100KB on the wire and decodes to ~576MB, which OOMs a
 * PHP-FPM worker; behind auth that's a nuisance, on a public route it's a way to
 * take api2 down and with it every login OTP. Hence the getimagesize() gate below,
 * which runs BEFORE Intervention touches the file.
 */
final class PhotoService
{
    /** @return array{ok:bool, reason:?string, width:?int, height:?int} */
    public function inspect(UploadedFile $file): array
    {
        $info = @getimagesize($file->getRealPath());

        if ($info === false) {
            return ['ok' => false, 'reason' => 'That file does not look like an image.', 'width' => null, 'height' => null];
        }

        [$width, $height] = $info;

        $maxDim = (int) config('natcon.photo.max_dimension', 8000);
        $maxMp  = (int) config('natcon.photo.max_megapixels', 40);

        if ($width > $maxDim || $height > $maxDim) {
            return [
                'ok'     => false,
                'reason' => "Image is too large ({$width}x{$height}). Please use one under {$maxDim}px on each side.",
                'width'  => $width,
                'height' => $height,
            ];
        }

        // Decompression-bomb gate. Pixel count, not file size — the whole trick is
        // that a bomb is tiny on the wire and enormous in memory.
        if (($width * $height) > ($maxMp * 1_000_000)) {
            return [
                'ok'     => false,
                'reason' => "Image resolution is too high ({$width}x{$height}). Please use one under {$maxMp} megapixels.",
                'width'  => $width,
                'height' => $height,
            ];
        }

        return ['ok' => true, 'reason' => null, 'width' => $width, 'height' => $height];
    }

    /**
     * Encode, upload, and add it to the recipient's set of photos.
     *
     * ─── Why this accumulates instead of replacing ───────────────────────────
     * It used to flip the previous active submission to `superseded`, so an
     * awardee could only ever have one photo on file. The organizers asked for
     * three, so they can choose rather than being handed whatever single file
     * arrives. Submissions therefore stack up to natcon.photo.max_count.
     *
     * `superseded` is still used, just not here — the reset endpoint uses it to
     * retire a photo without orphaning the S3 object.
     */
    public function store(
        Recipient $recipient,
        UploadedFile $file,
        ?string $ip = null,
        ?string $userAgent = null,
    ): PhotoSubmission {
        // Before decoding anything: no point spending a 40-megapixel decode and
        // an S3 round trip on a file that has nowhere to go.
        $existing = $recipient->activePhotos()->count();
        $max      = Recipient::maxPhotoCount();

        if ($existing >= $max) {
            // RuntimeException because PublicController::photo() already maps it
            // to a 422 carrying this message — no new error plumbing needed.
            throw new RuntimeException(
                $max === 1
                    ? 'You already have a photo on file. Please remove it before sending another.'
                    : "You've already sent {$max} photos. Please remove one before sending another."
            );
        }

        $check = $this->inspect($file);
        if (! $check['ok']) {
            throw new RuntimeException($check['reason'] ?? 'Unsupported image.');
        }

        $manager = new ImageManager(new Driver());
        $image   = $manager->read($file->getRealPath());

        // Strips EXIF as a side effect of re-encoding, which also removes GPS
        // coordinates from a phone photo — not the point, but worth having.
        $image = $image->scaleDown(width: (int) config('natcon.photo.max_width', 2000));

        $encoded = $this->encodeUnderTarget(
            fn (int $q) => (string) $image->toJpeg($q),
            (int) config('natcon.photo.target_bytes', 600 * 1024),
            40,
            92,
        );

        // Derived from the event's slug, not a config default. A default would
        // silently drop next year's photos into this year's folder — which is
        // exactly what NATCON_S3_PREFIX did before, undocumented in .env.example.
        $prefix = trim($recipient->event?->s3Prefix() ?: (string) config('natcon.photo.s3_prefix'), '/');
        $key    = $prefix . '/' . Str::uuid() . '.jpg';

        Storage::disk('s3')->put($key, $encoded, 'public');

        $url = rtrim((string) config('filesystems.disks.s3.url'), '/') . '/' . $key;

        return DB::transaction(function () use ($recipient, $key, $url, $encoded, $file, $image, $ip, $userAgent) {
            $submission = PhotoSubmission::create([
                'natcon_recipient_id' => $recipient->id,
                'natcon_event_id'     => $recipient->natcon_event_id,
                'source'              => PhotoSubmission::SOURCE_UPLOADED,
                'photo_url'           => $url,
                's3_key'              => $key,
                'original_filename'   => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                'mime_type'           => 'image/jpeg',
                'byte_size'           => strlen($encoded),
                'width'               => $image->width(),
                'height'              => $image->height(),
                'status'              => PhotoSubmission::STATUS_ACTIVE,
                'review_status'       => PhotoSubmission::REVIEW_PENDING,
                'uploaded_ip'         => $ip,
                'uploaded_user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
            ]);

            $this->syncResponseState($recipient);

            return $submission;
        });
    }

    /**
     * Set exactly which of the awardee's existing LR photos they are keeping.
     *
     * ─── Why keeping writes a row ────────────────────────────────────────────
     * The page asks for a SET of photos, however assembled — two kept plus one
     * new is a valid answer. That is only expressible if a kept photo and an
     * uploaded one are the same kind of thing, so keeping materialises a
     * submission pointing at Leuterio Realty's url. It is not copied into our
     * bucket: that would be a second source of truth for the same image, and the
     * url is already stable and public.
     *
     * Declarative rather than incremental — the caller sends the urls they want
     * kept and this makes reality match. Un-ticking a photo and re-ticking it in
     * the same session therefore ends where it started, with no drift.
     *
     * @param array<int,string> $urls
     */
    public function keepExisting(Recipient $recipient, array $urls): void
    {
        // Only a photo we actually hold for this person may be kept. Without
        // this the field is an open write of an arbitrary url into what the
        // events team will print.
        $allowed = $recipient->displayPhotos();
        $urls    = array_values(array_unique(array_filter(
            $urls,
            fn ($u) => is_string($u) && in_array($u, $allowed, true),
        )));

        DB::transaction(function () use ($recipient, $urls) {
            $existing = PhotoSubmission::where('natcon_recipient_id', $recipient->id)
                ->where('source', PhotoSubmission::SOURCE_LR_RETAINED)
                ->where('status', PhotoSubmission::STATUS_ACTIVE)
                ->get();

            foreach ($existing as $row) {
                if (! in_array($row->photo_url, $urls, true)) {
                    $row->forceFill(['status' => PhotoSubmission::STATUS_DELETED])->save();
                }
            }

            $held = $existing->pluck('photo_url')->all();

            foreach ($urls as $url) {
                if (in_array($url, $held, true)) {
                    continue;
                }

                PhotoSubmission::create([
                    'natcon_recipient_id' => $recipient->id,
                    'natcon_event_id'     => $recipient->natcon_event_id,
                    'source'              => PhotoSubmission::SOURCE_LR_RETAINED,
                    'photo_url'           => $url,
                    // Null: the file is Leuterio Realty's, not ours. This is why
                    // the 000002 migration made s3_key nullable.
                    's3_key'              => null,
                    'status'              => PhotoSubmission::STATUS_ACTIVE,
                    'review_status'       => PhotoSubmission::REVIEW_PENDING,
                ]);
            }

            $this->syncResponseState($recipient);
        });
    }

    /**
     * Retire one photo at the awardee's request.
     *
     * Marked `deleted`, not actually deleted. The S3 object stays — same call as
     * the reset endpoint: removing the row while the file lives on leaves an
     * object in the bucket with nothing pointing at it, so it can never be found
     * again to clean up. It also means "they deleted the good one by mistake" is
     * a recoverable conversation.
     */
    public function remove(Recipient $recipient, PhotoSubmission $submission): void
    {
        DB::transaction(function () use ($recipient, $submission) {
            $submission->forceFill(['status' => PhotoSubmission::STATUS_DELETED])->save();

            $this->syncResponseState($recipient);
        });
    }

    /**
     * ★ The single owner of "has this awardee finished?"
     *
     * ─── Why this exists at all ──────────────────────────────────────────────
     * PublicController::photo() used to set response = change and responded_at
     * itself, right after the upload, under the comment "uploading IS the change
     * response". With one required photo that was true. With three it is a silent
     * data-loss bug: someone who uploads one photo and gives up would be recorded
     * as responded, drop out of the reminder query (DrainOutbox::skipReason →
     * 'already responded'), and show in the admin as confirmed — with one photo
     * instead of three, discovered on deadline day.
     *
     * So completion is DERIVED, in one place, from the only thing that actually
     * evidences it: how many photos are standing.
     *
     *   responded  ⟺  active photo count >= natcon.photo.required_count
     *
     * It is deliberately reversible. Delete a photo and drop below the threshold
     * and responded_at clears, so the reminders resume — the alternative is an
     * awardee who is permanently "done" holding two photos.
     *
     * ⚠️ Do not write response / responded_at / current_photo_url from anywhere
     *    else. Two writers is how the original bug got in.
     */
    public function syncResponseState(Recipient $recipient): void
    {
        // ⚠️ Reload before computing. Eloquent's save() persists only attributes
        //    it considers DIRTY, and dirtiness is judged against whatever the
        //    instance held when it was loaded. Callers hand us models loaded
        //    earlier in the request — reviewPhoto passes $submission->recipient,
        //    remove() passes whatever the controller had — and a stale baseline
        //    makes a needed write look like a no-op and vanish. Caught in testing:
        //    deleting a photo then re-adding one left responded_at NULL with three
        //    photos on file, because the in-memory copy still said "responded".
        $recipient->refresh();

        $photos = $recipient->activePhotos()->get();
        $count  = $photos->count();

        // ─── Complete means BOTH halves of the ask ──────────────────────────
        //
        // It used to mean photos alone, and the two saves on the awardee page
        // are independent by design — saving photos never touched the form. So
        // an awardee could send their pictures, be marked responded, drop out of
        // REMINDABLE, and never be asked again for the polo shirt size the
        // convention kit is built from. The field was flagged required and was
        // enforced on ITS OWN submit, but nothing ever made anyone reach it.
        //
        // Photos remain the visible milestone; they are just no longer the
        // finish line on their own.
        /**
         * A retain that owns no submission row, but still has a photo to print.
         *
         * Two ways to land here: an answer from the old single-decision flow
         * (`PublicController::respond()`, which writes `response` and no row),
         * or a kept set that was later emptied while the response stood.
         *
         * ⚠️ This arm used to live BELOW as its own branch that set `status`
         *    and nothing else — which is what stranded people. It intercepted
         *    before the final arm could clear a stale `response`, so the row
         *    froze at status=responded_retain with responded_at NULL: a green
         *    "Retained" badge in the admin on someone the counts filed under
         *    "Sent, but not submitted", who could never reach Submitted no
         *    matter what else they did.
         *
         * finalPhotoUrl(), not displayPhotos(): it is the same question the
         * events team asks — is there something to print? — and it correctly
         * returns null once a reviewer has ruled the photo on file unusable, so
         * requires_new_photo still reopens them instead of being papered over.
         * With no printable photo this stays false, the final arm clears the
         * stale `response`, and they go back to being chased. That is the
         * honest answer: a retain of nothing is not a settled photo.
         */
        $retainedWithoutRow = $count === 0
            && $recipient->response === Recipient::RESPONSE_RETAIN
            && $recipient->finalPhotoUrl() !== null;

        $photosDone  = $count >= Recipient::requiredPhotoCount() || $retainedWithoutRow;
        $detailsDone = app(FormService::class)->hasRequiredAnswers($recipient);
        $complete    = $photosDone && $detailsDone;

        // A set assembled entirely from photos we already had is a "retain"; the
        // moment one new file is in it, it is a "change". Keeps the existing
        // response vocabulary meaningful now that the two can be mixed.
        $anyUploaded = $photos->contains(
            fn ($p) => $p->source !== PhotoSubmission::SOURCE_LR_RETAINED,
        );
        $retained = $photos->first(
            fn ($p) => $p->source === PhotoSubmission::SOURCE_LR_RETAINED,
        );

        // What the events team will actually print: the photo they picked, else
        // the most recent upload. Keeping this on current_photo_url rather than a
        // new column means finalPhotoUrl(), finalPhotoSource(), the admin
        // resource and the CSV export all keep working untouched.
        $chosen = $photos->firstWhere('review_status', PhotoSubmission::REVIEW_APPROVED)
            ?? $photos->last();

        $fields = [
            'current_photo_url' => $chosen?->photo_url,
            'photo_uploaded_at' => $anyUploaded
                ? ($recipient->photo_uploaded_at ?? Carbon::now())
                : null,
            // Kept for the admin's "Kept" chip and for finalPhotoUrl()'s fallback.
            // First rather than chosen: it answers "did they keep anything?", and
            // which one they print is current_photo_url's job.
            'retained_photo_url' => $retained?->photo_url,
        ];

        if ($complete) {
            $fields['response']     = $anyUploaded
                ? Recipient::RESPONSE_CHANGE
                : Recipient::RESPONSE_RETAIN;
            // Preserved if already set, so a later swap doesn't relabel someone
            // as having responded today when they finished last week.
            $fields['responded_at'] = $recipient->responded_at ?? Carbon::now();
            $fields['status']       = $anyUploaded
                ? Recipient::STATUS_PHOTO_UPLOADED
                : Recipient::STATUS_RESPONDED_RETAIN;
        } elseif ($photosDone) {
            // Pictures are all in; a required question is not. Deliberately keeps
            // responded_at NULL, which is what reminderTargets() filters on, so
            // these people stay in the chase instead of quietly finishing early.
            $fields['response']     = $anyUploaded
                ? Recipient::RESPONSE_CHANGE
                : Recipient::RESPONSE_RETAIN;
            $fields['responded_at'] = null;
            $fields['status']       = Recipient::STATUS_DETAILS_PENDING;
        } else {
            // Incomplete. This is the arm that keeps the reminders coming.
            //
            // `response` follows what is actually in the set — keeping two photos
            // and sending none is a retain in progress, not a change — while
            // `status` says plainly that they are part-way. Both are in
            // REMINDABLE, so neither reading drops them out of the chase.
            $fields['response']     = $count === 0
                ? null
                : ($anyUploaded ? Recipient::RESPONSE_CHANGE : Recipient::RESPONSE_RETAIN);
            $fields['responded_at'] = null;
            // "Reminded" survives. This arm now also runs when someone answers
            // the form before sending a photo, and collapsing reminded -> invited
            // there would tell the events team nobody had been chased yet when
            // they had. Only the outbox promotes a row to REMINDED, so nothing
            // else restores it.
            $fields['status']       = $count > 0
                ? Recipient::STATUS_PHOTOS_PARTIAL
                : ($recipient->status === Recipient::STATUS_REMINDED
                    ? Recipient::STATUS_REMINDED
                    : ($recipient->invited_at ? Recipient::STATUS_INVITED : Recipient::STATUS_PENDING));
        }

        $recipient->forceFill($fields)->save();
    }

    /**
     * Binary-search the JPEG quality that lands just under the byte target.
     * Lifted from ImageUploadController::encodeUnderTarget — same algorithm,
     * different budget, and deliberately copied rather than inherited so a future
     * change to the listing pipeline can't silently reshape awardee photos.
     */
    private function encodeUnderTarget(callable $encode, int $targetBytes, int $minQ, int $maxQ): string
    {
        $best = $encode($maxQ);
        if (strlen($best) <= $targetBytes) {
            return $best;
        }

        $lo   = $minQ;
        $hi   = $maxQ - 1;
        $best = $encode($minQ);

        while ($lo <= $hi) {
            $mid       = intdiv($lo + $hi, 2);
            $candidate = $encode($mid);

            if (strlen($candidate) <= $targetBytes) {
                $best = $candidate;
                $lo   = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $best;
    }
}
