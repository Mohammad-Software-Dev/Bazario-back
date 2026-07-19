<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Staudenmeir\BelongsToThrough\BelongsToThrough;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'category_id',
        'price',
        'seller_id',
    ];

    protected $casts = [
        'name' => 'array',
        'description' => 'array',
    ];

    protected $appends = ['isNew', 'name_translations', 'description_translations'];
    protected $dates = ['created_at'];

    public function getIsNewAttribute()
    {
        if (!$this->created_at) {
            return false;
        }

        return $this->created_at->gt(now()->subDays(2));
    }

    public function getNameTranslationsAttribute()
    {
        $value = $this->attributes['name'] ?? null;

        return $value === null ? null : $this->castAttribute('name', $value);
    }

    public function getDescriptionTranslationsAttribute()
    {
        $value = $this->attributes['description'] ?? null;

        return $value === null ? null : $this->castAttribute('description', $value);
    }

    public function getNameAttribute($value)
    {
        $name = is_array($value) ? $value : $this->castAttribute('name', $value);

        if (!is_array($name)) {
            return null;
        }

        $locale = app()->getLocale();
        $fallbacks = array_unique([$locale, config('app.fallback_locale'), 'en', 'ar', 'de']);

        foreach ($fallbacks as $fallback) {
            if (!empty($name[$fallback])) {
                return $name[$fallback];
            }
        }

        return null;
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function seller()
    {
        return $this->belongsTo(Seller::class, 'seller_id');
    }

    public function user()
    {
        return $this->belongsToThrough(User::class, Seller::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }
}
