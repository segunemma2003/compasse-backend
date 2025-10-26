<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Define gates for role-based access control
        Gate::define('is-super-admin', function ($user) {
            return $user->isSuperAdmin();
        });

        Gate::define('is-school-admin', function ($user) {
            return $user->isSchoolAdmin();
        });

        Gate::define('is-teacher', function ($user) {
            return $user->isTeacher();
        });

        Gate::define('is-student', function ($user) {
            return $user->isStudent();
        });

        Gate::define('is-guardian', function ($user) {
            return $user->isGuardian();
        });

        // Define permission gates
        Gate::define('manage-tenant', function ($user) {
            return $user->isSuperAdmin();
        });

        Gate::define('manage-school', function ($user) {
            return $user->isSuperAdmin() || $user->isSchoolAdmin();
        });

        Gate::define('manage-users', function ($user) {
            return $user->isSuperAdmin() || $user->isSchoolAdmin();
        });

        Gate::define('manage-students', function ($user) {
            return $user->isSuperAdmin() || $user->isSchoolAdmin() || $user->isTeacher();
        });

        Gate::define('manage-teachers', function ($user) {
            return $user->isSuperAdmin() || $user->isSchoolAdmin();
        });

        Gate::define('view-reports', function ($user) {
            return $user->isSuperAdmin() || $user->isSchoolAdmin() || $user->isTeacher();
        });

        Gate::define('manage-finances', function ($user) {
            return $user->isSuperAdmin() || $user->isSchoolAdmin();
        });
    }
}
