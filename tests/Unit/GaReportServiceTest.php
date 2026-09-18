<?php

namespace Tests\Unit;

use App\Services\Google\GaDataService;
use App\Services\Google\GaReportService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pure-HTTP tests for the GA4 data layer: service-account auth, report
 * assembly, source normalization, and the degrade-never-throw contract.
 * Everything is Http::fake'd — no DB, no network, no real GA property.
 */
class GaReportServiceTest extends TestCase
{
    private function configureGa(): void
    {
        // Real (throwaway) RSA key so the JWT signing path actually runs.
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        config([
            'services.ga4.property_id' => '123456789',
            'services.ga4.sa_key_base64' => base64_encode(json_encode([
                'client_email' => 'test-sa@example.iam.gserviceaccount.com',
                'private_key' => $pem,
            ])),
        ]);

        Cache::flush(); // no stale tokens/reports between tests
    }

    private function gaRow(array $dims, array $metrics): array
    {
        return [
            'dimensionValues' => array_map(fn ($v) => ['value' => (string) $v], $dims),
            'metricValues' => array_map(fn ($v) => ['value' => (string) $v], $metrics),
        ];
    }

    public function test_unconfigured_service_reports_not_configured(): void
    {
        config(['services.ga4.property_id' => null, 'services.ga4.sa_key_base64' => null]);

        $this->assertFalse(app(GaDataService::class)->configured());
        $this->assertFalse(app(GaReportService::class)->configured());
    }

    public function test_website_report_assembles_sections_and_normalizes_sources(): void
    {
        $this->configureGa();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-token']),
            'analyticsdata.googleapis.com/*' => Http::sequence()
                // totals (current) — matches the metric order in totals()
                ->push(['rows' => [$this->gaRow([], [100, 150, 400, 60, 62.4, 0.5123])]])
                // totals (previous window)
                ->push(['rows' => [$this->gaRow([], [50, 100, 200, 30, 60, 0.4])]])
                // trend (daily)
                ->push(['rows' => [
                    $this->gaRow(['20260916'], [40]),
                    $this->gaRow(['20260917'], [60]),
                ]])
                // pages — /admin must be filtered out
                ->push(['rows' => [
                    $this->gaRow(['/'], [200, 90]),
                    $this->gaRow(['/admin/insights-analytics'], [150, 10]),
                    $this->gaRow(['/for-sale/house/in-cebu-city-cebu'], [120, 70]),
                ]])
                // channels
                ->push(['rows' => [$this->gaRow(['Organic Search'], [90])]])
                // sources — facebook variants must collapse into one row
                ->push(['rows' => [
                    $this->gaRow(['m.facebook.com'], [30]),
                    $this->gaRow(['facebook.com'], [25]),
                    $this->gaRow(['l.facebook.com'], [5]),
                    $this->gaRow(['(direct)'], [40]),
                ]])
                // countries
                ->push(['rows' => [$this->gaRow(['Philippines', 'PH'], [80])]])
                // cities
                ->push(['rows' => [$this->gaRow(['Cebu City', 'Philippines'], [50])]])
                // devices
                ->push(['rows' => [$this->gaRow(['mobile'], [70])]])
                // lead events
                ->push(['rows' => [$this->gaRow(['submit_inquiry'], [7])]]),
        ]);

        $report = app(GaReportService::class)->websiteReport('2026-09-11', '2026-09-17');

        // Totals + change vs the previous window (100 vs 50 = +100%).
        $this->assertSame(100, $report['totals']['active_users']);
        $this->assertSame(62, $report['totals']['avg_session_duration_sec']);
        $this->assertSame(100.0, $report['change_vs_previous']['active_users_pct']);

        // Previous window arithmetic: 7 inclusive days ending the day before.
        $this->assertSame('2026-09-04', $report['range']['prev_from']);
        $this->assertSame('2026-09-10', $report['range']['prev_to']);

        // Internal paths are stripped from top pages.
        $paths = array_column($report['top_pages'], 'path');
        $this->assertContains('/', $paths);
        $this->assertNotContains('/admin/insights-analytics', $paths);

        // facebook.com variants collapse into one top row (30+25+5 = 60 > direct 40).
        $this->assertSame('facebook.com', $report['sources'][0]['source']);
        $this->assertSame(60, $report['sources'][0]['sessions']);
        $this->assertSame('direct', $report['sources'][1]['source']);

        // Lead events always carry all four keys.
        $this->assertSame(7, $report['lead_events']['submit_inquiry']);
        $this->assertSame(0, $report['lead_events']['click_phone']);
    }

    public function test_section_failures_degrade_to_null_not_500(): void
    {
        $this->configureGa();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-token']),
            // Every GA call fails — the report must still return, all-null.
            'analyticsdata.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 429),
        ]);

        $report = app(GaReportService::class)->websiteReport('2026-09-16', '2026-09-17');

        $this->assertNull($report['totals']);
        $this->assertNull($report['top_pages']);
        $this->assertNull($report['change_vs_previous']);
        $this->assertSame('2026-09-16', $report['range']['from']);
    }
}
