<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'type'];

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'participants')
            ->withPivot('last_read')
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function deliveryUser()
    {
        return $this->participants()->whereHas('role', function ($query) {
            $query->where('name', 'delivery');
        })->first();
    }

    public function customerUsers()
    {
        return $this->participants()->whereHas('role', function ($query) {
            $query->where('name', 'customer');
        })->get();
    }
}