<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DeliveryTracking extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_id',
        'status',
        'notes',
        'lat',
        'lng'
    ];

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }
}
