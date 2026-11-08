<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Email verification is OTP-based (see EmailVerificationService), so the
 * framework's link-based MustVerifyEmail contract is intentionally not used.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * SECURITY: role, status, lockout & audit columns are intentionally NOT fillable.
     * They can only be changed explicitly via forceFill() inside the service layer,
     * which prevents mass-assignment privilege escalation.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'failed_login_attempts',
        'locked_until',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'     => 'datetime',
            'password'              => 'hashed',
            'role'                  => UserRole::class,
            'status'                => UserStatus::class,
            'last_login_at'         => 'datetime',
            'locked_until'          => 'datetime',
            'password_changed_at'   => 'datetime',
            'failed_login_attempts' => 'integer',
        ];
    }

    /* ----------------------------------------------------------------- */
    /* Relations                                                          */
    /* ----------------------------------------------------------------- */

    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    public function verificationCodes(): HasMany
    {
        return $this->hasMany(VerificationCode::class);
    }

    public function teacherProfile(): HasOne
    {
        return $this->hasOne(TeacherProfile::class);
    }

    public function teacherPayoutAccounts(): HasMany
    {
        return $this->hasMany(TeacherPayoutAccount::class, 'teacher_id');
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'teacher_id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'teacher_id');
    }

    public function studyGroups(): HasMany
    {
        return $this->hasMany(StudyGroup::class, 'teacher_id');
    }

    public function scheduleNotes(): HasMany
    {
        return $this->hasMany(TeacherScheduleNote::class, 'teacher_id');
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'teacher_id');
    }

    public function examSubmissions(): HasMany
    {
        return $this->hasMany(ExamSubmission::class, 'student_id');
    }

    public function homeworks(): HasMany
    {
        return $this->hasMany(Homework::class, 'teacher_id');
    }

    public function homeworkSubmissions(): HasMany
    {
        return $this->hasMany(HomeworkSubmission::class, 'student_id');
    }

    public function courseLeagues(): HasMany
    {
        return $this->hasMany(CourseLeague::class, 'teacher_id');
    }

    public function leagueParticipations(): HasMany
    {
        return $this->hasMany(LeagueParticipant::class, 'student_id');
    }

    public function analyticsSnapshots(): HasMany
    {
        return $this->hasMany(TeacherAnalyticsSnapshot::class, 'teacher_id');
    }

    public function studentRiskSignals(): HasMany
    {
        return $this->hasMany(StudentRiskSignal::class, 'teacher_id');
    }

    public function courseCertificates(): HasMany
    {
        return $this->hasMany(CourseCertificate::class, 'teacher_id');
    }

    public function studentDirectPayments(): HasMany
    {
        return $this->hasMany(StudentDirectPayment::class, 'teacher_id');
    }

    public function studentPurchases(): HasMany
    {
        return $this->hasMany(StudentDirectPayment::class, 'student_id');
    }

    public function teacherSubscriptions(): HasMany
    {
        return $this->hasMany(TeacherSubscription::class, 'teacher_id');
    }

    public function teacherInvoices(): HasMany
    {
        return $this->hasMany(TeacherInvoice::class, 'teacher_id');
    }

    /**
     * Students linked to this parent account.
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'parent_student', 'parent_id', 'student_id')
            ->withTimestamps();
    }

    /**
     * Parents linked to this student account.
     */
    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'parent_student', 'student_id', 'parent_id')
            ->withTimestamps();
    }

    /* ----------------------------------------------------------------- */
    /* Role helpers                                                       */
    /* ----------------------------------------------------------------- */

    public function hasRole(UserRole|string $role): bool
    {
        $roleValue = $role instanceof UserRole ? $role->value : $role;
        return $this->role->value === $roleValue;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    public function isTeacher(): bool
    {
        return $this->role === UserRole::TEACHER;
    }

    public function isStudent(): bool
    {
        return $this->role === UserRole::STUDENT;
    }

    public function isParent(): bool
    {
        return $this->role === UserRole::PARENT;
    }

    /* ----------------------------------------------------------------- */
    /* Security state helpers                                             */
    /* ----------------------------------------------------------------- */

    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }
}
