<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One AI-assistant touch on a public page: a live fetch for a user (`view`),
 * a search-crawler index (`crawl`) or a person arriving from an assistant
 * (`click`). Insert-only, so `updated_at` is disabled.
 */
class AiEvent extends Model
{
    public const UPDATED_AT = null;

    public const KINDS = ['view', 'crawl', 'click'];

    protected $fillable = ['kind', 'source', 'agent', 'path'];
}
