<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FAQCategory extends Model
{
    use SoftDeletes;

    protected $table = 'faq_categories';

    protected $fillable = [
        'name_en',
        'name_kh',
        'order',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    public function faqs()
    {
        // explicitly define the foreign key
        return $this->hasMany(FAQ::class, 'category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('created_at');
    }

    public function getNameAttribute()
    {
        $locale = app()->getLocale();
        return $this->attributes["name_{$locale}"] ?? $this->attributes['name_en'];
    }
}