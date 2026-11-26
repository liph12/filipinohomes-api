<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Services\Listing\ListingFeedSwitch;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthTables;
use Tests\Concerns\CreatesListingFeedTables;
use Tests\TestCase;

/**
 * The listing sitemap tells Google when each listing REALLY last changed. The hourly rotation stamps `updated_at`
 * on listings nobody touched, so that column is left alone (anything else reading it is unaffected) and the sitemap
 * gets a `lastmod` built from dates that mean something.
 */
class SitemapRealLastmodTest extends TestCase
{
    use CreatesAuthTables;
    use CreatesListingFeedTables;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.guest_api_secret' => 'guest-secret']);

        $this->createAuthTables();
        DB::table('roles')->insert([['id' => 1, 'name' => 'admin'], ['id' => 2, 'name' => 'agent']]);
        $this->createListingFeedTables();
        ListingFeedSwitch::set(ListingFeedSwitch::LASTMOD, 'on'); // off is the default: see the first test below
    }

    private function guest(): static
    {
        $payload = json_encode(['exp' => time() + 600]);

        return $this->withHeader('X-Guest-Token', base64_encode($payload).'.'.hash_hmac('sha256', $payload, 'guest-secret'));
    }

    /** @return array<string, mixed> the sitemap row of one listing */
    private function row(string $endpoint, int $id): array
    {
        $data = $this->guest()->getJson("/api/sitemap/{$endpoint}?per_page=50")->assertOk()->json('data');

        return collect($data)->firstWhere('id', $id);
    }

    private function audit(int $listingId, string $when, ?int $userId, string $event = 'updated'): void
    {
        DB::table('audits')->insert([
            'event' => $event, 'auditable_type' => Listing::class, 'auditable_id' => $listingId,
            'user_id' => $userId, 'created_at' => $when, 'updated_at' => $when,
        ]);
    }

    public function test_by_default_the_sitemaps_are_exactly_what_they_were(): void
    {
        DB::table('settings')->where('key', ListingFeedSwitch::LASTMOD)->delete();
        ListingFeedSwitch::flush();
        $id = $this->feedListing(['created' => '2024-01-10 08:00:00', 'updated' => now()->toDateTimeString()]);

        $row = $this->row('listings', $id);
        $this->assertArrayNotHasKey('lastmod', $row);
        $this->assertSame(now()->toDateString(), substr((string) $row['updated_at'], 0, 10));
        $this->assertArrayNotHasKey('lastmod', $this->row('listing-images', $id));
    }

    public function test_a_row_without_a_creation_date_starts_from_its_last_update_instead_of_having_no_date(): void
    {
        $id = $this->feedListing(['updated' => '2025-02-02 10:00:00']);
        DB::table('listings')->where('id', $id)->update(['created_at' => null]);

        $this->assertSame('2025-02-02 10:00:00', $this->row('listings', $id)['lastmod']);
    }

    public function test_a_listing_nobody_touched_keeps_its_creation_date_whatever_the_rotation_stamped(): void
    {
        $id = $this->feedListing(['created' => '2024-01-10 08:00:00', 'updated' => now()->toDateTimeString()]);

        $row = $this->row('listings', $id);

        $this->assertSame('2024-01-10 08:00:00', $row['lastmod']);
        $this->assertSame(now()->toDateString(), substr((string) $row['updated_at'], 0, 10)); // untouched, for anything else that reads it
    }

    public function test_the_last_edit_by_a_person_counts_but_a_job_or_another_kind_of_event_does_not(): void
    {
        $id = $this->feedListing(['created' => '2024-01-10 08:00:00']);
        $this->audit($id, '2025-03-05 10:00:00', 7);               // an agent edited it
        $this->audit($id, '2025-09-01 10:00:00', null);            // a job (no user): not a person
        $this->audit($id, '2025-10-01 10:00:00', 7, 'created');    // not an edit
        $this->audit($id + 99, '2025-12-01 10:00:00', 7);          // someone else's listing

        $this->assertSame('2025-03-05 10:00:00', $this->row('listings', $id)['lastmod']);
    }

    public function test_an_audit_or_a_resubmission_counts_too_and_the_latest_wins(): void
    {
        $audited = $this->feedListing(['created' => '2024-01-10 08:00:00']);
        DB::table('listings')->where('id', $audited)->update(['audited_at' => '2025-04-01 09:00:00']);
        $resubmitted = $this->feedListing(['created' => '2024-01-10 08:00:00']);
        DB::table('listings')->where('id', $resubmitted)->update(['re_submitted_at' => '2025-06-01 09:00:00']);
        $both = $this->feedListing(['created' => '2024-01-10 08:00:00']);
        DB::table('listings')->where('id', $both)->update(['audited_at' => '2025-04-01 09:00:00']);
        $this->audit($both, '2025-08-15 12:00:00', 3);

        $this->assertSame('2025-04-01 09:00:00', $this->row('listings', $audited)['lastmod']);
        $this->assertSame('2025-06-01 09:00:00', $this->row('listings', $resubmitted)['lastmod']);
        $this->assertSame('2025-08-15 12:00:00', $this->row('listings', $both)['lastmod']);
    }

    public function test_the_image_sitemap_carries_it_too(): void
    {
        $id = $this->feedListing(['created' => '2024-01-10 08:00:00', 'updated' => now()->toDateTimeString()]);

        $this->assertSame('2024-01-10 08:00:00', $this->row('listing-images', $id)['lastmod']);
    }
}
