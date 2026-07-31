<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeIdentification;
use App\Models\EmployeeIdentificationCategory;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    Storage::fake('attachments');

    $this->office = Office::factory()->create(['code' => 'CEB']);

    $this->selfUser = User::factory()->create();
    $this->employee = Employee::factory()->create([
        'user_id' => $this->selfUser->id,
        'current_office_id' => $this->office->id,
    ]);

    $this->hr = User::factory()->create();
    $this->hr->assignRole('HR Admin');
    $this->hr->hrAdminOffices()->attach($this->office->id);
    $this->hr = $this->hr->fresh();

    $this->tin = EmployeeIdentificationCategory::query()->create([
        'code' => 'TIN', 'name' => 'TIN', 'description' => 'BIR Taxpayer ID',
    ]);
});

it('creates an identification with a scan', function (): void {
    // UploadedFile::fake()->create() only fakes the REPORTED size/mime; the temp file it
    // backs is empty on disk. Medialibrary's FileAdder determines the mime type from the
    // real bytes via finfo, so an upload that must actually attach needs createWithContent()
    // with real PDF magic bytes instead. See SubmitAdjustmentTest.php:154 / RequestReadsTest.php:177.
    $scan = UploadedFile::fake()->createWithContent('tin.pdf', str_repeat('%PDF-1.4'.PHP_EOL, 20));

    $this->actingAs($this->hr)
        ->post("/api/v1/admin/employees/{$this->employee->id}/identifications", [
            'category_id' => $this->tin->id,
            'number' => '653536955000',
            'issued_on' => '2020-03-01',
            'scan' => $scan,
        ])
        ->assertOk()
        ->assertJsonPath('data.identifications.0.number', '653536955000')
        ->assertJsonPath('data.identifications.0.has_scan', true);

    expect(EmployeeIdentification::query()->count())->toBe(1);
});

it('upserts on category rather than creating a duplicate', function (): void {
    $payload = ['category_id' => $this->tin->id, 'number' => '111111111111'];

    $this->actingAs($this->hr)
        ->post("/api/v1/admin/employees/{$this->employee->id}/identifications", $payload)
        ->assertOk();

    $this->actingAs($this->hr)
        ->post("/api/v1/admin/employees/{$this->employee->id}/identifications",
            [...$payload, 'number' => '222222222222'])
        ->assertOk()
        ->assertJsonPath('data.identifications.0.number', '222222222222');

    expect(EmployeeIdentification::query()->count())->toBe(1);
});

it('keeps the existing scan when an upsert omits the file', function (): void {
    $scan = UploadedFile::fake()->createWithContent('tin.pdf', str_repeat('%PDF-1.4'.PHP_EOL, 20));

    $this->actingAs($this->hr)
        ->post("/api/v1/admin/employees/{$this->employee->id}/identifications", [
            'category_id' => $this->tin->id,
            'number' => '111111111111',
            'scan' => $scan,
        ])->assertOk();

    // Second write, number only — the scan must survive rather than being cleared.
    $this->actingAs($this->hr)
        ->post("/api/v1/admin/employees/{$this->employee->id}/identifications", [
            'category_id' => $this->tin->id,
            'number' => '222222222222',
        ])
        ->assertOk()
        ->assertJsonPath('data.identifications.0.has_scan', true);
});

it('rejects a scan that is not a pdf or image', function (): void {
    $this->actingAs($this->hr)
        ->post("/api/v1/admin/employees/{$this->employee->id}/identifications", [
            'category_id' => $this->tin->id,
            'number' => '111111111111',
            'scan' => UploadedFile::fake()->create('payload.exe', 20, 'application/x-msdownload'),
        ])
        ->assertStatus(400)
        ->assertJsonStructure(['error']);
});

it('deletes an identification', function (): void {
    $identification = EmployeeIdentification::factory()->create([
        'employee_id' => $this->employee->id,
        'category_id' => $this->tin->id,
    ]);

    $this->actingAs($this->hr)
        ->deleteJson("/api/v1/admin/employees/{$this->employee->id}/identifications/{$identification->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data.identifications');

    expect(EmployeeIdentification::query()->count())->toBe(0);
});

it('404s deleting an identification belonging to a different employee', function (): void {
    $other = Employee::factory()->create(['current_office_id' => $this->office->id]);
    $identification = EmployeeIdentification::factory()->create([
        'employee_id' => $other->id,
        'category_id' => $this->tin->id,
    ]);

    $this->actingAs($this->hr)
        ->deleteJson("/api/v1/admin/employees/{$this->employee->id}/identifications/{$identification->id}")
        ->assertNotFound();

    expect(EmployeeIdentification::query()->count())->toBe(1);
});

it('streams the scan to the employee themself', function (): void {
    $identification = EmployeeIdentification::factory()->create([
        'employee_id' => $this->employee->id,
        'category_id' => $this->tin->id,
    ]);
    $identification->addMedia(UploadedFile::fake()->createWithContent('tin.pdf', str_repeat('%PDF-1.4'.PHP_EOL, 20)))
        ->toMediaCollection('scan');

    $this->actingAs($this->selfUser->fresh())
        ->get("/api/v1/employees/{$this->employee->id}/identifications/{$identification->id}/scan")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('404s the scan stream when the identification belongs to a different employee', function (): void {
    // The attack: an ordinary employee is authorized against THEIR OWN employee id (which
    // self-grants viewFullProfile), then pairs it in the URL with a victim's identification
    // id instead of their own. Without the employee_id === $employee->id check in
    // DownloadScanController, this streams the victim's scan straight back.
    //
    // The victim's identification MUST carry a real scan here — otherwise the "no media"
    // branch 404s regardless of the ownership check, and this test would pass vacuously
    // even with the ownership check deleted.
    $victim = Employee::factory()->create(['current_office_id' => $this->office->id]);
    $victimIdentification = EmployeeIdentification::factory()->create([
        'employee_id' => $victim->id,
        'category_id' => $this->tin->id,
    ]);
    $victimIdentification->addMedia(UploadedFile::fake()->createWithContent('tin.pdf', str_repeat('%PDF-1.4'.PHP_EOL, 20)))
        ->toMediaCollection('scan');

    $this->actingAs($this->selfUser->fresh())
        ->get("/api/v1/employees/{$this->employee->id}/identifications/{$victimIdentification->id}/scan")
        ->assertNotFound();
});

it('404s the scan stream for an HR Admin of a different office', function (): void {
    $otherOffice = Office::factory()->create(['code' => 'MNL']);
    $otherHr = User::factory()->create();
    $otherHr->assignRole('HR Admin');
    $otherHr->hrAdminOffices()->attach($otherOffice->id);

    $identification = EmployeeIdentification::factory()->create([
        'employee_id' => $this->employee->id,
        'category_id' => $this->tin->id,
    ]);
    $identification->addMedia(UploadedFile::fake()->createWithContent('tin.pdf', str_repeat('%PDF-1.4'.PHP_EOL, 20)))
        ->toMediaCollection('scan');

    $this->actingAs($otherHr->fresh())
        ->get("/api/v1/employees/{$this->employee->id}/identifications/{$identification->id}/scan")
        ->assertNotFound();
});

it('404s the scan stream for a manager, who never sees identifications at all', function (): void {
    $managerUser = User::factory()->create();
    $manager = Employee::factory()->create([
        'user_id' => $managerUser->id,
        'current_office_id' => $this->office->id,
    ]);
    $this->employee->update(['current_reports_to_id' => $manager->id]);

    $identification = EmployeeIdentification::factory()->create([
        'employee_id' => $this->employee->id,
        'category_id' => $this->tin->id,
    ]);
    $identification->addMedia(UploadedFile::fake()->createWithContent('tin.pdf', str_repeat('%PDF-1.4'.PHP_EOL, 20)))
        ->toMediaCollection('scan');

    $this->actingAs($managerUser->fresh())
        ->get("/api/v1/employees/{$this->employee->id}/identifications/{$identification->id}/scan")
        ->assertNotFound();
});

it('404s the scan stream when the identification carries no scan', function (): void {
    $identification = EmployeeIdentification::factory()->create([
        'employee_id' => $this->employee->id,
        'category_id' => $this->tin->id,
    ]);

    $this->actingAs($this->selfUser->fresh())
        ->get("/api/v1/employees/{$this->employee->id}/identifications/{$identification->id}/scan")
        ->assertNotFound();
});

it('refuses an employee writing their own identifications', function (): void {
    $this->actingAs($this->selfUser->fresh())
        ->post("/api/v1/admin/employees/{$this->employee->id}/identifications", [
            'category_id' => $this->tin->id,
            'number' => '111111111111',
        ])
        ->assertNotFound();

    expect(EmployeeIdentification::query()->count())->toBe(0);
});
