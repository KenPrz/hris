<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Compute\RecomputeTrigger;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class RecomputeRun extends Model
{
    use HasUuids, LogsActivity;

    protected $fillable = [
        'trigger_type', 'trigger_id', 'reason', 'pair_count', 'batch_id', 'status', 'caused_by',
    ];

    protected function casts(): array
    {
        return [
            'trigger_type' => RecomputeTrigger::class,
            'pair_count' => 'integer',
        ];
    }

    public function newUniqueId(): string { return Str::uuid7()->toString(); }

    /** @return array<int,string> */
    public function uniqueIds(): array { return ['id']; }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['trigger_type', 'trigger_id', 'reason', 'pair_count', 'status'])
            ->logOnlyDirty()
            ->useLogName('recompute_run');
    }
}
