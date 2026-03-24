<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\PageBuilder;

class PageBuilderSeeder extends Seeder
{
    public function run(): void
    {
        DB::disableQueryLog();

        DB::table('pagebuilder')->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $p) {
                if (!DB::table('agents')->where('id', $p->agent_id)->exists()) {
                        continue;
                    }
                $galleryUpdated = [];
                foreach (json_decode($p->images ?? '[]') ?? [] as $img) {
                    if (empty($img)) continue;
                    $galleryUpdated[] = str_contains($img, 'https://')
                        ? $img
                        : 'https://s3-ap-southeast-1.amazonaws.com/filipinohomes/' . $img;
                }

                $bannerPhoto = !empty($p->banner)
                    ? [str_contains($p->banner, 'https://')
                        ? $p->banner
                        : 'https://s3-ap-southeast-1.amazonaws.com/filipinohomes/' . $p->banner]
                    : [];

                PageBuilder::create([
                    'title'       => $p->title,
                    'seo_tags'    => $p->seo_tags ?? null,
                    'description' => $p->description,
                    'banner'      => $bannerPhoto,
                    'gallery'     => $galleryUpdated,
                    'video_url'   => $p->video_url ?? null,
                    'agent_id'    => $p->agent_id,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        });
    }
}