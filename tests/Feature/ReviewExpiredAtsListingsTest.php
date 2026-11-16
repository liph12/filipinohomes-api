<?php

namespace Tests\Feature;

use App\Services\Agent\AgentCachePurger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use OwenIt\Auditing\Events\AuditCustom;
use Tests\TestCase;

/**
 * listings:review-expired-ats — a Verified / Fully Verified listing whose ATS
 * has expired goes back to pending review; nothing else is touched.
 */
class ReviewExpiredAtsListingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('ats_status')->nullable();
            $table->date('ats_expiration_date')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id');
            $table->string('verification_status')->nullable();
            $table->timestamp('re_submitted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Event::fake([AuditCustom::class]);
        $this->mock(AgentCachePurger::class)->shouldReceive('purgeAgent')->andReturnNull();
    }

    private function listing(int $id, ?string $verification, ?string $atsStatus, ?string $expires): void
    {
        DB::table('properties')->insert(['id' => $id, 'ats_status' => $atsStatus, 'ats_expiration_date' => $expires]);
        DB::table('listings')->insert(['id' => $id, 'property_id' => $id, 'agent_id' => 7, 'verification_status' => $verification]);
    }

    private function verification(int $id): ?string
    {
        return DB::table('listings')->where('id', $id)->value('verification_status');
    }

    public function test_verified_listings_with_an_expired_ats_go_back_to_pending_review(): void
    {
        $past = now()->subDays(3)->toDateString();
        $future = now()->addMonth()->toDateString();

        $this->listing(1, 'verified', 'approve', $past);            // date passed (status not flipped yet)
        $this->listing(2, 'fully_verified', 'expired', $past);      // already marked expired
        $this->listing(3, 'fully_verified', 'approve', now()->toDateString()); // expires today
        $this->listing(4, 'verified', 'approve', $future);          // still valid
        $this->listing(5, 'flagged', 'expired', $past);             // not verified → untouched
        $this->listing(6, null, 'expired', $past);                  // never audited → untouched
        $this->listing(7, 'fully_verified', 'approve', null);       // no expiry date

        $this->artisan('listings:review-expired-ats')->assertSuccessful();

        $this->assertSame('pending_review', $this->verification(1));
        $this->assertSame('pending_review', $this->verification(2));
        $this->assertSame('pending_review', $this->verification(3));
        $this->assertSame('verified', $this->verification(4));
        $this->assertSame('flagged', $this->verification(5));
        $this->assertNull($this->verification(6));
        $this->assertSame('fully_verified', $this->verification(7));

        // Logged in the listings audit trail; not marked as an agent resubmission.
        Event::assertDispatchedTimes(AuditCustom::class, 3);
        $this->assertNull(DB::table('listings')->where('id', 1)->value('re_submitted_at'));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->listing(1, 'verified', 'expired', now()->subDay()->toDateString());

        $this->artisan('listings:review-expired-ats', ['--dry-run' => true])
            ->expectsOutputToContain('1 listing(s) would go to pending review')
            ->assertSuccessful();

        $this->assertSame('verified', $this->verification(1));
        Event::assertNotDispatched(AuditCustom::class);
    }
}
