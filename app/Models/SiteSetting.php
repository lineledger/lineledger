<?php

namespace App\Models;

use App\Support\SiteSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * A single platform-wide setting (one row per key). Cross-tenant — not scoped
 * to any company. Read and written through the cached {@see SiteSettings}
 * accessor rather than directly, so the cache stays coherent.
 *
 * @property string $key
 * @property mixed $value
 */
class SiteSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
