# NATCON — read this before changing anything here

The National Real Estate Convention. **It runs every year**, so nothing about a
particular year may live in code. `natcon_events` is the per-year config; the
module reads it.

Frontend counterpart: `filipinohomes-final/src/components/natcon/` — see its own
`CLAUDE.md`.

---

## 1. Per-year data goes on `natcon_events`, never in config or code

```
slug  year  name  short_name  starts_on  ends_on  venue  hashtag
timezone  photo_deadline_at  update_profile_url  banner_base
email_banner_url  thank_you_message  reminder_offsets
sales_breakpoint  is_active
```

Adding a per-year asset (a logo, a sponsor strip, a different banner) means a
column here plus a field in the admin's Event settings tab — **not** a constant.

`config/natcon.php` holds fallbacks only, and its own comments say why: the
default S3 prefix and email banner are hardcoded to `natcon-2026`, so anything
that relies on the fallback instead of the event row *"would silently drop next
year's photos into this year's folder — which is exactly what NATCON_S3_PREFIX did
before."* `NatconEvent::s3Prefix()` derives the path from `slug` for the same
reason.

---

## 2. Sending is a cron drain. Never send inline.

`/send-invites` writes `natcon_outbox` rows and returns; `natcon:drain-outbox`
does the sending, paced by the scheduler (~40/min).

Sending inline **would 524 behind Cloudflare partway through**, and the admin
would press Send again — so the double-send guarantee is a database constraint,
not application logic:

```
UNIQUE (recipient, kind, send_date)   on natcon_outbox
```

Do not weaken it. It is the only thing standing between a retry and 292 people
getting two emails.

`NATCON_SEND_MODE` (`off` / `whitelist` / `live`) is the last line before real
awardees receive mail. **287+ real awardees are live.**

### `targetQuery` returns early

`recipient_ids` and `statuses` **replace** the default `status = pending` guard
rather than narrowing it. That is deliberate — it is how "send to this one
awardee" works — but it means a caller passing `recipient_ids` bypasses the
pending filter entirely.

---

## 3. Completion means photos AND every required answer

`PhotoService::syncResponseState()`:

```php
$photosDone  = $count >= Recipient::requiredPhotoCount();
$detailsDone = app(FormService::class)->hasRequiredAnswers($recipient);
$complete    = $photosDone && $detailsDone;
```

It used to be photos alone, which let awardees finish and leave the events team a
picture and no shirt size.

Two consequences to respect:

- **`FormService::submit()` must recompute it too.** Answering the form can
  complete someone; without that call they sit at `details_pending` for ever,
  being reminded about something they already did.
- **`refresh()` first.** `save()` only persists attributes it thinks are dirty,
  judged against a stale baseline, and callers hand in models loaded earlier in
  the request. Deleting a photo then re-adding one once left `responded_at` NULL
  with three photos on file.

### Adding a status means deciding if it is remindable

`Recipient::REMINDABLE` drives `InviteService::reminderTargets()`. A new status
that is not in it silently stops being chased — that is how `photos_partial`
submitters went unreminded once.

---

## 4. Timestamps: stored UTC, edited as a wall clock

`config('app.timezone')` is **UTC**. Admin forms send
`<input type="datetime-local">` values, which are wall clocks in the *event's*
timezone.

So always `Carbon::parse($value, $event->timezone)->utc()` on the way in, and
serve a separate `*_local` field for the form to read back:

- `photo_deadline_at` (UTC) + `photo_deadline_local` (`Y-m-d\TH:i`)
- `published_at` (UTC) + `published_local` on announcements

A bare `Carbon::parse()` reads 9am Manila as 9am UTC. This has been the same bug
twice — the photo deadline walked eight hours earlier on every save, and scheduled
announcements went live eight hours late.

⚠️ Use `array_merge`, not `+`, when overriding serialised model keys. The union
operator keeps the **left** operand's keys, so `$e->toArray() + [...]` silently
discards every override.

---

## 5. The form is admin-defined. Never special-case a field key.

Fields live in `natcon_form_fields` and are edited in the admin. `FormService`
validates whatever is there. The moment something does
`if ($key === 'polo_shirt_size')`, next year's form breaks.

- **The schema is cached per EVENT** (`natcon:schema:{id}`). Never put
  per-recipient data in it — that is why `person_names` rides on the recipient
  payload instead. Call `forgetSchema()` after changing fields.
- **`Str::snake` shatters acronyms.** "NATCON Polo Shirt Size" became the key
  `n_a_t_c_o_n_polo_shirt_size`; `uniqueKey()` now lowercases first. The live 2026
  field keeps the mangled key — it is what the stored answers are filed under, and
  renaming it would orphan them.
- **`per_person` fields** store a positional array aligned to
  `Recipient::personNames()`. `normalizePerPerson()` delegates to `normalize()`
  per person rather than reimplementing the type rules.
- **`missingRequiredLabels()` deliberately avoids `isBlank()` for those**: a
  couple with `["large", null]` would pass an `array_filter` check and be marked
  complete with a size missing.

### Couples

`Recipient::personNames()` splits `displayName()` on `and` / `And` / `&` / `+`.
**118 of 292 awardees for 2026 are couples on one login.** Measured over all of
them: exactly one uses `&`, none has three people, and the `\b` word boundaries
are load-bearing — without them the split eats the "and" inside *Alexander* and
*Fernanda*.

---

## 6. Photos

`PhotoSubmission.s3_key` is **nullable**. Rows with `source = lr_retained` point at
a Leuterio Realty URL and are deliberately *not* copied into our bucket — that
would be a second source of truth. Anything reading photo bytes must handle both.

`finalPhotoUrl()` legitimately returns **null** when `requires_new_photo` is set
and no replacement exists. That gap is intentional and visible in the export;
do not paper over it with a guess.

Photos are JPEG, ≤2000px, ≤600KB — deliberately *not* the 50KB WebP listing
pipeline, because these go to a print workflow.

---

## 7. Announcements and sponsors are per-event; recaps are global

`natcon_announcements` and `natcon_sponsors` have an event FK. `natcon_recaps`
does **not** — the recordings run back to 2012 and those years have no event row.
The admin says so out loud, because switching year and seeing the same videos
otherwise reads as a bug.

### Sponsors

Three public tiers on `Sponsor::TIERS` (`major`, `minor`, `star`) and a fourth on
`ALL_TIERS`:

⚠️ **`library` is the admin's upload pool and must never be served publicly.**
`LandingController::sponsors()` filters with `whereIn('tier', Sponsor::TIERS)`
precisely so the pool cannot leak. Any new public read has to do the same.

There is **no `is_published` column** — it was dropped on purpose. Being in a
public tier *is* being published, and the flag duplicated removal while
confusing the admin UI. Do not add it back; `scopeLive()` only orders.

⚠️ **Dropping an index that backs a foreign key fails with MySQL error 1553.**
The sponsors table hit this: the composite index also backed the
`natcon_event_id` FK, so the replacement index must be created in its own
`Schema::table` call BEFORE the old one is dropped. The migration that does it is
worth reading before you touch any index on these tables.

`NatconAnnouncement` keeps its module prefix because `App\Models\Announcement`
already exists and is a completely different thing (a push broadcast). Two
same-named models one namespace apart is how the wrong one gets imported.

Public landing reads (`/natcon/recaps`, `/natcon/{year}/announcements`) sit
**outside** the `verify.guest.token` group, beside `/natcon/event`. SSR and
Googlebot send no token.

---

## 8. Announcement reactions are anonymous, and that shapes everything

`natcon_announcement_reactions`. The landing page has no login, so the actor is
the browser's persistent `visitor_id` (the same `fh_vid` the web already keeps for
acquisition tracking) — **not** a user.

⚠️ **The unique key is `(natcon_announcement_id, visitor_id)` and deliberately
excludes `reaction`.** That one constraint is the whole Facebook behaviour: one
reaction per person per post, so a different emoji REPLACES the previous one
rather than stacking. Widen it to include `reaction` and every visitor can hold
all seven at once.

- **Keys, never emoji.** `AnnouncementReaction::KEYS` is an allowlist and the
  validator uses `in:`. A public endpoint writing arbitrary strings into a label
  column is an unbounded set nobody can total or render.
- **The set is all positive on purpose.** Sad and Angry were declined: the page is
  public, indexed and sells convention seats, so a one-tap way to attach a
  visible negative number to the organisers' own announcements is not a feature.
- **Counts are aggregated on read**, not denormalised into a counter column. The
  frontend proxies the read behind a short cache so the GROUP BY runs about twice
  a minute however busy the page is, and a counter that drifts is worse than an
  aggregate that cannot.
- **Cast the tally to `(object)` on output.** An empty PHP array serialises as
  `[]`, so the client is handed a list where it was promised a map — harmless for
  `counts[key]`, fatal for `Object.keys`. (The submissions payload still has this
  bug; do not copy it.)
- **Not Auditable**, unlike the rest of the module. An audit row per tap would
  bury the sends, photo reviews and slug changes under thousands of clicks.
- `natcon_events.reactions_enabled` is the valve. Off means the tally endpoint
  returns nothing AND the write 404s — hiding the bar without refusing the write
  would be theatre. Existing rows are kept, so switching back on restores them.
- The FK cascade fires on a real DELETE only. Announcements soft-delete, so a
  pulled announcement keeps its reactions and a restore brings them back; only a
  `forceDelete` reaps them. That is the wanted behaviour, not an oversight.

## 9. Running things on api2

**Every artisan command must run as `www-data`:**

```
sudo -u www-data php artisan …
```

Root- or ubuntu-owned files in `storage/` or `bootstrap/cache` make php-fpm unable
to write, and the symptom is a 500 that the browser reports as a CORS error.
`psysh`/`tinker` fails as `www-data` — use `env HOME=/tmp` or a bootstrapped
script.

The scheduler is the `www-data` crontab. Cache is the file driver.
