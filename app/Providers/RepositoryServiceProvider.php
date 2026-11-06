<?php

namespace App\Providers;

use App\Repositories\Contracts\LoginHistoryRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\VerificationCodeRepositoryInterface;
use App\Repositories\Eloquent\LoginHistoryRepository;
use App\Repositories\Eloquent\UserRepository;
use App\Repositories\Eloquent\VerificationCodeRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * All repository interface-to-implementation bindings.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        UserRepositoryInterface::class             => UserRepository::class,
        VerificationCodeRepositoryInterface::class => VerificationCodeRepository::class,
        LoginHistoryRepositoryInterface::class     => LoginHistoryRepository::class,
    ];

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
        //
    }
}
