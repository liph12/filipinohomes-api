<?php

namespace App\Http\Controllers;

use App\Http\Resources\BuyerFormResource;
use App\Http\Resources\BuyerFormRegistrationResource;
use App\Models\Agent;
use App\Models\BuyerForm;
use App\Models\Project;
use App\Models\BuyerFormRegistration;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BuyerFormController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $user = $request->user();
        $agent = Agent::where('user_id', $user->id)->first();

        $query = BuyerForm::with(['propertyType', 'project', 'agent'])
            ->withCount('registrations')
            ->latest();

        if ($user->role?->name !== 'admin') {
            $query->where('agent_id', $agent?->id);
        }

        return BuyerFormResource::collection(
            $query->paginate((int) $request->input('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $agent = Agent::where('user_id', $user->id)->first();

        if (!$agent) {
            return response()->json(['message' => 'Only agents can create buyer forms'], 403);
        }

        $data = $request->validate([
            'title'            => 'required|string|max:255',
            // Optional: a buyer form is often a generic search ("2BR condo in
            // Cebu City"), and the column has always been nullable. Requiring
            // it was what blocked agents whose project was not in the list.
            'project_id'       => 'nullable|integer|exists:projects,id',
            // A project the agent typed that is not in the list yet. Found by
            // name or created — the listing form's behaviour, so the next
            // agent finds it in the dropdown.
            'project_name'     => 'nullable|string|max:100',
            'description'      => 'nullable|string',
            'location'         => 'nullable|string|max:255',
            'property_type_id' => 'nullable|integer|exists:property_types,id',
        ]);

        $projectName = trim((string) ($data['project_name'] ?? ''));
        unset($data['project_name']);

        if (empty($data['project_id']) && $projectName !== '') {
            $data['project_id'] = $this->findOrCreateProject($request, $projectName, $data['location'] ?? null)->id;
        }

        $data['agent_id'] = $agent->id;

        $form = BuyerForm::create($data);

        return (new BuyerFormResource($form->load(['propertyType', 'project', 'agent'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * The project an agent typed rather than picked.
     *
     * Matched on the normalised name first, so "the residences" and "The
     * Residences " land on the same row. Only then created — with the form's
     * location as its address, which is all a buyer form knows about it — and
     * only for a user the ProjectPolicy allows to create projects (admins and
     * agents). The model fills slug, added_by and created_by itself.
     */
    private function findOrCreateProject(Request $request, string $name, ?string $location): Project
    {
        $clean = Str::of($name)->squish()->toString();

        $existing = Project::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($clean)])
            ->orderByRaw('CASE WHEN featured_photo IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $this->authorize('create', Project::class);

        // The projects table refuses NULL in these five columns and has no
        // defaults, so an unknown address and coordinates are stored as the
        // empty string — exactly what ListingService::buildProjectPayload does.
        $address = trim((string) $location);

        return Project::create([
            'name'             => $clean,
            'mapaddress'       => $address,
            'complete_address' => $address,
            'latitude'         => '',
            'longitude'        => '',
            'date_updated'     => now(),
        ]);
    }

    public function show(string $slug)
    {
        $form = BuyerForm::where('slug', $slug)
            ->with(['propertyType', 'project', 'agent'])
            ->firstOrFail();

        return new BuyerFormResource($form);
    }

    public function destroy(Request $request, $id)
    {
        $form = BuyerForm::findOrFail($id);
        $this->authorize('delete', $form);

        $form->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function registrations(Request $request, $id)
    {
        $form = BuyerForm::findOrFail($id);
        $this->authorize('viewRegistrations', $form);

        return BuyerFormRegistrationResource::collection(
            $form->registrations()->latest()->get()
        );
    }

    public function register(Request $request, string $slug)
    {
        $form = BuyerForm::where('slug', $slug)->firstOrFail();
        $user = $request->user();

        $v = $request->validate([
            // max:255 matches the VARCHAR(255) home_address column so an
            // over-long address returns a clean 422 instead of a DB 500.
            'home_address' => 'nullable|string|max:255',
        ]);

        $reg = BuyerFormRegistration::firstOrCreate(
            [
                'buyer_form_id' => $form->id,
                'user_id'       => $user->id,
            ],
            [
                'full_name'    => $user->name,
                'email'        => $user->email,
                'home_address' => $v['home_address'] ?? null,
            ]
        );

        return (new BuyerFormRegistrationResource($reg))
            ->response()
            ->setStatusCode(201);
    }
}
