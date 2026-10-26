<?php

namespace App\Services\OpenAI;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The record behind "What people ask ASA": one row per question with its intent, the place it
 * named and how it ended. Writes never fail the reply — a missing table or a DB hiccup is swallowed.
 */
class AsaQuestionLog
{
    private static ?bool $available = null;

    private static function available(): bool
    {
        if (self::$available === null) {
            try {
                self::$available = Schema::hasTable('asa_questions');
            } catch (\Throwable) {
                self::$available = false;
            }
        }

        return self::$available;
    }

    /** Record a question as it is routed; the id rides on the request for a later outcome update. */
    public static function start(Request $request, string $question, string $intent, ?string $place = null): ?int
    {
        if (! self::available()) {
            return null;
        }
        try {
            $id = DB::table('asa_questions')->insertGetId([
                'device_id' => mb_substr((string) $request->input('device_id', ''), 0, 120) ?: null,
                'user_id' => Auth::guard('sanctum')->id(),
                'question' => mb_substr(trim($question), 0, 2000),
                'intent' => mb_substr($intent, 0, 24),
                'place' => $place !== null && $place !== '' ? mb_substr($place, 0, 120) : null,
                'outcome' => 'answered',
                'ms' => null,
                'created_at' => now(),
            ]);
            $request->attributes->set('asa_log_id', $id);
            $request->attributes->set('asa_log_t0', microtime(true));

            return $id;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Update the row this request started (outcome, elapsed time, the place once known). */
    public static function outcome(Request $request, string $outcome, ?string $place = null): void
    {
        $id = $request->attributes->get('asa_log_id');
        if (! $id || ! self::available()) {
            return;
        }
        try {
            $t0 = $request->attributes->get('asa_log_t0');
            $patch = ['outcome' => mb_substr($outcome, 0, 24)];
            if ($t0) {
                $patch['ms'] = (int) round((microtime(true) - $t0) * 1000);
            }
            if ($place !== null && $place !== '') {
                $patch['place'] = mb_substr($place, 0, 120);
            }
            DB::table('asa_questions')->where('id', $id)->update($patch);
        } catch (\Throwable) {
            // never fail the reply
        }
    }

    /**
     * The search half of a listing / agent question runs as a second request: update the latest
     * row of that intent for this device (within two minutes) with how the search ended.
     */
    public static function outcomeLatest(Request $request, string $intent, string $outcome, ?string $place = null): void
    {
        if (! self::available()) {
            return;
        }
        try {
            $device = (string) $request->input('device_id', '');
            $q = DB::table('asa_questions')->where('intent', $intent)->where('created_at', '>=', now()->subMinutes(2));
            if ($device !== '') {
                $q->where('device_id', $device);
            }
            $id = $q->orderByDesc('id')->value('id');
            if ($id) {
                $patch = ['outcome' => $outcome];
                if ($place !== null && $place !== '') {
                    $patch['place'] = mb_substr($place, 0, 120);
                }
                DB::table('asa_questions')->where('id', $id)->update($patch);
            }
        } catch (\Throwable) {
            // never fail the reply
        }
    }
}
