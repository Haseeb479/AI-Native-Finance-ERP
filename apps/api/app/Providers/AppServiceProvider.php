<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Domain\Organization\Context\TenantContext::class, function () {
            return new \App\Domain\Organization\Context\TenantContext();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::before(function ($user, string $ability, array $args = []) {
            if (! empty($args) && (is_string($args[0]) || $args[0] instanceof \App\Domain\Organization\Models\Organization)) {
                $org = $args[0];
                $entity = $args[1] ?? null;
                $branch = $args[2] ?? null;
                $dept = $args[3] ?? null;
                return app(\App\Domain\Identity\Services\AuthorizationService::class)->can($user, $ability, $org, $entity, $branch, $dept);
            }
            return null;
        });

        $this->app->terminating(function () {
            if ($this->app->bound(\App\Domain\Organization\Context\TenantContext::class)) {
                $this->app->make(\App\Domain\Organization\Context\TenantContext::class)->clear();
            }
        });
    }
}
