<?php

namespace App\Providers;

use App\Models\LeaveRequest;
use App\Models\MonthlyRecap;
use App\Models\User;
use App\Policies\LeaveRequestPolicy;
use App\Policies\MonthlyRecapPolicy;
use App\Services\Outsource\Session\ArrayOutsourceSessionStore;
use App\Services\Outsource\Session\OutsourceSessionStoreInterface;
use App\Services\Outsource\Session\RedisOutsourceSessionStore;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OutsourceSessionStoreInterface::class, function ($app) {
            $driver = (string) config('outsource_session.driver', 'redis');

            if ($driver === 'array') {
                return new ArrayOutsourceSessionStore;
            }

            return new RedisOutsourceSessionStore;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(LeaveRequest::class, LeaveRequestPolicy::class);
        Gate::policy(MonthlyRecap::class, MonthlyRecapPolicy::class);
        $this->registerAuthorizationGates();
    }

    /**
     * Register the centralized authorization foundation.
     *
     * - SUPER_ADMIN bypasses all permission checks through Gate::before.
     *   This is the single wildcard-access location in the application.
     * - Every Spatie permission (module.action) is registered as a Gate
     *   ability, so permissions stay dynamic (database-driven) while
     *   authorization stays centralized in Gate/Policy.
     *
     * Route protection should prefer `can:permission.name` so the Super
     * Admin bypass applies consistently.
     */
    protected function registerAuthorizationGates(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->hasRole('SUPER_ADMIN')) {
                return true;
            }

            return null;
        });

        // artisan/composer tooling boots the app outside a request lifecycle
        // (package:discover, config:cache, migrate, ...). The database may
        // not exist or be unreachable at that point (e.g. fresh CI runner
        // with no sqlite file yet). Never let gate registration crash boot:
        // fail closed (deny) and let the test/request lifecycle decide.
        try {
            if (! Schema::hasTable(config('permission.table_names.permissions'))) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        try {
            foreach (app(PermissionRegistrar::class)->getPermissions() as $permission) {
                Gate::define($permission->name, function (User $user) use ($permission): bool {
                    return $user->hasPermissionTo($permission->name);
                });
            }
        } catch (\Throwable) {
            // Permission table unreadable during boot — gates stay denied
            // until a lifecycle with a working database re-registers them.
        }
    }
}
