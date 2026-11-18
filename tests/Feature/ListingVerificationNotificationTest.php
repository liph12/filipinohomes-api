<?php

namespace Tests\Feature;

use App\Mail\ListingFlaggedMailer;
use App\Mail\ListingVerifiedMailer;
use App\Models\User;
use App\Services\Agent\AgentCachePurger;
use App\Services\ExpoPushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery;
use OwenIt\Auditing\Events\AuditCustom;
use Tests\Concerns\CreatesAuthTables;
use Tests\Concerns\CreatesListingTables;
use Tests\TestCase;

/**
 * PATCH /api/listings/{listing}/verify — who is told about an audit outcome.
 *
 * An agent who has resigned, been terminated, gone inactive or been
 * deactivated can't act on the audit (EnsureAgentActive 403s everything but
 * their profile), so the "Action Required" email and the push are skipped —
 * and the response says why, so the auditor isn't left assuming the agent was
 * told. Admins stay notified whatever their agent row says.
 *
 * Mail is faked and nothing leaves the process; in-memory sqlite only.
 */
class ListingVerificationNotificationTest extends TestCase
{
    use CreatesAuthTables, CreatesListingTables;

    private const BLOCKED = ['inactive', 'resigned', 'deactivated', 'terminated'];

    /** @var list<array{0: int|string|null, 1: string}> [listing id, push type] */
    private array $pushed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        $this->createListingTables();

        Event::fake([AuditCustom::class]);
        $this->mock(AgentCachePurger::class)->shouldReceive('purgeAgent')->andReturnNull();
        $this->mock(ExpoPushService::class)
            ->shouldReceive('notify')
            ->andReturnUsing(function ($user, $type, $title, $body, $data = []) {
                $this->pushed[] = [$data['id'] ?? null, $type];
            });

        $auditorId = DB::table('users')->insertGetId([
            'name' => 'Auditor', 'email' => 'auditor@example.test', 'password' => 'x',
            'role_id' => $this->roleId('admin'), 'last_online_at' => now(),
        ]);
        Sanctum::actingAs(User::findOrFail($auditorId));
    }

    private function audit(int $listingId, ?string $outcome)
    {
        $this->pushed = [];
        Mail::fake();

        return $this->patchJson("/api/listings/{$listingId}/verify", [
            'verification_status' => $outcome,
            'audit_notes' => 'Photos do not match the unit.',
        ])->assertOk();
    }

    public function test_a_blocked_agent_is_told_nothing_about_any_audit_outcome(): void
    {
        $n = 0;
        foreach (self::BLOCKED as $agentStatus) {
            foreach (['flagged', 'verified', 'fully_verified'] as $outcome) {
                $label = "{$agentStatus} / {$outcome}";
                $owned = $this->seedOwnedListing(++$n, $agentStatus);

                $response = $this->audit($owned['listing'], $outcome);

                Mail::assertNothingSent();
                $this->assertSame([], $this->pushed, "{$label}: a push went out");
                $response->assertJsonPath('email_sent', false)
                    ->assertJsonPath('notification_skipped.reason', "agent_{$agentStatus}");
                $this->assertStringContainsString($agentStatus, $response->json('notification_skipped.message'), $label);

                // The audit itself is still recorded.
                $this->assertSame($outcome, DB::table('listings')->where('id', $owned['listing'])->value('verification_status'), $label);
            }
        }
    }

    public function test_an_active_agent_is_still_told(): void
    {
        $flagged = $this->seedOwnedListing(1);
        $response = $this->audit($flagged['listing'], 'flagged');
        Mail::assertSent(ListingFlaggedMailer::class, fn ($m) => $m->hasTo($flagged['email']));
        $this->assertSame([[$flagged['listing'], 'listing_flagged']], $this->pushed);
        $response->assertJsonPath('email_sent', true)->assertJsonPath('notification_skipped', null);

        $verified = $this->seedOwnedListing(2);
        $response = $this->audit($verified['listing'], 'verified');
        Mail::assertSent(ListingVerifiedMailer::class, fn ($m) => $m->hasTo($verified['email']));
        $this->assertSame([[$verified['listing'], 'listing_verified']], $this->pushed);
        $response->assertJsonPath('notification_skipped', null);

        // Fully verified has never sent an email — only the push.
        $full = $this->seedOwnedListing(3);
        $response = $this->audit($full['listing'], 'fully_verified');
        Mail::assertNothingSent();
        $this->assertSame([[$full['listing'], 'listing_fully_verified']], $this->pushed);
        $response->assertJsonPath('email_sent', false)->assertJsonPath('notification_skipped', null);
    }

    public function test_an_admin_is_still_told_whatever_their_agent_row_says(): void
    {
        $n = 0;
        foreach (self::BLOCKED as $agentStatus) {
            $owned = $this->seedOwnedListing(++$n, $agentStatus, 'admin');

            $response = $this->audit($owned['listing'], 'flagged');

            Mail::assertSent(ListingFlaggedMailer::class, fn ($m) => $m->hasTo($owned['email']));
            $this->assertSame([[$owned['listing'], 'listing_flagged']], $this->pushed, $agentStatus);
            $response->assertJsonPath('email_sent', true)->assertJsonPath('notification_skipped', null);
        }
    }

    public function test_no_notice_when_nothing_would_have_been_sent(): void
    {
        // pending_review and clearing the audit never email or push anyone, so
        // there is nothing to explain.
        $owned = $this->seedOwnedListing(1, 'resigned');

        $this->audit($owned['listing'], 'pending_review')->assertJsonPath('notification_skipped', null);
        $this->audit($owned['listing'], null)->assertJsonPath('notification_skipped', null);
    }

    public function test_a_soft_deleted_agent_keeps_the_old_behaviour(): void
    {
        // The relation is null for a trashed agent, so the existing "no agent
        // user/email" path applies — no mail, and not a blocked-agent notice.
        $owned = $this->seedOwnedListing(1, 'resigned', 'agent', true);

        $response = $this->audit($owned['listing'], 'flagged');

        Mail::assertNothingSent();
        $response->assertJsonPath('email_sent', false)->assertJsonPath('notification_skipped', null);
    }

    public function test_the_skip_is_logged(): void
    {
        Log::spy();
        $owned = $this->seedOwnedListing(1, 'terminated');

        $this->audit($owned['listing'], 'flagged');

        Log::shouldHaveReceived('info')
            ->with('Listing audit notification skipped — agent is not active', Mockery::on(
                fn ($context) => $context['listing_id'] === $owned['listing']
                    && $context['agent_status'] === 'terminated'
                    && $context['audit_status'] === 'flagged',
            ))
            ->once();
    }
}
