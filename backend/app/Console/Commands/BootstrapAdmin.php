<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\ProfileCatalogSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The first login on a fresh production database.
 *
 * `DatabaseSeeder` cannot serve this purpose: it calls `RbacSeeder` (the permission
 * catalog and the 'HR Admin' role — real configuration, required in production, and
 * `SetHrAdminOffices` throws without it) and then `CompanySeeder` (a Manila/Cebu demo
 * company that must never touch production). They cannot run together, and running
 * neither leaves M8's done-when unreachable: you cannot configure a company from an
 * empty database entirely through the UI if you cannot sign in to start.
 *
 * So this command runs the RBAC half and mints exactly one System Admin. It creates a
 * `users` row and nothing else — a System Admin needs no employee record (`SessionResource`
 * renders `employee: null`, and the seeded sysadmin has none), which is what avoids the
 * chicken-and-egg: an employee needs an organization, and creating that organization is
 * the first thing this admin is going to do.
 *
 * See docs/superpowers/specs/2026-07-29-m9-containerization-production-design.md.
 */
final class BootstrapAdmin extends Command
{
    protected $signature = 'hris:bootstrap-admin {email : The sign-in email for the first System Admin}
                            {--name= : Display name (defaults to "System Administrator")}';

    protected $description = 'Seed the RBAC catalog and create the first System Admin on an empty database';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email:rfc']]);

        if ($validator->fails()) {
            $this->error("Not a valid email address: {$email}");

            return self::FAILURE;
        }

        // Refuse rather than upsert. A command that quietly mints a second superuser — or
        // resets the existing one's password — is a privilege-escalation path wearing a
        // helpful face. Recovering a lost admin password is a deliberate, separate act.
        if (User::query()->where('is_system_admin', true)->exists()) {
            $this->error('A System Admin already exists — refusing to create a second one.');
            $this->line('This command bootstraps an empty database. Manage further admins through the app.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error("A user with the email {$email} already exists.");

            return self::FAILURE;
        }

        // Idempotent (findOrCreate throughout), so running this on a database that already
        // has the catalog is a no-op rather than an error.
        $this->callSilent('db:seed', ['--class' => RbacSeeder::class, '--force' => true]);

        // The profile catalog is production configuration too, exactly like the permission
        // catalog above — an HR Admin cannot record a TIN against a category that does not
        // exist, and no UI creates categories. Idempotent, so re-running is safe.
        $this->callSilent('db:seed', ['--class' => ProfileCatalogSeeder::class, '--force' => true]);

        $password = Str::password(24);

        $name = trim((string) ($this->option('name') ?? ''));

        $user = new User();
        $user->name = $name !== '' ? $name : 'System Administrator';
        $user->email = $email;
        // The 'hashed' cast on User::casts() hashes this on the way in.
        $user->password = $password;
        // is_system_admin is guarded against mass assignment (see User), so it is set
        // explicitly — the one deliberate grant of the most powerful flag in the system.
        $user->is_system_admin = true;
        $user->save();

        $this->info('System Admin created.');
        $this->newLine();
        $this->line("  email:    {$user->email}");
        $this->line("  password: {$password}");
        $this->newLine();
        // Printed once, never stored in plaintext and never recoverable from the database.
        $this->warn('Store this password now — it is shown once and cannot be retrieved later.');

        return self::SUCCESS;
    }
}
