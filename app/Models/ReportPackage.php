<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A management report package (QBO "Management Reports"): a named, configurable
 * bundle of reports rendered for one period into a single professional PDF with
 * a cover page, table of contents, and optional preliminary/end-notes pages.
 * Per user + company.
 */
#[Fillable([
    'company_id',
    'user_id',
    'name',
    'title',
    'subtitle',
    'period_preset',
    'comparison_basis',
    'show_cover',
    'show_logo',
    'show_toc',
    'preliminary_text',
    'end_notes',
])]
class ReportPackage extends Model
{
    use BelongsToCompany;

    /**
     * Mirror the column defaults so a freshly created (un-refreshed) model
     * behaves the same as a fetched one.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'period_preset' => 'last_month',
        'comparison_basis' => 'off',
        'show_cover' => true,
        'show_logo' => true,
        'show_toc' => true,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ReportPackageItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReportPackageItem::class)->orderBy('sort_order');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_cover' => 'boolean',
            'show_logo' => 'boolean',
            'show_toc' => 'boolean',
        ];
    }
}
