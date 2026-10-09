<?php

namespace Tests\Feature;

use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\Recipient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GET /api/service/natcon/awardees — the roster natcon-api-v2 syncs from.
 *
 * The case this file exists for: the payload must carry OUR recipient id.
 * The printed NATCON tickets encode it (the photobooth stub's QR is
 * `pb-{id}`), so the venue scanner resolves a stub offline against a roster
 * v2 synced from here. Drop the field and the booth stops recognising 336
 * printed tickets — with nothing failing anywhere a test would notice.
 *
 * ⚠️ `fh_recipient_id` is NOT `lr_awardee_id`. The second is LR's agent id
 *    from the qualifier feed; the two have been confused before, which is
 *    why they are asserted together with different values here.
 *
 * Builds its own minimal tables — the full migration suite is MySQL-only,
 * same convention as NatconRaffleTest.
 */
class NatconServiceRosterTest extends TestCase
{
    private const TOKEN = 'service-token-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.fh_service_token' => self::TOKEN]);

        Schema::create('natcon_events', function (Blueprint $table) {
            $table->id();
            $table->integer('year');
            $table->string('name')->nullable();
            $table->string('short_name')->nullable();
            $table->string('slug')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        Schema::create('natcon_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id');
            $table->string('email')->nullable();
            $table->unsignedBigInteger('lr_awardee_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('team')->nullable();
            $table->string('state')->nullable();
            $table->string('award_segment')->nullable();
            $table->json('award_segments')->nullable();
            $table->json('qualifier_payload')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function roster(int $year, ?string $token = self::TOKEN)
    {
        return $this->getJson(
            "/api/service/natcon/awardees?year={$year}",
            $token === null ? [] : ['X-FH-Service-Token' => $token],
        );
    }

    public function test_it_publishes_our_recipient_id_beside_lrs_agent_id(): void
    {
        $event = NatconEvent::create(['year' => 2026, 'name' => 'NATCON 2026', 'slug' => 'natcon-2026']);

        $r = Recipient::create([
            'natcon_event_id' => $event->id,
            'email' => 'aaron@example.com',
            'lr_awardee_id' => 48213,
            'display_name' => 'Aaron Joseph Duremdes',
            'team' => 'BEX Team',
            'state' => 'South Cotabato',
            'award_segment' => 'top_agent',
        ]);

        $row = $this->roster(2026)->assertOk()->json('data.0');

        $this->assertSame($r->id, $row['fh_recipient_id']);
        // The two ids are different namespaces and must not be collapsed.
        $this->assertSame(48213, $row['lr_awardee_id']);
        $this->assertNotSame($row['fh_recipient_id'], $row['lr_awardee_id']);
    }

    public function test_the_id_is_what_the_printed_photobooth_stub_encodes(): void
    {
        $event = NatconEvent::create(['year' => 2026, 'name' => 'NATCON 2026']);

        Recipient::create([
            'natcon_event_id' => $event->id,
            'email' => 'ada@example.com',
            'display_name' => 'Ada Mae And Jay Roiles',
        ]);

        $row = $this->roster(2026)->assertOk()->json('data.0');

        // One stub per PARTY: the couple is pre-split, and both names ride on
        // the single id the stub carries.
        $this->assertSame('pb-'.$row['fh_recipient_id'], 'pb-'.Recipient::first()->id);
        $this->assertSame(['Ada Mae', 'Jay Roiles'], $row['person_names']);
    }

    public function test_an_excluded_recipient_still_never_travels(): void
    {
        $event = NatconEvent::create(['year' => 2026, 'name' => 'NATCON 2026']);

        Recipient::create([
            'natcon_event_id' => $event->id,
            'email' => 'in@example.com',
            'display_name' => 'Included Person',
        ]);
        Recipient::create([
            'natcon_event_id' => $event->id,
            'email' => 'out@example.com',
            'display_name' => 'Excluded Person',
            'status' => Recipient::STATUS_EXCLUDED,
        ]);

        $rows = $this->roster(2026)->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Included Person', $rows[0]['display_name']);
    }

    public function test_it_is_refused_without_the_service_token(): void
    {
        NatconEvent::create(['year' => 2026, 'name' => 'NATCON 2026']);

        $this->roster(2026, null)->assertUnauthorized();
        $this->roster(2026, 'not-the-token')->assertUnauthorized();
    }

    public function test_a_year_with_no_event_is_a_404(): void
    {
        $this->roster(2031)->assertNotFound();
    }
}
