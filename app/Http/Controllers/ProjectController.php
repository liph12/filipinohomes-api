<?php

namespace App\Http\Controllers;

use App\Services\Project\ProjectService;

class ProjectController extends Controller
{
    public function index(ProjectService $projectService)
    {
        $projects = $projectService->fetchProjects();

        return response()->json([
            'message' => 'Projects fetched successfully',
            'data' => $projects
        ]);
    }
}
