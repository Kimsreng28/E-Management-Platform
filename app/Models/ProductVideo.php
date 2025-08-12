<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVideo extends Model
{
    protected $fillable = [
        'product_id',
        'url',
        'thumbnail',
        'title',
        'duration'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
