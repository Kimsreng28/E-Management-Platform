<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserNotificationSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'email',
        'push',
        'telegram',
        'sms',
        'telegram_chat_id',
        'phone_number',
    ];

    protected $casts = [
        'email' => 'boolean',
        'push' => 'boolean',
        'telegram' => 'boolean',
        'sms' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
