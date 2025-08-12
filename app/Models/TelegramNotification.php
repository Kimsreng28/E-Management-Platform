<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramNotification extends Model
{
    protected $fillable = [
        'order_id',
        'chat_id',
        'message',
        'is_sent',
        'sent_at'
    ];

    protected $casts = [
        'is_sent' => 'boolean',
        'sent_at' => 'datetime'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}