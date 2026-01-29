<?php

namespace App\Services\Project;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class ProjectService
{
    protected string $apiUrl = 'https://api.leuteriorealty.com/fh/v2/public/api/get-projects';

    public function fetchProjects(): array
    {
        return Cache::remember('projects_api', 600, function () {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => 'https://api.leuteriorealty.com/',
            ])
            ->withoutVerifying() // optional
            ->get($this->apiUrl);

            if ($response->failed()) {
                return [
                    'error' => 'Failed to fetch projects from external API',
                    'status' => $response->status()
                ];
            }

            return $response->json();
        });
    }
}
