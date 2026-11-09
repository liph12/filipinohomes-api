<?php

namespace App\Support;

use App\Models\Agent;
use App\Models\BriefLink;
use App\Models\ClientBrief;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The Buyers & Sellers Link briefs, read from config/briefs.php: the
 * definitions the site renders, the parser that narrows a submission to
 * known option keys and capped free text, the buyer's lead grade, the
 * agent's public face on the brief pages, and the link's slug and code.
 */
final class Briefs
{
    /** Codes use an alphabet with no look-alikes (0/o, 1/l/i). */
    private const CODE_ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';

    private const BLOCKED_AGENT = ['inactive', 'resigned', 'deactivated'];

    // ── Definitions ─────────────────────────────────────────────────────

    /** Both briefs as the site needs them: options as {value, label}. */
    public static function definitions(): array
    {
        $c = config('briefs');
        $opts = fn (array $pairs) => array_map(fn ($p) => ['value' => $p[0], 'label' => $p[1]], $pairs);
        $form = function (array $f) use ($opts) {
            $questions = [];
            foreach ($f['questions'] as $key => $q) {
                $questions[$key] = [
                    'label' => $q['label'],
                    'short' => $q['short'],
                    'multi' => $q['multi'],
                    'options' => $opts($q['options']),
                    'show_when' => $q['show_when'] ?? null,
                ];
            }

            return ['steps' => $f['steps'], 'required' => $f['required'], 'questions' => $questions, 'texts' => $f['texts']];
        };
        $grades = [];
        foreach ($c['grade']['labels'] as $key => $g) {
            $grades[] = ['value' => $key, 'label' => $g['label'], 'why' => $g['why']];
        }

        return [
            'contact_time' => $opts($c['contact_time']),
            'budget' => $opts($c['budget']),
            'grades' => $grades,
            'buyer' => $form($c['buyer']),
            'seller' => $form($c['seller']),
        ];
    }

    // ── Parsing ─────────────────────────────────────────────────────────

    /**
     * Keep only known questions with known option keys, drop answers whose
     * `show_when` rule fails, and trim and cap the free-text fields (digits
     * only where asked). Every submission goes through this, so stored
     * briefs are always clean.
     */
    public static function parseProfile(string $kind, mixed $raw): array
    {
        $f = config("briefs.$kind");
        $in = is_array($raw) ? $raw : [];
        $out = [];
        foreach ($f['questions'] as $key => $q) {
            if (! self::applies($q['show_when'] ?? null, $out)) {
                continue;
            }
            $allowed = array_column($q['options'], 0);
            $v = $in[$key] ?? null;
            if ($q['multi']) {
                if (is_array($v)) {
                    $picked = array_values(array_unique(array_filter($v, fn ($x) => is_string($x) && in_array($x, $allowed, true))));
                    if ($picked !== []) {
                        $out[$key] = $picked;
                    }
                }
            } elseif (is_string($v) && in_array($v, $allowed, true)) {
                $out[$key] = $v;
            }
        }
        foreach ($f['texts'] as $key => $t) {
            $v = $in[$key] ?? null;
            if (isset($t['list'])) {
                if (! is_array($v)) {
                    continue;
                }
                $items = [];
                foreach ($v as $x) {
                    if (! is_string($x)) {
                        continue;
                    }
                    $clean = mb_substr(trim(preg_replace('/\s+/u', ' ', $x)), 0, $t['max']);
                    if ($clean !== '' && ! in_array($clean, $items, true)) {
                        $items[] = $clean;
                    }
                    if (count($items) >= $t['list']) {
                        break;
                    }
                }
                if ($items !== []) {
                    $out[$key] = $items;
                }

                continue;
            }
            if (! is_string($v)) {
                continue;
            }
            $clean = ! empty($t['digits']) ? ltrim(preg_replace('/\D/', '', $v), '0') : trim($v);
            if ($clean !== '') {
                $out[$key] = mb_substr($clean, 0, $t['max']);
            }
        }

        return $out;
    }

    /** Whether a question's `show_when` rule holds for the answers parsed so far. */
    public static function applies(?array $rule, array $answers): bool
    {
        if (! $rule) {
            return true;
        }
        $v = $answers[$rule['key']] ?? null;
        if (isset($rule['in'])) {
            return is_string($v) && in_array($v, $rule['in'], true);
        }
        if (isset($rule['not_in'])) {
            return ! is_string($v) || ! in_array($v, $rule['not_in'], true);
        }

        return true;
    }

    // ── Labels ──────────────────────────────────────────────────────────

    /** "House and Lot, Condominium" for a stored answer, or null when unanswered. */
    public static function label(string $kind, string $key, mixed $value): ?string
    {
        $q = config("briefs.$kind.questions.$key");
        if (! $q || $value === null || $value === [] || $value === '') {
            return null;
        }
        $names = [];
        foreach ($q['options'] as [$k, $l]) {
            $names[$k] = $l;
        }
        $one = fn ($v) => $names[$v] ?? (string) $v;

        return is_array($value) ? implode(', ', array_map($one, $value)) : $one($value);
    }

    public static function budgetLabel(?string $budget): ?string
    {
        foreach (config('briefs.budget') as $b) {
            if ($b[0] === $budget) {
                return $b[1];
            }
        }

        return null;
    }

    public static function contactTimeLabel(?string $value): ?string
    {
        foreach (config('briefs.contact_time') as [$k, $l]) {
            if ($k === $value) {
                return $l;
            }
        }

        return null;
    }

    /** "₱2,500,000" from stored digits. */
    public static function pesos(?string $digits): ?string
    {
        return $digits !== null && $digits !== '' && ctype_digit($digits) ? '₱'.number_format((int) $digits) : null;
    }

    /**
     * The brief as the agent reads it, in the client's four steps, with the
     * contact fields first. Used by the email; the dashboard builds the same
     * from the definitions.
     *
     * @return array<int, array{title: string, rows: array<int, array{0: string, 1: string}>}>
     */
    public static function sections(ClientBrief $brief): array
    {
        $kind = $brief->kind;
        $p = $brief->profile ?? [];
        $txt = fn (string $k) => is_string($p[$k] ?? null) ? $p[$k] : '';
        $row = fn (string $label, ?string $value) => ($value === null || $value === '') ? null : [$label, $value];
        $contact = [
            $row('Phone', trim($brief->phone_code.' '.$brief->phone)),
            $row('Email', $brief->email),
            $row('Nationality', $txt('nationality')),
            $row('Best time', self::contactTimeLabel($brief->contact_time)),
        ];
        $sections = [];
        foreach (config("briefs.$kind.steps") as $i => $step) {
            $rows = $i === 0 ? $contact : [];
            if ($i === 0 && $kind === 'buyer') {
                // nothing extra: the budget leads the financials step
            }
            foreach ($step['keys'] as $key) {
                $q = config("briefs.$kind.questions.$key");
                $value = self::label($kind, $key, $p[$key] ?? null);
                if ($kind === 'buyer' && $key === 'areas') {
                    $cities = is_array($p['areas_cities'] ?? null) ? implode(', ', $p['areas_cities']) : '';
                    $value = implode(', ', array_filter([$value, $cities]));
                }
                if ($kind === 'seller' && $key === 'area') {
                    $value = implode(', ', array_filter([$value, $txt('city')]));
                }
                if ($kind === 'buyer' && $key === 'income_source') {
                    $rows[] = $row($q['short'], $value);
                    $rows[] = $row('Profession', $txt('profession'));
                    $rows[] = $row(in_array($p['income_source'] ?? '', ['business', 'self_employed'], true) ? 'Business' : 'Position', $txt('position'));

                    continue;
                }
                $rows[] = $row($q['short'], $value);
                if ($kind === 'seller' && $key === 'area') {
                    $rows[] = $row('Subdivision / building', $txt('building'));
                }
                if ($kind === 'seller' && $key === 'furnishing') {
                    $rows[] = $row('Lot area', $txt('lot_area_sqm') !== '' ? number_format((int) $txt('lot_area_sqm')).' sqm' : null);
                    $rows[] = $row('Floor area', $txt('floor_area_sqm') !== '' ? number_format((int) $txt('floor_area_sqm')).' sqm' : null);
                }
                if ($kind === 'seller' && $key === 'tenancy_ends') {
                    $rows[] = $row('Asking price', self::pesos($txt('asking_price')));
                }
            }
            if ($kind === 'buyer' && $step['id'] === 'financials') {
                array_unshift($rows, $row('Budget', self::budgetLabel($brief->budget)));
            }
            $sections[] = [
                'title' => $i === 0 ? 'Contact' : $step['title'],
                'rows' => array_values(array_filter($rows)),
            ];
        }
        if ($brief->message) {
            $sections[] = ['title' => 'Message', 'rows' => [['Message', $brief->message]]];
        }

        return $sections;
    }

    // ── The lead grade ──────────────────────────────────────────────────

    /**
     * A buyer's grade from their answers, so the agent knows who to call
     * first. Plain rules: Priority = ₱3M or more, buying within 6 months,
     * funds in place (cash, in-house, or a loan pre-approved or in progress)
     * and ready to go ahead; Qualified = ₱1M or more, within a year, not
     * only exploring; Information = only exploring; anything else Nurture.
     */
    public static function grade(?string $budget, array $profile): string
    {
        $floor = 0;
        foreach (config('briefs.budget') as $b) {
            if ($b[0] === $budget) {
                $floor = $b[2];
            }
        }
        $s = fn (string $k) => is_string($profile[$k] ?? null) ? $profile[$k] : '';
        $t = $s('buy_timeline');
        $r = $s('readiness');
        $pay = $s('payment');
        $loan = $s('loan_status');
        $soon = in_array($t, ['asap', '1_3m', '3_6m'], true);
        $withinYear = $soon || $t === '6_12m';
        $keen = $r === '' || in_array($r, ['reserve', 'serious'], true);
        $funds = $pay === 'cash' || $pay === 'in_house'
            || (in_array($pay, ['bank_loan', 'pagibig'], true) && in_array($loan, ['approved', 'in_progress'], true));

        if ($r === 'exploring' || ($t === 'exploring' && $r === 'info')) {
            return 'info';
        }
        if ($floor >= config('briefs.grade.priority_min') && $soon && $keen && $funds) {
            return 'priority';
        }
        if ($floor >= config('briefs.grade.qualified_min') && $withinYear && $r !== 'exploring') {
            return 'qualified';
        }

        return 'nurture';
    }

    // ── The owner ───────────────────────────────────────────────────────

    /**
     * Who may have a link: every agent, and every admin (with or without an
     * agent row). Clients and other users have none.
     */
    public static function canOwnLink(?User $user, ?Agent $agent): bool
    {
        return $agent !== null || ($user !== null && $user->isAdmin());
    }

    /** Where the owner reads their briefs on the dashboard. */
    public static function dashboardPath(?User $user): string
    {
        return $user?->isAdmin() ? '/admin/buyers-link' : '/agent/buyers-link';
    }

    /**
     * The person behind a link as the public page shows them, or null when
     * their access is gone (a link dies with it). An agent's link stops when
     * they are blocked; an admin's stays up whatever their agent row says,
     * like the rest of the admin's access.
     *
     * @return array{id: int, name: string, first_name: string, initials: string, photo: ?string, whatsapp: string, phone: string}|null
     */
    public static function ownerFace(?Agent $agent, ?User $user): ?array
    {
        $admin = $user !== null && $user->isAdmin();
        if (! $admin && (! $agent || in_array($agent->status, self::BLOCKED_AGENT, true))) {
            return null;
        }
        $user ??= $agent?->user;
        $name = self::ownerName($agent, $user);
        $photo = AvatarUrl::clean($agent?->avatar ?? $user?->avatar);
        if (is_array($photo)) {
            $photo = $photo[0] ?? null;
        }
        $phone = self::phoneDigits('+63', $agent?->mobile_no ?: $user?->mobile_no);
        $whatsapp = self::phoneDigits('+63', $agent?->whats_app_no) ?: $phone;
        $words = preg_split('/\s+/', $name) ?: [];

        return [
            'id' => (int) ($agent?->id ?? $user?->id ?? 0),
            'name' => $name,
            'first_name' => $words[0] ?? $name,
            'initials' => mb_strtoupper(mb_substr($words[0] ?? 'F', 0, 1).mb_substr($words[count($words) - 1] ?? '', 0, 1)),
            'photo' => is_string($photo) && $photo !== '' ? $photo : null,
            'whatsapp' => $whatsapp,
            'phone' => $phone,
        ];
    }

    /** The owner's name as shown everywhere: the agent row's first, else the account's. */
    public static function ownerName(?Agent $agent, ?User $user): string
    {
        $name = $agent ? trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([$agent->first_name, $agent->last_name]))) ?? '') : '';
        $name = $name !== '' ? $name : trim((string) (($user ?? $agent?->user)?->name ?? ''));

        return $name !== '' ? ucwords(mb_strtolower($name)) : 'Your Filipino Homes agent';
    }

    /** @deprecated use ownerName(); kept for callers that only have the agent. */
    public static function agentName(Agent $agent): string
    {
        return self::ownerName($agent, null);
    }

    /**
     * The international digits wa.me and tel: need: "+63" + "0917 123 4567"
     * → "639171234567". A number typed with "+" is used as written; one
     * already starting with the country code is kept; a national one drops
     * its trunk 0 behind the code.
     */
    public static function phoneDigits(?string $code, ?string $number): string
    {
        $n = trim((string) $number);
        if ($n === '') {
            return '';
        }
        $digits = preg_replace('/\D/', '', $n);
        if (str_starts_with($n, '+')) {
            return $digits;
        }
        $cc = preg_replace('/\D/', '', (string) ($code ?: '+63'));
        if ($cc !== '' && str_starts_with($digits, $cc) && strlen($digits) >= strlen($cc) + 9) {
            return $digits;
        }
        $national = ltrim($digits, '0');

        return $national === '' ? '' : $cc.$national;
    }

    // ── The link ────────────────────────────────────────────────────────

    /**
     * The readable address, minted once from the owner's name ("MICHELLE Q.
     * GUINTO" → "michelle-guinto"; an admin without an agent row from their
     * account name), then -2, -3, … when the name is taken.
     */
    public static function slugFor(?Agent $agent, ?User $user = null): string
    {
        $first = trim((string) $agent?->first_name);
        $last = trim((string) $agent?->last_name);
        $base = Str::slug($first.' '.$last) ?: Str::slug((string) (($user ?? $agent?->user)?->name ?? ''));
        $base = Str::limit($base ?: 'fh-agent', 60, '');
        $taken = BriefLink::where('slug', $base)->orWhere('slug', 'like', $base.'-%')->pluck('slug')->all();
        for ($n = 1; $n <= 500; $n++) {
            $candidate = $n === 1 ? $base : $base.'-'.$n;
            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }

        return $base.'-'.Str::lower(Str::random(6));
    }

    /** The link's internal id: 8 characters, no look-alikes, unused. */
    public static function mintCode(): string
    {
        $alphabet = self::CODE_ALPHABET;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            if (! BriefLink::where('code', $code)->exists()) {
                return $code;
            }
        }

        return Str::lower(Str::random(8));
    }

    /** The site, for links in the agent's email. */
    public static function siteUrl(): string
    {
        // The same FRONTEND_URL the other mailers use; the default is right in production.
        return rtrim((string) env('FRONTEND_URL', 'https://filipinohomes.com'), '/');
    }
}
