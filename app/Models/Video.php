<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    protected $fillable = [
        'title',
        'description',
        'thumbnail_url',
        'trailer_url',
        'full_video_url',
    ];
}
