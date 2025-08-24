<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AppearanceSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'language',
        'dark_mode',
        'currency',
        'timezone'
    ];

    protected $casts = [
        'dark_mode' => 'boolean'
    ];
}