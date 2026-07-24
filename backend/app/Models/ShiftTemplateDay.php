<?php
declare(strict_types=1);

namespace App\Models;

use App\Domain\Schedule\Weekday;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class ShiftTemplateDay extends Model
{
    use HasUuids;

    protected $fillable = ['shift_template_id', 'weekday', 'is_rest', 'start_minute', 'end_minute', 'break_minutes'];

    protected function casts(): array
    {
        return ['weekday' => Weekday::class, 'is_rest' => 'boolean',
            'start_minute' => 'integer', 'end_minute' => 'integer', 'break_minutes' => 'integer'];
    }

    public function newUniqueId(): string { return Str::uuid7()->toString(); }

    /** @return array<int,string> */
    public function uniqueIds(): array { return ['id']; }

    /** @return BelongsTo<ShiftTemplate, ShiftTemplateDay> */
    public function template(): BelongsTo { return $this->belongsTo(ShiftTemplate::class, 'shift_template_id'); }
}
