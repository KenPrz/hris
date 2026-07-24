<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class PayRule extends Model
{
    use HasUuids, LogsActivity;

    protected $fillable = [
        'effective_from', 'overtime_ordinary_bp', 'overtime_premium_bp', 'night_diff_bp',
        'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'overtime_ordinary_bp' => 'integer',
            'overtime_premium_bp' => 'integer',
            'night_diff_bp' => 'integer',
        ];
    }

    public function newUniqueId(): string { return Str::uuid7()->toString(); }

    /** @return array<int,string> */
    public function uniqueIds(): array { return ['id']; }

    /** @return HasMany<PayRuleDayRate> */
    public function dayRates(): HasMany { return $this->hasMany(PayRuleDayRate::class); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['effective_from', 'overtime_ordinary_bp', 'overtime_premium_bp', 'night_diff_bp', 'note'])
            ->logOnlyDirty()
            ->useLogName('pay_rule');
    }
}
