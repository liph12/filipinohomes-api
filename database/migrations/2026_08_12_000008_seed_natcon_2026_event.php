<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const YEAR = 2026;
    private const SLUG = 'natcon-2026';

    /**
     * Seeds the first convention (NATCON 2026) and its starting form questions.
     *
     * Production does not run seeders, so reference data goes in via an
     * idempotent migration upsert — same pattern as
     * 2026_07_01_000002_insert_secretary_role. Safe to re-run.
     *
     * This migration exists ONLY to bootstrap year one. NATCON 2027 is created
     * from the admin (POST /admin/natcon/events), which clones the previous
     * year's questions — do not add a second seed migration per year.
     */
    public function up(): void
    {
        $now = Carbon::now();

        // The business deadline is "the 24th" in Manila. app.timezone is UTC, so
        // convert explicitly rather than letting Carbon guess — the two differ by
        // 8 hours, which on a hard deadline is a full working day.
        $deadlineUtc = Carbon::parse('2026-08-24 23:59:59', 'Asia/Manila')->utc();

        DB::table('natcon_events')->upsert([[
            'slug'               => self::SLUG,
            'year'               => self::YEAR,
            'name'               => 'National Real Estate Convention 2026',
            'short_name'         => 'NATCON 2026',
            'starts_on'          => '2026-10-18',
            'ends_on'            => '2026-10-19',
            'venue'              => 'JPark Island Resort & Waterpark Mactan, Cebu',
            'hashtag'            => '#LRNATCON2026',
            'photo_deadline_at'  => $deadlineUtc,
            'timezone'           => 'Asia/Manila',
            'update_profile_url' => 'https://filipinohomes.com/natcon/update-profile',
            // Web banner: served by the Next.js app from public/, responsive.
            'banner_base'        => '/images/natcon-2026/natcon2026',
            // Email banner: S3, NOT the web app. Deliberate — email art must not
            // depend on a frontend deploy, and Gmail permanently caches whatever
            // it fetches the first time, so a banner that 404s on send day stays
            // broken for those recipients forever. (It did 404 once, before this.)
            'email_banner_url'   => 'https://filipinohomes123.s3.ap-southeast-1.amazonaws.com/filipinohomes-new/natcon-2026/email/banner-1200.jpg',
            'thank_you_message'  => 'Thank you very much for your cooperation, see you at NATCON 2026',
            // Days before the deadline on which reminders fire.
            // [4,3,2] against Aug 24 == Aug 20 / 21 / 22, as specified.
            // Editable from the admin: shifting the deadline shifts these with it,
            // and changing the cadence is a data edit, not a deploy.
            'reminder_offsets'   => json_encode([4, 3, 2]),
            'is_active'          => true,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]], ['slug'], [
            'year', 'name', 'short_name', 'starts_on', 'ends_on', 'venue', 'hashtag',
            'photo_deadline_at', 'timezone', 'update_profile_url', 'banner_base',
            'email_banner_url', 'thank_you_message', 'reminder_offsets', 'updated_at',
        ]);

        $eventId = DB::table('natcon_events')->where('slug', self::SLUG)->value('id');
        if (! $eventId) {
            return;
        }

        // Starting questions. The admin builder edits these rows and clones them
        // into future years, so this is a starting point rather than a fixture.
        $poloChoices = [
            ['value' => 'xs',  'label' => 'XS',  'help_text' => 'Chest 36in', 'image_url' => null, 'is_active' => true],
            ['value' => 's',   'label' => 'S',   'help_text' => 'Chest 38in', 'image_url' => null, 'is_active' => true],
            ['value' => 'm',   'label' => 'M',   'help_text' => 'Chest 40in', 'image_url' => null, 'is_active' => true],
            ['value' => 'l',   'label' => 'L',   'help_text' => 'Chest 42in', 'image_url' => null, 'is_active' => true],
            ['value' => 'xl',  'label' => 'XL',  'help_text' => 'Chest 44in', 'image_url' => null, 'is_active' => true],
            ['value' => '2xl', 'label' => '2XL', 'help_text' => 'Chest 46in', 'image_url' => null, 'is_active' => true],
            ['value' => '3xl', 'label' => '3XL', 'help_text' => 'Chest 48in', 'image_url' => null, 'is_active' => true],
        ];

        $fields = [
            [
                'key'         => 'polo_shirt_size',
                'label'       => 'Polo Shirt Size',
                'help_text'   => 'Unisex fit. Refer to the chest measurement under each size.',
                'type'        => 'radio',
                'is_required' => true,
                'sort_order'  => 10,
                'section'     => 'merch',
                'choices'     => json_encode($poloChoices),
                'config'      => json_encode(['layout' => 'grid']),
            ],
            [
                'key'         => 'dietary_restrictions',
                'label'       => 'Dietary restrictions',
                'help_text'   => 'Leave blank if none.',
                'type'        => 'short_text',
                'is_required' => false,
                'sort_order'  => 20,
                'section'     => 'general',
                'choices'     => null,
                'config'      => json_encode([
                    'placeholder' => 'e.g. no pork, vegetarian',
                    'max_length'  => 255,
                ]),
            ],
        ];

        foreach ($fields as $field) {
            DB::table('natcon_form_fields')->upsert([array_merge($field, [
                'natcon_event_id'  => $eventId,
                'illustration_url' => null,
                'is_active'        => true,
                'created_at'       => $now,
                'updated_at'       => $now,
            ])], ['natcon_event_id', 'key'], [
                'label', 'help_text', 'type', 'is_required', 'sort_order',
                'section', 'choices', 'config', 'updated_at',
            ]);
        }
    }

    public function down(): void
    {
        // Cascades take out fields, recipients and submissions.
        DB::table('natcon_events')->where('slug', self::SLUG)->delete();
    }
};
