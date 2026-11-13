<?php

namespace App\Http\Controllers;

use App\Services\Listing\AssumeFinanceSettings;
use Illuminate\Http\Request;

/**
 * The financing assumptions behind every "For Assume" estimate.
 *
 * Exists so these are an office setting, not a deploy: the Pag-IBIG
 * promotional rate in the defaults expires 31 December 2026, and on 1 January
 * someone must be able to correct it before the site starts quoting a monthly
 * payment that no longer exists.
 *
 * `config()` is public — the listing wizard's live preview needs the same
 * numbers the server computes with, so an agent isn't typing against a
 * different set of assumptions than the page will publish.
 */
class AssumeSettingsController extends Controller
{
    public function __construct(private AssumeFinanceSettings $settings)
    {
    }

    /** Public: the rates the site is currently pricing with. */
    public function config()
    {
        return response()->json($this->settings->settings());
    }

    /** Admin: read the same values for the settings form. */
    public function show()
    {
        return response()->json($this->settings->settings());
    }

    /** Admin: update them. Values are clamped by the service on the way in and out. */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'bank_rate_pct' => 'required|numeric|min:0|max:30',
            'bank_term_years' => 'required|integer|min:1|max:40',
            'pagibig_rate_pct' => 'required|numeric|min:0|max:30',
            'pagibig_term_years' => 'required|integer|min:1|max:40',
            'pagibig_max_loan' => 'required|numeric|min:0',
            'dti_ratio' => 'required|numeric|min:0.10|max:0.60',
        ]);

        return response()->json($this->settings->saveSettings($validated));
    }
}
