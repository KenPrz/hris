<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class ShiftTemplate extends Model
{
    use HasUuids, LogsActivity;

    protected $fillable = ['office_id', 'name'];

    public function newUniqueId(): string { return Str::uuid7()->toString(); }

    /** @return array<int,string> */
    public function uniqueIds(): array { return ['id']; }

    /** @return BelongsTo<Office, $this> */
    public function office(): BelongsTo { return $this->belongsTo(Office::class); }

    /** @return HasMany<ShiftTemplateDay> */
    public function days(): HasMany { return $this->hasMany(ShiftTemplateDay::class); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['office_id', 'name'])->logOnlyDirty()->useLogName('shift_template');
    }
}
