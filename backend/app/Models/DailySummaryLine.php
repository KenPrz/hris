<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pay\SummaryLineKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class DailySummaryLine extends Model
{
    use HasUuids;

    protected $fillable = ['summary_id', 'kind', 'minutes', 'applied_bp'];

    protected function casts(): array
    {
        return [
            'kind' => SummaryLineKind::class,
            'minutes' => 'integer',
            'applied_bp' => 'integer',
        ];
    }

    public function newUniqueId(): string { return Str::uuid7()->toString(); }

    /** @return array<int,string> */
    public function uniqueIds(): array { return ['id']; }

    /** @return BelongsTo<DailyAttendanceSummary, DailySummaryLine> */
    public function summary(): BelongsTo { return $this->belongsTo(DailyAttendanceSummary::class, 'summary_id'); }
}
