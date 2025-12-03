<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'phone',
        'provider_id',
        'provider',
        'avatar',
        'two_factor_enabled',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'is_active',
        'last_seen_at'
    ];

    protected $casts = [
        'two_factor_enabled' => 'boolean',
        'two_factor_recovery_codes' => 'array',
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function carts()
    {
        return $this->hasMany(Cart::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function appearanceSetting()
    {
        return $this->hasOne(AppearanceSetting::class);
    }

    public function wishlists()
    {
        return $this->hasMany(Wishlist::class);
    }

    public function wishlistProducts()
    {
        return $this->belongsToMany(Product::class, 'wishlists');
    }

    public function shop()
    {
        return $this->hasOne(Shop::class, 'vendor_id');
    }

   public function sessions()
    {
        return DB::table('sessions')
            ->where('user_id', $this->id)
            ->orderBy('last_activity', 'desc')
            ->get()
            ->map(function ($session) {
                return (object) [
                    'id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'last_activity' => $session->last_activity,
                ];
            });
    }

    public function activityLogs()
    {
        return $this->hasMany(UserActivityLog::class);
    }

    public function lastActivity()
    {
        return $this->hasOne(UserActivityLog::class)->latestOfMany();
    }

    public function notificationSettings()
    {
        return $this->hasOne(UserNotificationSetting::class);
    }

    public function notifications(): MorphMany
    {
        // matches your Notification model's morph
        return $this->morphMany(Notification::class, 'notifiable');
    }

    /** Scope to admins (adjust to your schema) */
    public function scopeAdmins($query)
    {
        // if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'role')) {
        //     return $query->where('role', 'admin');
        // }

        // if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'is_admin')) {
        //     return $query->where('is_admin', true);
        // }

        // Check which column exists in your users table
        if (Schema::hasColumn('users', 'role')) {
            return $query->where('role', 'admin');
        } elseif (Schema::hasColumn('users', 'is_admin')) {
            return $query->where('is_admin', true);
        } elseif (Schema::hasColumn('users', 'role_id')) {
            // If you have a role_id column, check for admin role ID
            return $query->where('role_id', 1); // Adjust to your admin role ID
        }

        return $query->whereRaw('1 = 0');
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'participants')
            ->withPivot('last_read')
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isDelivery(): bool
    {
        return $this->role_id === 5; // Assuming delivery role ID is 3
    }

    public function isCustomer(): bool
    {
        return $this->role_id === 2; // Customer role ID is 2
    }

    public function isVendor(): bool
    {
        return $this->role_id === 4; // Vendor role ID is 4
    }

    public function isAdmin(): bool
    {
        return $this->role_id === 1; // Assuming admin role ID is 1
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class, 'delivery_agent_id');
    }

    public function deliveryPreferences()
    {
        return $this->hasOne(DeliveryPreferences::class);
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
            'is_active' => true,
        ])->save();
    }

    public function sendEmailVerificationNotification()
    {

        $this->notify(new \App\Notifications\VerifyEmail);
    }

    public function getEmailForVerification()
    {
        return $this->email;
    }

    public function hasVerifiedEmail()
    {
        return !is_null($this->email_verified_at);
    }

    // New here
    public function hasPermission($permission): bool
    {
        if ($this->isAdmin()) {
            return true; // Admin has all permissions
        }

        return $this->role->permissions()->where('name', $permission)->exists();
    }

    public function hasAnyPermission($permissions): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->role->permissions()->whereIn('name', (array)$permissions)->exists();
    }

    public function hasAllPermissions($permissions): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->role->permissions()->whereIn('name', (array)$permissions)->count() === count((array)$permissions);
    }

    // Update Last Seen
    public function updateLastSeen()
    {
        $this->last_seen_at = now();
        $this->save();

        // Broadcast presence update
        broadcast(new \App\Events\UserPresenceUpdated($this->id, true))->toOthers();
    }

    // Mark as offline
    public function markAsOffline()
    {
        // We don't set last_seen_at to null, we keep the last seen time
        // Broadcast offline status
        broadcast(new \App\Events\UserPresenceUpdated($this->id, false))->toOthers();
    }

    // Is online
    public function isOnline()
    {
        if (!$this->last_seen_at) {
            return false;
        }

        // Consider user online if they were active in the last 1 minutes
        return $this->last_seen_at->greaterThan(now()->subMinutes(1));
    }

    // Get last Seen for human
    public function getLastSeenForHumans()
    {
        if (!$this->last_seen_at) {
            return 'Never';
        }

        if ($this->isOnline()) {
            return 'Online';
        }

        return $this->last_seen_at->diffForHumans();
    }

    // public function markEmailAsVerified(): bool
    // {
    //     return $this->forceFill([
    //         'email_verified_at' => $this->freshTimestamp(),
    //         'is_active' => true,
    //     ])->save();
    // }

    // public function sendEmailVerificationNotification()
    // {
    //     $this->notify(new \App\Notifications\VerifyEmail);
    // }

    // public function getEmailForVerification()
    // {
    //     return $this->email;
    // }

    // public function hasVerifiedEmail()
    // {
    //     return !is_null($this->email_verified_at);
    // }
}
