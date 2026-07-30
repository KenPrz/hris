<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmployeeIdentificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class EmployeeIdentification extends Model implements HasMedia
{
    /** @use HasFactory<EmployeeIdentificationFactory> */
    use HasFactory, HasUuids, InteractsWithMedia, LogsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
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

    /** @return BelongsTo<EmployeeIdentificationCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EmployeeIdentificationCategory::class, 'category_id');
    }

    public function registerMediaCollections(): void
    {
        // The scanned copy, on the private RustFS-backed disk — same collection shape and
        // limits as Request's 'attachment'. singleFile() so re-uploading replaces rather
        // than accumulating: an identification has one current scan, not a pile.
        $this->addMediaCollection('scan')
            ->singleFile()
            ->useDisk('attachments')
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);
    }

    /**
     * `number` is DELIBERATELY absent from logOnly(). Logging it would copy every TIN, SSS
     * number, and bank account into activity_log — a table with different read rules and a
     * longer retention than anyone reasoned about. The log records THAT the identification
     * changed, never to what. See the M10a spec, decision 6.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'category_id', 'issued_on', 'expires_on'])
            ->useLogName('employee_profile')
            ->logOnlyDirty();
    }
}
