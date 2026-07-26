<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OfficeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class Office extends Model
{
    /** @use HasFactory<OfficeFactory> */
    use HasFactory, HasUuids;

    // Fully unguarded, not $fillable: every write comes from a vetted Action, so there is
    // no untrusted mass-assignment surface to fence off. Adding a non-empty $fillable here
    // would flip Eloquent's semantics for the whole model (isFillable() only falls back to
    // "everything but $guarded" when $fillable is empty) and silently block every OTHER
    // column — including CompanySeeder's Office::create() and
    // SetDefaultTemplateController's default_shift_template_id update — so
    // minutes_per_leave_day relies on $guarded staying empty, not a $fillable entry.
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ip_allowlist' => 'array',
            'minutes_per_leave_day' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Department, $this> */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /** @return HasMany<Holiday, $this> */
    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    /** @return HasMany<ShiftTemplate, $this> */
    public function shiftTemplates(): HasMany
    {
        return $this->hasMany(ShiftTemplate::class);
    }

    /** @return BelongsTo<ShiftTemplate, $this> */
    public function defaultShiftTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'default_shift_template_id');
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
}
