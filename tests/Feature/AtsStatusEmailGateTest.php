<?php

namespace Tests\Feature;

use App\Http\Controllers\FullListingController;
use App\Mail\AtsStatusUpdatedMailer;
use App\Models\Listing;
use App\Services\Listing\ListingService;
use Illuminate\Support\Facades\Mail;
use Mockery;
use ReflectionMethod;
use Tests\Concerns\CreatesAuthTables;
use Tests\Concerns\CreatesListingTables;
use Tests\TestCase;

/**
 * FullListingController::notifyAtsStatusChanged — the "your ATS status was
 * updated" email an auditor's ATS decision triggers. Same rule as the audit
 * and reminder emails: not sent to an agent who is switched off, still sent to
 * an admin whatever their agent row says.
 *
 * It's a private method reached through the whole listing-update wizard, so it
 * is called directly. Mail is faked; in-memory sqlite only.
 */
class AtsStatusEmailGateTest extends TestCase
{
    use CreatesAuthTables, CreatesListingTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        $this->createListingTables();
        Mail::fake();
    }

    private function notify(int $listingId, string $rawStatus = 'approve'): void
    {
        $controller = new FullListingController(Mockery::mock(ListingService::class));
        $method = new ReflectionMethod($controller, 'notifyAtsStatusChanged');

        $method->invoke($controller, Listing::findOrFail($listingId), $rawStatus);
    }

    public function test_an_active_agent_is_told(): void
    {
        $owned = $this->seedOwnedListing(1);

        $this->notify($owned['listing']);

        Mail::assertSent(AtsStatusUpdatedMailer::class, fn ($m) => $m->hasTo($owned['email']));
    }

    public function test_a_blocked_agent_is_not_told(): void
    {
        $n = 0;
        foreach (['inactive', 'resigned', 'deactivated', 'terminated'] as $status) {
            $owned = $this->seedOwnedListing(++$n, $status);

            $this->notify($owned['listing']);
        }

        Mail::assertNothingSent();
    }

    public function test_an_admin_is_told_whatever_their_agent_row_says(): void
    {
        $owned = $this->seedOwnedListing(1, 'terminated', 'admin');

        $this->notify($owned['listing']);

        Mail::assertSent(AtsStatusUpdatedMailer::class, fn ($m) => $m->hasTo($owned['email']));
    }
}
