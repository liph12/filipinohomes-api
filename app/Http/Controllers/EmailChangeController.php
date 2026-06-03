<?php

namespace App\Http\Controllers;

use App\Mail\EmailChangeOtpMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmailChangeController extends Controller
{
    private const OTP_TTL = 10; // minutes
    private const LR_SIGNIN_URL = 'https://api.leuteriorealty.com/lr/v2/public/api/agent/sign-in';
    private const LR_AGENT_URL = 'https://api.leuteriorealty.com/lr/v2/public/api/agents';

    private function startChange(string $key, string $sendTo, string $name, array $payload): void
    {
        $otp = Str::upper(Str::random(6));
        Mail::to($sendTo)->send(new EmailChangeOtpMailer($sendTo, $otp, $name));
        Cache::put($key, ['otp' => $otp] + $payload, now()->addMinutes(self::OTP_TTL));
    }

    private function verifyOtp(string $key, string $otp): array
    {
        $pending = Cache::get($key);
        abort_if(!$pending, 422, 'Your verification code has expired. Please start again.');
        abort_unless(hash_equals((string) $pending['otp'], strtoupper(trim($otp))), 403, 'Invalid verification code.');
        Cache::forget($key);
        return $pending;
    }

    public function initiateUserEmail(Request $request)
    {
        $newEmail = strtolower(trim($request->validate([
            'new_email' => 'required|email|unique:users,email',
        ])['new_email']));

        $user = $request->user();
        abort_if($newEmail === strtolower($user->email), 422, 'The new email is the same as your current email.');

        $this->startChange("user_email_change_{$user->id}", $newEmail, $user->name, ['new_email' => $newEmail]);

        return response()->json(['message' => 'A verification code was sent to your new email.']);
    }

    public function confirmUserEmail(Request $request)
    {
        $otp = $request->validate(['otp' => 'required|string'])['otp'];
        $user = $request->user();
        $pending = $this->verifyOtp("user_email_change_{$user->id}", $otp);
        $request->merge(['new_email' => $pending['new_email']]);
        $request->validate(['new_email' => 'required|email|unique:users,email']);
        $user->update(['email' => $pending['new_email']]);

        return response()->json(['message' => 'Your email has been updated.', 'user' => $user->fresh()]);
    }

    public function initiateLrEmail(Request $request)
    {
        $data = $request->validate([
            'lr_email'    => 'required|email',
            'lr_password' => 'required|string',
        ]);
        $user = $request->user();
        abort_unless($user->agent, 403, 'Linking a Leuterio Realty email is only available for agent accounts.');
        $lr = $this->lrSignIn($data['lr_email'], $data['lr_password']);
        abort_if(!$lr, 422, 'Invalid Leuterio Realty credentials. Please check your email and password.');
        $lrEmail = strtolower(trim($lr['email'] ?? $data['lr_email']));
        $detail = $this->lrAgentDetail($lrEmail) ?? [];
        $birthday = trim((string) ($detail['birthday'] ?? ''));
        $this->startChange("lr_email_change_{$user->id}", $lrEmail, $user->name, [
            'lr_email'  => $lrEmail,
            'birthdate' => $birthday !== '' ? $birthday : null,
            'gender'    => match ($detail['gender'] ?? null) { 0, '0' => 'male', 1, '1' => 'female', default => null },
        ]);

        return response()->json(['message' => 'A verification code was sent to your Leuterio Realty email.']);
    }

    public function confirmLrEmail(Request $request)
    {
        $otp = $request->validate(['otp' => 'required|string'])['otp'];
        $user = $request->user();
        abort_unless($user->agent, 403, 'Agent profile not found.');
        $pending = $this->verifyOtp("lr_email_change_{$user->id}", $otp);
        $user->agent->update([
            'lr_email'  => $pending['lr_email'],
            'birthdate' => $pending['birthdate'],
            'gender'    => $pending['gender'],
        ]);

        return response()->json(['message' => 'Your Leuterio Realty email has been linked.', 'agent' => $user->agent->fresh()]);
    }

    private function lrSignIn(string $email, string $password): ?array
    {
        try {
            $res = Http::timeout(10)->acceptJson()->post(self::LR_SIGNIN_URL, [
                'email'    => strtolower(trim($email)),
                'password' => $password,
            ]);
            if (!$res->successful() || $res->json('success') !== true) {
                return null;
            }
            return $res->json('0.user');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function lrAgentDetail(string $email): ?array
    {
        try {
            $res = Http::timeout(10)->acceptJson()
                ->get(self::LR_AGENT_URL . '/' . urlencode(strtolower(trim($email))));
            return $res->successful() ? $res->json('data') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
