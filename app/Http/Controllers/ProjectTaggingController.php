<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\Project;
use App\Models\ProjectTagDismissal;
use App\Models\Property;
use App\Services\Project\ProjectTagAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * The admin "Project tagging" tab: listings whose title names a development they are not tagged to,
 * fixed one click at a time. See ProjectTagAudit for how a listing gets flagged.
 */
class ProjectTaggingController extends Controller
{
    public function __construct(private readonly ProjectTagAudit $audit)
    {
    }

    /** The flagged listings, the per-project rollup and the totals. Admins only. */
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->adminsOnly($request)) {
            return $denied;
        }

        return response()->json($this->audit->report());
    }

    /**
     * Tag a listing to a project: the property's project_id (and the "in a project" flag) is set, a
     * property name that only mirrored the old tag follows the new one, the listing's activity log gets
     * a "project_tagged" line, and every cache that counts the project's units is cleared.
     */
    public function tag(Request $request, Listing $listing): JsonResponse
    {
        if ($denied = $this->adminsOnly($request)) {
            return $denied;
        }
        $data = $request->validate(['project_id' => 'required|integer']);
        $project = Project::query()->whereNull('deleted_at')->find((int) $data['project_id']);
        if ($project === null) {
            return response()->json(['message' => 'That project does not exist.'], 422);
        }
        $property = Property::query()->find($listing->property_id);
        if ($property === null) {
            return response()->json(['message' => 'This listing has no property record.'], 422);
        }

        $oldId = $property->project_id ? (int) $property->project_id : null;
        $oldName = $oldId ? Project::withTrashed()->whereKey($oldId)->value('name') : null;
        $update = ['project_id' => $project->id, 'is_project' => true];
        $propertyName = trim((string) $property->name);
        if ($propertyName === '' || ($oldName !== null && mb_strtolower($propertyName) === mb_strtolower(trim((string) $oldName)))) {
            $update['name'] = (string) $project->name;
        }
        Property::query()->whereKey($property->id)->update($update);
        ProjectTagDismissal::query()->where('listing_id', $listing->id)->delete();

        $listing->auditEvent = 'project_tagged';
        $listing->isCustomEvent = true;
        $listing->auditCategoryOverride = 'listings';
        $listing->auditSource = 'project_tagging';
        $listing->auditDescription = 'Project: '.($oldName ?? 'none')." → {$project->name}";
        $listing->auditCustomOld = ['project_id' => $oldId, 'project' => $oldName];
        $listing->auditCustomNew = ['project_id' => (int) $project->id, 'project' => (string) $project->name];
        try {
            Event::dispatch(new AuditCustom($listing));
        } catch (\Throwable) {
            // The tag is saved either way; the activity line is a courtesy.
        }

        self::forgetProjectCaches(array_filter([$oldId, (int) $project->id]));

        return response()->json([
            'ok' => true,
            'listing_id' => (int) $listing->id,
            'project' => ['id' => (int) $project->id, 'name' => (string) $project->name],
        ]);
    }

    /** "Not this project": the listing is not in the development its title names; stop showing the pair. */
    public function dismiss(Request $request, Listing $listing): JsonResponse
    {
        if ($denied = $this->adminsOnly($request)) {
            return $denied;
        }
        $data = $request->validate(['project_id' => 'required|integer']);
        ProjectTagDismissal::query()->updateOrCreate(
            ['listing_id' => (int) $listing->id, 'project_id' => (int) $data['project_id']],
            ['user_id' => $request->user()?->id],
        );
        ProjectTagAudit::forget();

        return response()->json(['ok' => true]);
    }

    /** The development a title or address names, for the listing form's suggestion. Any signed-in agent. */
    public function detect(Request $request): JsonResponse
    {
        $text = trim((string) $request->query('text', ''));
        if (mb_strlen($text) < 4) {
            return response()->json(['project' => null]);
        }

        return response()->json(['project' => $this->audit->detect(mb_substr($text, 0, 300))]);
    }

    private function adminsOnly(Request $request): ?JsonResponse
    {
        return $request->user()?->role?->name === 'admin' ? null : response()->json(['message' => 'Admins only.'], 403);
    }

    /** @param  array<int, int>  $projectIds */
    public static function forgetProjectCaches(array $projectIds): void
    {
        ProjectTagAudit::forget();
        Cache::forget('projects_db');
        foreach ($projectIds as $id) {
            Cache::forget("project-insight:v2:{$id}");
        }
    }
}
