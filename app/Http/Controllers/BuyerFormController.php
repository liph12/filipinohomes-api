<?php

namespace App\Http\Controllers;

use App\Http\Resources\BuyerFormResource;
use App\Http\Resources\BuyerFormRegistrationResource;
use App\Models\Agent;
use App\Models\BuyerForm;
use App\Models\BuyerFormRegistration;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

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
            'project_id'       => 'required|integer|exists:projects,id',
            'description'      => 'nullable|string',
            'location'         => 'nullable|string|max:255',
            'property_type_id' => 'nullable|integer|exists:property_types,id',
        ]);

        $data['agent_id'] = $agent->id;

        $form = BuyerForm::create($data);

        return (new BuyerFormResource($form->load(['propertyType', 'project', 'agent'])))
            ->response()
            ->setStatusCode(201);
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
