<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Cutoff\CutoffState;
use Database\Factories\CutoffPeriodFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class CutoffPeriod extends Model
{
    /** @use HasFactory<CutoffPeriodFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'office_id', 'start_date', 'end_date', 'state', 'closed_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'state' => CutoffState::class,
            'closed_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['office_id', 'start_date', 'end_date', 'state', 'closed_by', 'closed_at'])
            ->logOnlyDirty()
            ->useLogName('cutoff_period');
    }
}
