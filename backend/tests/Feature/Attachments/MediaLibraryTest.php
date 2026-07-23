<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// The Request model that will actually be HasMedia lands in Task 2. Office is not
// HasMedia and the brief asks us not to add the trait just to exercise this test, so
// this proves the critical cascade gotcha at the migration level instead: insert a
// media row with a full uuid model_id via DB::table and read it back unchanged. Before
// the uuidMorphs() fix, model_id is an unsignedBigInteger and Postgres rejects a
// 36-character uuid string outright with a QueryException — that is the RED state.
it('stores a full uuid in media.model_id without truncation', function (): void {
    $modelId = (string) Str::uuid7();

    DB::table('media')->insert([
        'model_type' => 'App\\Models\\Office',
        'model_id' => $modelId,
        'collection_name' => 'attachment',
        'name' => 'proof',
        'file_name' => 'proof.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'attachments',
        'size' => 100,
        'manipulations' => '[]',
        'custom_properties' => '[]',
        'generated_conversions' => '[]',
        'responsive_images' => '[]',
    ]);

    $stored = DB::table('media')->where('model_type', 'App\\Models\\Office')->first();

    expect($stored)->not->toBeNull()
        ->and($stored->model_id)->toBe($modelId)
        ->and($stored->model_id)->toBeString()
        ->and(mb_strlen((string) $stored->model_id))->toBe(36);
});

it('configures the attachments disk on the s3 driver, faked in tests', function (): void {
    Storage::fake('attachments');

    expect(config('filesystems.disks.attachments.driver'))->toBe('s3')
        ->and(config('filesystems.disks.attachments.bucket'))->toBe('hris-attachments')
        ->and(config('media-library.disk_name'))->toBe('attachments');

    Storage::disk('attachments')->put('proof.pdf', 'contents');

    Storage::disk('attachments')->assertExists('proof.pdf');
});
