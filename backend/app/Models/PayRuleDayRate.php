<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pay\DayType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class PayRuleDayRate extends Model
{
    use HasUuids;

    protected $fillable = ['pay_rule_id', 'day_type', 'worked_bp', 'worked_rest_bp', 'unworked_bp'];

    protected function casts(): array
    {
        return [
            'day_type' => DayType::class,
            'worked_bp' => 'integer',
            'worked_rest_bp' => 'integer',
            'unworked_bp' => 'integer',
        ];
    }

    public function newUniqueId(): string { return Str::uuid7()->toString(); }

    /** @return array<int,string> */
    public function uniqueIds(): array { return ['id']; }

    /** @return BelongsTo<PayRule, PayRuleDayRate> */
    public function payRule(): BelongsTo { return $this->belongsTo(PayRule::class); }
}
