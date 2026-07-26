<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LeaveLedgerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The leave bank statement's append-only rows: a grant, a deduction, or a correction. No
 * updated_at is managed — CREATED_AT is the only timestamp constant Eloquent knows about,
 * so a save() on an already-persisted row still never writes an updated_at column (the
 * migration doesn't even have one). A correction is a new compensating row, never an edit.
 */
final class LeaveLedger extends Model
{
    /** @use HasFactory<LeaveLedgerFactory> */
    use HasFactory, HasUuids;

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $table = 'leave_ledger';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'entry_type',
        'minutes',
        'reason',
        'source',
        'request_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
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

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /** @return BelongsTo<Request, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
