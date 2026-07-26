<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'office_id',
        'name',
        'code',
        'is_paid',
        'requires_attachment',
        'deducts_balance',
        'is_cash_convertible',
        'max_carryover_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'requires_attachment' => 'boolean',
            'deducts_balance' => 'boolean',
            'is_cash_convertible' => 'boolean',
            'max_carryover_minutes' => 'integer',
            'is_active' => 'boolean',
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
            ->logOnly([
                'office_id',
                'name',
                'code',
                'is_paid',
                'requires_attachment',
                'deducts_balance',
                'is_cash_convertible',
                'max_carryover_minutes',
                'is_active',
            ])
            ->logOnlyDirty()
            ->useLogName('leave_type');
    }
}
