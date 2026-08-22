<?php

namespace App\Http\Controllers;

use App\Models\AppVersion;
use App\Models\Setting;

class AppConfigController extends Controller
{
    /**
     * Public bootstrap config the mobile app fetches on launch (and polls) to
     * decide whether to show the maintenance gate or force an update. Must work
     * without auth so the gate can render pre-login.
     */
    public function show()
    {
        $latest = AppVersion::where('platform', 'android')
            ->where('is_latest', true)
            ->first();

        $message       = Setting::get('maintenance_message');
        $updateMessage = Setting::get('update_message');

        return response()->json([
            // `maintenance` is the MOBILE general-maintenance flag (what AppGate
            // blocks non-admins on). `maintenance_web` is the website flag —
            // surfaced so the admin screens can show/toggle both from one place.
            'maintenance'     => Setting::get('maintenance_mode_mobile') === 'true',
            'maintenance_web' => Setting::get('maintenance_mode') === 'true',
            'message'         => $message !== '' ? $message : null,
            // `force_update` is an independent flag: when on, apps below
            // `min_app_version` are hard-blocked. `update_message` is the copy
            // shown on that "Update required" screen.
            'force_update'    => Setting::get('force_update_mobile') === 'true',
            'min_app_version' => Setting::get('min_app_version'),
            'update_message'  => $updateMessage !== '' ? $updateMessage : null,
            'downloads_url'   => config('app.downloads_url'),
            'latest'          => $latest ? [
                'version'      => $latest->version,
                'download_url' => $latest->download_url,
            ] : null,
        ]);
    }
}
