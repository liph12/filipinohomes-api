<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An admin said a listing is not in the project its title names; see ProjectTagAudit. */
class ProjectTagDismissal extends Model
{
    protected $fillable = ['listing_id', 'project_id', 'user_id'];
}
