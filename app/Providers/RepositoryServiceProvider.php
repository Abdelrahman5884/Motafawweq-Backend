<?php

namespace App\Providers;

use App\Repositories\Contracts\CourseRepositoryInterface;
use App\Repositories\Contracts\ExamRepositoryInterface;
use App\Repositories\Contracts\GroupEnrollmentRepositoryInterface;
use App\Repositories\Contracts\LessonRepositoryInterface;
use App\Repositories\Contracts\LoginHistoryRepositoryInterface;
use App\Repositories\Contracts\StudyGroupRepositoryInterface;
use App\Repositories\Contracts\TeacherPayoutAccountRepositoryInterface;
use App\Repositories\Contracts\TeacherProfileRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\VerificationCodeRepositoryInterface;
use App\Repositories\Eloquent\CourseRepository;
use App\Repositories\Eloquent\ExamRepository;
use App\Repositories\Eloquent\GroupEnrollmentRepository;
use App\Repositories\Eloquent\LessonRepository;
use App\Repositories\Eloquent\LoginHistoryRepository;
use App\Repositories\Eloquent\StudyGroupRepository;
use App\Repositories\Eloquent\TeacherPayoutAccountRepository;
use App\Repositories\Eloquent\TeacherProfileRepository;
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
        UserRepositoryInterface::class                 => UserRepository::class,
        VerificationCodeRepositoryInterface::class     => VerificationCodeRepository::class,
        LoginHistoryRepositoryInterface::class         => LoginHistoryRepository::class,
        TeacherProfileRepositoryInterface::class       => TeacherProfileRepository::class,
        TeacherPayoutAccountRepositoryInterface::class => TeacherPayoutAccountRepository::class,
        CourseRepositoryInterface::class               => CourseRepository::class,
        LessonRepositoryInterface::class               => LessonRepository::class,
        StudyGroupRepositoryInterface::class           => StudyGroupRepository::class,
        GroupEnrollmentRepositoryInterface::class      => GroupEnrollmentRepository::class,
        ExamRepositoryInterface::class                 => ExamRepository::class,
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
