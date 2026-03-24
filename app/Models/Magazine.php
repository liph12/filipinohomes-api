<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Magazine extends Model
{
    protected $table = 'magazines';

    /**
     * Mass assignable fields
     */
    protected $fillable = [
        'title',
        'description',
        'cover_photo',
        'pdf_file',
        'publish_date',
    ];

    /**
     * Casts (VERY IMPORTANT for JSON fields)
     */
    protected $casts = [
        'cover_photo' => 'array',
        'pdf_file' => 'array',
        'publish_date' => 'date',
    ];
}