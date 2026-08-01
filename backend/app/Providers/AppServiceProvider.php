<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Requests\RequestEffectFactory;
use App\Domain\Requests\RequestEffectResolver;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Policies\DocumentPolicy;
use App\Policies\EmployeePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The one production implementation. Tests bind a spy/fake over this interface
        // (e.g. TwoHopApprovalTest's deferred-effect assertions) rather than mocking the
        // final RequestEffectFactory class directly, which Mockery cannot do.
        $this->app->bind(RequestEffectResolver::class, RequestEffectFactory::class);
    }

    public function boot(): void
    {
        self::assertConfigured();

        // Global oversight. A System Admin passes every gate; returning null (not false)
        // for everyone else lets the normal policy chain run. Spatie's own recommended
        // super-admin pattern. See docs/05-rbac.md.
        Gate::before(fn (User $user): ?bool => $user->is_system_admin ? true : null);

        // Five login attempts per minute per email+IP. The envelope renders the 429.
        RateLimiter::for('login', fn ($request) => Limit::perMinute(5)->by(
            $request->input('email').'|'.$request->ip()
        ));

        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);

        // Deliberately NO Relation::morphMap() here. Both Employee and Office already use
        // LogsActivity, which morphs through activity_log.subject_type/causer_type — a
        // GLOBAL registry, not scoped to document_files. Registering an alias for either
        // model would silently rewrite the type new activity rows are logged under while
        // every historical row keeps the FQCN, breaking ActivityResource's wire shape and
        // ListActivityController's subject_type filter in both directions. document_files
        // stores the FQCN instead, exactly like `media` and `activity_log` already do; a
        // later task's DocumentFileResource maps it to the config('documents.documentable')
        // alias at the wire layer. Do not add a morph map back without re-auditing every
        // existing polymorphic relation on Employee/Office, not just spatie's `media`.
    }

    /**
     * Fail fast, at boot, rather than as a wrong number at payday.
     *
     * A missing currency or a non-UTC timezone surfaces as data that looks corrupt
     * rather than as misconfiguration, and by the time anyone notices, the bad rows
     * are already mixed in with the good ones.
     *
     * Static and public so it can be tested without booting a second application.
     */
    public static function assertConfigured(): void
    {
        if (blank(config('hris.currency'))) {
            throw new RuntimeException('HRIS_CURRENCY is not set.');
        }

        if (blank(config('hris.organization_name'))) {
            throw new RuntimeException('HRIS_ORGANIZATION_NAME is not set.');
        }

        if (config('app.timezone') !== 'UTC') {
            throw new RuntimeException(
                'APP_TIMEZONE must be UTC. Display timezone belongs on offices.'
            );
        }
    }
}
