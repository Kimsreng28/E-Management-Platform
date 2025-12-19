<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewMedia extends Model
{
    protected $fillable = [
        'review_id',
        'path',
        'type',
        'mime_type',
        'size',
        'order',
        'thumbnail'
    ];

    protected $appends = ['full_url', 'thumbnail_url'];

    public function review()
    {
        return $this->belongsTo(Review::class);
    }

    public function getFullUrlAttribute()
    {
        return $this->path ? asset('storage/' . $this->path) : null;
    }

    public function getThumbnailUrlAttribute()
    {
        return $this->thumbnail ? asset('storage/' . $this->thumbnail) : null;
    }
}