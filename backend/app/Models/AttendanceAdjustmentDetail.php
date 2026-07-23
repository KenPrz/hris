<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Attendance\AdjustmentOperation;
use App\Domain\Attendance\PunchDirection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AttendanceAdjustmentDetail extends Model
{
    /** @use HasFactory<\Database\Factories\AttendanceAdjustmentDetailFactory> */
    use HasFactory;

    // A true 1:1 with requests: the primary key IS the request's id, not a generated one.
    protected $primaryKey = 'request_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'operation' => AdjustmentOperation::class,
            'direction' => PunchDirection::class,
            'punched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Request, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    /** @return BelongsTo<AttendanceLog, $this> */
    public function targetLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class, 'target_log_id');
    }
}
