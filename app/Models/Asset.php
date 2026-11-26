<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Asset extends Model
{
    protected $fillable = ['asset_tag', 'name', 'category', 'serial_number', 'status', 'image_path'];

    /** The storage path stays private; clients read has_image and GET /assets/{id}/image. */
    protected $hidden = ['image_path'];

    protected $appends = ['has_image'];

    public function getHasImageAttribute(): bool
    {
        return ! empty($this->attributes['image_path'] ?? null);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(AssetAssignment::class)->whereNull('returned_at')->latestOfMany('assigned_at');
    }
}
