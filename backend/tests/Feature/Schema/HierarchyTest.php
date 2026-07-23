<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Office;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds an organization with offices and departments', function (): void {
    $org = Organization::factory()->create(['name' => 'Delsan Inc.']);
    $office = Office::factory()->for($org)->create(['code' => 'MNL']);
    $dept = Department::factory()->for($office)->create(['code' => 'ENG']);

    expect($org->id)->toBeString()                       // uuidv7, not an int
        ->and($office->organization_id)->toBe($org->id)
        ->and($dept->office_id)->toBe($office->id)
        ->and($org->offices)->toHaveCount(1)
        ->and($office->departments)->toHaveCount(1)
        ->and($office->organization->is($org))->toBeTrue();
});

it('assigns a uuidv7 primary key from the database default', function (): void {
    $org = Organization::factory()->create();

    // uuidv7: version nibble is 7. Proves the DB default fired, not a client-side uuid.
    expect($org->id[14])->toBe('7');
});

it('requires an office code to be unique within the schema', function (): void {
    Office::factory()->create(['code' => 'MNL']);

    expect(fn () => Office::factory()->create(['code' => 'MNL']))
        ->toThrow(QueryException::class);
});
