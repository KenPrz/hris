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

    protected $guarded = [];

    protected function casts(): array
    {
        return ['ip_allowlist' => 'array'];
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
