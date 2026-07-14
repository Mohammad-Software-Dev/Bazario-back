<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use SoftDeletes;
    use HasFactory;

    protected $fillable = [
        'name',
        'image',
        'parent_id',
        'slug',
        'type',
        'description',
    ];

    protected $casts = [
        'name' => 'array',
    ];

    public function getNameAttribute($value)
    {
        $name = is_array($value) ? $value : json_decode($value ?? 'null', true);

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

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}
