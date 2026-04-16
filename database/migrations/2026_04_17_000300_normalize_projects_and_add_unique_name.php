<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/^\s*,+\s*/', '', $value);
        $value = preg_replace('/\s+/', ' ', trim((string) $value));

        return $value === '' ? null : $value;
    }

    private function uniqueValue(string $base, array &$used, int $limit = 255): string
    {
        $base = trim($base);
        if ($base === '') {
            $base = 'Project';
        }

        $candidate = Str::limit($base, $limit, '');
        $counter = 2;

        while (isset($used[strtolower($candidate)])) {
            $suffix = " ({$counter})";
            $candidate = Str::limit($base, $limit - strlen($suffix), '') . $suffix;
            $counter++;
        }

        $used[strtolower($candidate)] = true;

        return $candidate;
    }

    private function uniqueSlug(string $base, array &$used): string
    {
        $base = Str::slug($base);
        if ($base === '') {
            $base = 'project';
        }

        $candidate = Str::limit($base, 191, '');
        $counter = 2;

        while (isset($used[strtolower($candidate)])) {
            $suffix = '-' . $counter;
            $candidate = Str::limit($base, 191 - strlen($suffix), '') . $suffix;
            $counter++;
        }

        $used[strtolower($candidate)] = true;

        return $candidate;
    }

    public function up(): void
    {
        $userEmails = DB::table('users')->pluck('email', 'id');
        $projects = DB::table('projects')
            ->select('id', 'name', 'slug', 'complete_address', 'mapaddress', 'added_by', 'created_by')
            ->orderBy('id')
            ->get();

        $usedNames = [];
        $usedSlugs = [];

        foreach ($projects as $project) {
            $name = $this->normalizeText($project->name) ?? "Project {$project->id}";
            $uniqueName = $this->uniqueValue($name, $usedNames);

            $slugSeed = $this->normalizeText($project->slug) ?? $uniqueName;
            $uniqueSlug = $this->uniqueSlug($slugSeed, $usedSlugs);

            $completeAddress = $this->normalizeText($project->complete_address);
            $mapaddress = $this->normalizeText($project->mapaddress);
            $resolvedAddress = $completeAddress ?: $mapaddress ?: $uniqueName;
            $addedBy = $project->created_by ? ($userEmails[$project->created_by] ?? $project->added_by) : $project->added_by;

            DB::table('projects')
                ->where('id', $project->id)
                ->update([
                    'name' => $uniqueName,
                    'slug' => $uniqueSlug,
                    'complete_address' => $resolvedAddress,
                    'mapaddress' => $mapaddress ?: $resolvedAddress,
                    'added_by' => $addedBy ?: 'system@local',
                ]);
        }

        Schema::table('projects', function (Blueprint $table) {
            try {
                $table->unique('name', 'projects_name_unique');
            } catch (\Throwable $e) {
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            try {
                $table->dropUnique('projects_name_unique');
            } catch (\Throwable $e) {
            }
        });
    }
};
