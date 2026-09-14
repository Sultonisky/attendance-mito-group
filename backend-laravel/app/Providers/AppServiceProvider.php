<?php

namespace App\Providers;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Policies\LeaveRequestPolicy;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(LeaveRequest::class, LeaveRequestPolicy::class);
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

        if (! Schema::hasTable(config('permission.table_names.permissions'))) {
            return;
        }

        foreach (app(PermissionRegistrar::class)->getPermissions() as $permission) {
            Gate::define($permission->name, function (User $user) use ($permission): bool {
                return $user->hasPermissionTo($permission->name);
            });
        }
    }
}
