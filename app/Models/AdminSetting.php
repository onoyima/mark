<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AdminSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
        'category',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get a setting value by key with caching
     */
    public static function get(string $key, $default = null)
    {
        return Cache::remember("admin_setting_{$key}", 3600, function () use ($key, $default) {
            $setting = self::where('key', $key)->where('is_active', true)->first();
            
            if (!$setting) {
                return $default;
            }
            
            return self::castValue($setting->value, $setting->type);
        });
    }

    /**
     * Set a setting value by key
     */
    public static function set(string $key, $value, string $type = 'string', string $description = null, string $category = 'general')
    {
        $setting = self::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'type' => $type,
                'description' => $description,
                'category' => $category,
                'is_active' => true
            ]
        );

        // Clear cache
        Cache::forget("admin_setting_{$key}");
        
        return $setting;
    }

    /**
     * Get all settings by category
     */
    public static function getByCategory(string $category)
    {
        return Cache::remember("admin_settings_category_{$category}", 3600, function () use ($category) {
            return self::where('category', $category)
                      ->where('is_active', true)
                      ->get()
                      ->mapWithKeys(function ($setting) {
                          return [$setting->key => self::castValue($setting->value, $setting->type)];
                      });
        });
    }

    /**
     * Cast value to appropriate type
     */
    private static function castValue($value, string $type)
    {
        switch ($type) {
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'number':
                return is_numeric($value) ? (float) $value : $value;
            case 'json':
                return json_decode($value, true);
            case 'date':
                return $value;
            default:
                return $value;
        }
    }

    /**
     * Clear all settings cache
     */
    public static function clearCache()
    {
        $keys = self::pluck('key');
        foreach ($keys as $key) {
            Cache::forget("admin_setting_{$key}");
        }
        
        $categories = self::distinct('category')->pluck('category');
        foreach ($categories as $category) {
            Cache::forget("admin_settings_category_{$category}");
        }
    }

    /**
     * Boot method to clear cache on model events
     */
    protected static function boot()
    {
        parent::boot();

        static::saved(function ($model) {
            Cache::forget("admin_setting_{$model->key}");
            Cache::forget("admin_settings_category_{$model->category}");
        });

        static::deleted(function ($model) {
            Cache::forget("admin_setting_{$model->key}");
            Cache::forget("admin_settings_category_{$model->category}");
        });
    }
}