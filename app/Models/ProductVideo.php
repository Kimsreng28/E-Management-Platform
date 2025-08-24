<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVideo extends Model
{
    use SoftDeletes;

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
