<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'bio',
        'birth_date',
        'gender',
        'website',
        'social_links'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'birth_date' => 'date',
        'social_links' => 'array',
    ];

    /**
     * Get the user that owns the profile.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user's gender in a readable format.
     *
     * @return string|null
     */
    public function getFormattedGenderAttribute()
    {
        return match ($this->gender) {
            'male' => 'Male',
            'female' => 'Female',
            'other' => 'Other',
            default => null,
        };
    }

    /**
     * Get the user's age if birth date is set.
     *
     * @return int|null
     */
    public function getAgeAttribute()
    {
        return $this->birth_date?->age;
    }

    /**
     * Get the social links as an array with proper URLs.
     *
     * @return array
     */
    public function getSocialLinksWithUrlsAttribute()
    {
        if (empty($this->social_links)) {
            return [];
        }

        $platforms = [
            'facebook' => 'https://facebook.com/',
            'twitter' => 'https://twitter.com/',
            'instagram' => 'https://instagram.com/',
            'linkedin' => 'https://linkedin.com/in/',
            'youtube' => 'https://youtube.com/',
            'tiktok' => 'https://tiktok.com/@',
        ];

        $links = [];
        foreach ($this->social_links as $platform => $username) {
            if (array_key_exists($platform, $platforms) && !empty($username)) {
                $links[$platform] = [
                    'username' => $username,
                    'url' => $platforms[$platform] . $username
                ];
            }
        }

        return $links;
    }
}
