<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DeliveryPreferences extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'delivery_type',
        'preferred_delivery_time',
        'delivery_instructions',
        'leave_at_door',
        'signature_required'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
