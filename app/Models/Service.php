<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Staudenmeir\BelongsToThrough\BelongsToThrough;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'category_id',
        'price',
        'duration_minutes',
        'location_type',
        'provider_id',
        'is_active',
        'max_concurrent_bookings',
        'slot_interval_minutes',
        'cancel_cutoff_hours',
        'edit_cutoff_hours',
        'cancel_late_policy',
        'edit_late_policy',
    ];

    protected $appends = ['isNew', 'title_translations', 'description_translations'];
    protected $dates = ['created_at'];
    protected $casts = [
        'title' => 'array',
        'description' => 'array',
        'cancel_cutoff_hours' => 'integer',
        'edit_cutoff_hours' => 'integer',
        'cancel_late_policy' => 'string',
        'edit_late_policy' => 'string',
    ];

    public function getIsNewAttribute()
    {
        if (!$this->created_at) {
            return false;
        }

        return $this->created_at->gt(now()->subDays(2));
    }

    public function getTitleAttribute($value)
    {
        $title = is_array($value) ? $value : $this->castAttribute('title', $value);

        if (!is_array($title)) {
            return null;
        }

        $locale = app()->getLocale();
        $fallbacks = array_unique([$locale, config('app.fallback_locale'), 'en', 'ar', 'de']);

        foreach ($fallbacks as $fallback) {
            if (!empty($title[$fallback])) {
                return $title[$fallback];
            }
        }

        return null;
    }

    public function getTitleTranslationsAttribute()
    {
        $title = $this->attributes['title'] ?? null;

        if (is_array($title)) {
            return $title;
        }

        return is_string($title) ? json_decode($title, true) : null;
    }

    public function getDescriptionTranslationsAttribute()
    {
        $description = $this->attributes['description'] ?? null;

        if (is_array($description)) {
            return $description;
        }

        return is_string($description) ? json_decode($description, true) : null;
    }

    public function user()
    {
        return $this->belongsToThrough(User::class, ServiceProvider::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function serviceProvider()
    {
        return $this->belongsTo(ServiceProvider::class, 'provider_id');
    }

    public function images()
    {
        return $this->hasMany(ServiceImage::class);
    }

    public function bookings()
    {
        return $this->hasMany(ServiceBooking::class);
    }
}
