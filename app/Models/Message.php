<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Message extends Model
{
    use HasFactory;

    protected $fillable = ['conversation_id', 'user_id', 'body', 'type', 'read_at', 'attachment', 'duration', 'call_type', 'call_status', 'call_id'];

    protected $casts = [
        'read_at' => 'datetime',
        'duration' => 'integer',
        'call_id' => 'string',
        'call_type' => 'string',
        'call_status' => 'string',
    ];

    protected $appends = [
        'attachment_url',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getAttachmentUrlAttribute()
    {
        return $this->attachment ? Storage::url($this->attachment) : null;
    }
}
