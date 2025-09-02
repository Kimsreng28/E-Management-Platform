<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FAQ extends Model
{
    use SoftDeletes;

    protected $table = 'faqs';

    protected $fillable = [
        'question_en',
        'question_kh',
        'answer_en',
        'answer_kh',
        'category_id',
        'order',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    public function category()
    {
        return $this->belongsTo(FAQCategory::class, 'category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('created_at');
    }

    public function getQuestionAttribute()
    {
        $locale = app()->getLocale();
        return $this->attributes["question_{$locale}"] ?? $this->attributes['question_en'];
    }

    public function getAnswerAttribute()
    {
        $locale = app()->getLocale();
        return $this->attributes["answer_{$locale}"] ?? $this->attributes['answer_en'];
    }
}
