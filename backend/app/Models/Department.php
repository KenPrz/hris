<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Office, $this> */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function newUniqueId(): string
    {
        // uuidv7 everywhere, model-path included — time-ordered keys keep the btree happy.
        return (string) Str::uuid7();
    }

    public function uniqueIds(): array
    {
        return ['id'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['office_id', 'name', 'code', 'archived_at'])
            ->useLogName('department')
            ->logOnlyDirty();
    }
}
