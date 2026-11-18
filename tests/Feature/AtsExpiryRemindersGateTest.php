<?php

namespace Tests\Feature;

use App\Mail\AtsExpiryMailer;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesAuthTables;
use Tests\Concerns\CreatesListingTables;
use Tests\TestCase;

/**
 * ats:expiry-reminders — the daily "your ATS is about to lapse / has lapsed"
 * email goes to agents who can still act on it. A resigned, terminated,
 * inactive or deactivated agent can't renew anything (EnsureAgentActive blocks
 * them), so they are skipped and counted; an admin's own listings still get
 * the reminder whatever their agent row says.
 *
 * Mail is faked; in-memory sqlite only.
 */
class AtsExpiryRemindersGateTest extends TestCase
{
    use CreatesAuthTables, CreatesListingTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        $this->createListingTables();
        Mail::fake();
    }

    /** @return array<string, mixed> */
    private function expiresInAWeek(): array
    {
        return ['ats_status' => 'approve', 'ats_expiration_date' => now()->addDays(7)->toDateString()];
    }

    public function test_only_agents_who_can_still_act_are_reminded(): void
    {
        $active = $this->seedOwnedListing(1, 'active', 'agent', false, $this->expiresInAWeek());
        $admin = $this->seedOwnedListing(2, 'resigned', 'admin', false, $this->expiresInAWeek());

        $blocked = [];
        $n = 2;
        foreach (['inactive', 'resigned', 'deactivated', 'terminated'] as $status) {
            $blocked[$status] = $this->seedOwnedListing(++$n, $status, 'agent', false, $this->expiresInAWeek());
        }

        $this->artisan('ats:expiry-reminders')
            ->expectsOutputToContain('skipped (agent not active): 4')
            ->assertSuccessful();

        Mail::assertSent(AtsExpiryMailer::class, 2);
        Mail::assertSent(AtsExpiryMailer::class, fn ($m) => $m->hasTo($active['email']));
        Mail::assertSent(AtsExpiryMailer::class, fn ($m) => $m->hasTo($admin['email']));
        foreach ($blocked as $status => $owned) {
            Mail::assertNotSent(AtsExpiryMailer::class, fn ($m) => $m->hasTo($owned['email']));
        }
    }

    public function test_the_expired_today_notice_is_gated_too(): void
    {
        $property = ['ats_status' => 'expired', 'ats_expiration_date' => now()->toDateString()];
        $active = $this->seedOwnedListing(1, 'active', 'agent', false, $property);
        $this->seedOwnedListing(2, 'terminated', 'agent', false, $property);

        $this->artisan('ats:expiry-reminders')->assertSuccessful();

        Mail::assertSent(AtsExpiryMailer::class, 1);
        Mail::assertSent(AtsExpiryMailer::class, fn ($m) => $m->hasTo($active['email']));
    }
}
