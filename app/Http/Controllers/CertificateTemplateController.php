<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The Top 10 certificate templates (Top Listing Creators → Certificate Template). Agents and teams each
 * keep a LIBRARY of templates and pick the one in use (none = the built-in certificate). A template is an
 * uploaded image plus where the name, the description and — optionally — the photo / logo go (boxes as
 * fractions of the image, like the NATCON frame areas), the photo's shape / background / border, and a
 * title to find it by. The wording itself is fixed in the frontend (certificateLines) and only stored for
 * reference. Stored as JSON in the settings KV: certificate_templates_{kind} = { items: [...], schedule }.
 * `schedule` assigns templates to months ({"2026-04": id | "builtin"}); a month left out repeats the last
 * run of assigned months in order (resolved in the frontend, certificateTemplateFor).
 * A single template saved before the library existed (certificate_template_{kind}) becomes its first item;
 * the template "in use" from before the schedule existed (`active`) becomes April 2026's.
 */
class CertificateTemplateController extends Controller
{
    private const KINDS = ['agent', 'team'];

    /** The schedule value for the built-in certificate. */
    private const BUILTIN = 'builtin';

    /** The first month Top 10 certificates exist — where an old "in use" template is scheduled. */
    private const FIRST_MONTH = '2026-04';

    /** GET /certificate-templates — every signed-in user (certificates are drawn in the browser). */
    public function show(): JsonResponse
    {
        $out = [];
        foreach (self::KINDS as $kind) {
            $out[$kind] = $this->out($this->library($kind));
        }

        return response()->json($out);
    }

    /** POST /admin/certificate-templates/upload — the template image to S3; returns its URL + size. */
    public function upload(Request $request): JsonResponse
    {
        $request->validate(['image' => 'required|image|mimes:png,jpg,jpeg,webp|max:20480']);
        $file = $request->file('image');
        [$width, $height] = getimagesize($file->getRealPath()) ?: [0, 0];
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        $path = '/filipinohomes-new/certificates/templates/'.Str::uuid().'.'.$ext;
        Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()), 'public');

        return response()->json([
            'image_url' => config('filesystems.disks.s3.url').$path,
            'width' => (int) $width,
            'height' => (int) $height,
        ]);
    }

    /** POST /admin/certificate-templates/{kind} — add a template to the library (not put in use). */
    public function store(Request $request, string $kind): JsonResponse
    {
        $this->kind($kind);
        $data = $this->validated($request);
        $data['id'] = (string) Str::uuid();
        $data['updated_at'] = now()->toIso8601String();
        $lib = $this->library($kind);
        $lib['items'][] = $data;
        $this->save($kind, $lib);

        return response()->json($data, 201);
    }

    /** PUT /admin/certificate-templates/{kind}/{id} — save a template's image, areas, photo look, name. */
    public function update(Request $request, string $kind, string $id): JsonResponse
    {
        $this->kind($kind);
        $lib = $this->library($kind);
        $i = $this->index($lib, $id);
        $data = $this->validated($request);
        $data['id'] = $id;
        $data['updated_at'] = now()->toIso8601String();
        $lib['items'][$i] = $data;
        $this->save($kind, $lib);

        return response()->json($data);
    }

    /**
     * PUT /admin/certificate-templates/{kind}/schedule — assign a month's template: a template id,
     * "builtin", or null (unassigned — it follows the months before it).
     */
    public function schedule(Request $request, string $kind): JsonResponse
    {
        $this->kind($kind);
        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'id' => 'nullable|string',
        ]);
        $lib = $this->library($kind);
        $id = $data['id'] ?? null;
        if ($id !== null && $id !== self::BUILTIN) {
            $this->index($lib, $id);
        }
        if ($id === null) {
            unset($lib['schedule'][$data['month']]);
        } else {
            $lib['schedule'][$data['month']] = $id;
        }
        ksort($lib['schedule']);
        $this->save($kind, $lib);

        return response()->json($this->out($lib));
    }

    /** DELETE /admin/certificate-templates/{kind}/{id} — remove one; the months it was assigned to are unassigned. */
    public function destroy(string $kind, string $id): JsonResponse
    {
        $this->kind($kind);
        $lib = $this->library($kind);
        $i = $this->index($lib, $id);
        array_splice($lib['items'], $i, 1);
        $lib['schedule'] = array_filter($lib['schedule'], fn ($v) => $v !== $id);
        $this->save($kind, $lib);

        return response()->json($this->out($lib));
    }

    private function kind(string $kind): void
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);
    }

    /** @return array{items: array<int, array<string, mixed>>, schedule: array<string, string>} */
    private function library(string $kind): array
    {
        $raw = Setting::get("certificate_templates_{$kind}");
        if ($raw) {
            $lib = json_decode($raw, true) ?: [];
            $active = $lib['active'] ?? null;
        } else {
            // The single template saved before the library existed becomes its first item, in use.
            $legacy = Setting::get("certificate_template_{$kind}");
            $tpl = $legacy ? json_decode($legacy, true) : null;
            if ($tpl) {
                $tpl['id'] ??= (string) Str::uuid();
                $tpl['title'] ??= 'Template 1';
            }
            $lib = ['items' => $tpl ? [$tpl] : []];
            $active = $tpl['id'] ?? null;
        }
        // Before the schedule, one template was "in use" for every month: it becomes the first month's,
        // which every later unassigned month then follows — the same certificates as before.
        $schedule = array_key_exists('schedule', $lib)
            ? (array) $lib['schedule']
            : ($active ? [self::FIRST_MONTH => $active] : []);

        return ['items' => array_values($lib['items'] ?? []), 'schedule' => $schedule];
    }

    /** The library as JSON — the schedule always an object, even when empty. */
    private function out(array $lib): array
    {
        return ['items' => $lib['items'], 'schedule' => (object) $lib['schedule']];
    }

    private function save(string $kind, array $lib): void
    {
        Setting::set("certificate_templates_{$kind}", json_encode($lib));
    }

    private function index(array $lib, string $id): int
    {
        foreach ($lib['items'] as $i => $item) {
            if (($item['id'] ?? null) === $id) {
                return $i;
            }
        }
        abort(404, 'Template not found.');
    }

    private function validated(Request $request): array
    {
        $box = fn (string $k) => [
            "{$k}" => 'required|array',
            "{$k}.x" => 'required|numeric|between:0,1',
            "{$k}.y" => 'required|numeric|between:0,1',
            "{$k}.w" => 'required|numeric|between:0.01,1',
            "{$k}.h" => 'required|numeric|between:0.01,1',
        ];

        return $request->validate([
            // What it's called in the template list (`name` is the name text's box).
            'title' => 'required|string|max:80',
            'image_url' => 'required|url|max:1000',
            'width' => 'required|integer|min:1',
            'height' => 'required|integer|min:1',
            // Optional photo / logo area: a circle (inside the box) or the whole box, with an optional fill.
            'photo' => 'nullable|array',
            'photo.x' => 'required_with:photo|numeric|between:0,1',
            'photo.y' => 'required_with:photo|numeric|between:0,1',
            'photo.w' => 'required_with:photo|numeric|between:0.01,1',
            'photo.h' => 'required_with:photo|numeric|between:0.01,1',
            'photo_shape' => 'nullable|in:circle,box',
            'photo_bg' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'photo_border' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            // Border thickness as a share of the shape's smaller side (0.5% – 20%).
            'photo_border_width' => 'nullable|numeric|between:0.005,0.2',
            ...$box('name'),
            ...$box('description'),
            'description_text' => 'required|string|max:600',
            'name_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
    }
}
