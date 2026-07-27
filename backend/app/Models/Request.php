<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class Request extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\RequestFactory> */
    use HasFactory, HasUuids, InteractsWithMedia;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => RequestType::class,
            'state' => RequestState::class,
            'decided_at' => 'datetime',
            'manager_decided_at' => 'datetime',
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

    public function isPending(): bool
    {
        return $this->state === RequestState::Pending;
    }

    /** Approved/Rejected/Cancelled: nothing left to decide. A `manager_approved` request
     *  is NOT terminal — it is still actionable, at hop 2. */
    public function isTerminal(): bool
    {
        return in_array($this->state, [
            RequestState::Approved,
            RequestState::Rejected,
            RequestState::Cancelled,
        ], true);
    }

    public function registerMediaCollections(): void
    {
        // Optional single attachment (a photo/PDF backing the correction), on the private
        // RustFS-backed disk. singleFile() means a re-upload replaces rather than appends.
        $this->addMediaCollection('attachment')
            ->singleFile()
            ->useDisk('attachments')
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return HasOne<AttendanceAdjustmentDetail, $this> */
    public function attendanceAdjustmentDetail(): HasOne
    {
        return $this->hasOne(AttendanceAdjustmentDetail::class);
    }

    /** @return HasOne<LeaveDetail, $this> */
    public function leaveDetail(): HasOne
    {
        return $this->hasOne(LeaveDetail::class, 'request_id');
    }

    /** @return HasOne<OvertimeDetail, $this> */
    public function overtimeDetail(): HasOne
    {
        return $this->hasOne(OvertimeDetail::class, 'request_id');
    }
}
