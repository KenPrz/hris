<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pay\DayType;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'day_type' => DayType::class,
            'date' => 'date',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['id'];
    }

    /** @return BelongsTo<Office, $this> */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['office_id', 'date', 'day_type', 'name'])
            ->logOnlyDirty()
            ->useLogName('holiday');
    }
}
