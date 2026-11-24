<?php

use App\Http\Controllers\Api\V1\Admin\AdminAnnouncementController;
use App\Http\Controllers\Api\V1\Admin\AdminAttendanceController;
use App\Http\Controllers\Api\V1\Admin\AdminChampionController;
use App\Http\Controllers\Api\V1\Admin\AdminCourseController;
use App\Http\Controllers\Api\V1\Admin\AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\AdminFinancialController;
use App\Http\Controllers\Api\V1\Admin\AdminRepublicLeagueController;
use App\Http\Controllers\Api\V1\Admin\AdminSystemController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Center\CenterAttendanceController;
use App\Http\Controllers\Api\V1\Center\CenterBillingController;
use App\Http\Controllers\Api\V1\Center\CenterBranchController;
use App\Http\Controllers\Api\V1\Center\CenterDashboardController;
use App\Http\Controllers\Api\V1\Center\CenterFinancialController;
use App\Http\Controllers\Api\V1\Center\CenterGroupController;
use App\Http\Controllers\Api\V1\Center\CenterRoomController;
use App\Http\Controllers\Api\V1\Center\CenterSettingsController;
use App\Http\Controllers\Api\V1\Center\CenterStaffController;
use App\Http\Controllers\Api\V1\Center\CenterTeacherController;
use App\Http\Controllers\Api\V1\Public\CertificateVerificationController;
use App\Http\Controllers\Api\V1\Public\PlatformPublicController;
use App\Http\Controllers\Api\V1\Storage\StorageController;
use App\Http\Controllers\Api\V1\Student\StudentBillingController;
use App\Http\Controllers\Api\V1\Student\StudentConvertedLectureController;
use App\Http\Controllers\Api\V1\Student\StudentCourseLessonController;
use App\Http\Controllers\Api\V1\Student\StudentDashboardController;
use App\Http\Controllers\Api\V1\Student\StudentExamTakingController;
use App\Http\Controllers\Api\V1\Student\StudentGamificationController;
use App\Http\Controllers\Api\V1\Student\StudentGroupScheduleController;
use App\Http\Controllers\Api\V1\Student\StudentHomeworkController;
use App\Http\Controllers\Api\V1\Student\StudentMinistryController;
use App\Http\Controllers\Api\V1\Student\StudentProfileController;
use App\Http\Controllers\Api\V1\Teacher\LessonWorkspaceController;
use App\Http\Controllers\Api\V1\Teacher\TeacherAnalyticsController;
use App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController;
use App\Http\Controllers\Api\V1\Teacher\TeacherBillingController;
use App\Http\Controllers\Api\V1\Teacher\TeacherCenterInvitationController;
use App\Http\Controllers\Api\V1\Teacher\TeacherCertificateController;
use App\Http\Controllers\Api\V1\Teacher\TeacherCourseController;
use App\Http\Controllers\Api\V1\Teacher\TeacherExamController;
use App\Http\Controllers\Api\V1\Teacher\TeacherExamGradingController;
use App\Http\Controllers\Api\V1\Teacher\TeacherFinancialController;
use App\Http\Controllers\Api\V1\Teacher\TeacherHomeworkController;
use App\Http\Controllers\Api\V1\Teacher\TeacherLeagueController;
use App\Http\Controllers\Api\V1\Teacher\TeacherPayoutAccountController;
use App\Http\Controllers\Api\V1\Teacher\TeacherRosterController;
use App\Http\Controllers\Api\V1\Teacher\TeacherScheduleController;
use App\Http\Controllers\Api\V1\Teacher\TeacherSettingsController;
use App\Http\Controllers\Api\V1\Teacher\TeacherStudioController;
use App\Http\Controllers\Api\V1\Teacher\TeacherStudyGroupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Motafawweq Platform
|--------------------------------------------------------------------------
| Middleware layers used below:
|   auth:sanctum             -> valid, non-expired bearer token
|   abilities:access         -> must be an ACCESS token (refresh tokens rejected)
|   abilities:refresh        -> must be a REFRESH token
|   account.active           -> blocks suspended accounts (re-checked every request)
|   account.active:strict    -> also blocks accounts pending approval
|   email.verified           -> email must be confirmed via OTP
|   role:admin,...           -> role-based access control
|   throttle:*               -> rate limiting (see AppServiceProvider)
*/

// Health check endpoint
Route::get('/ping', function () {
    return response()->json([
        'success' => true,
        'message' => 'Motafawweq Platform API is active and healthy.',
        'timestamp' => now()->toIso8601String(),
        'version' => 'v1',
    ]);
});

Route::prefix('v1')->group(function () {

    // Public Certificate Verification via QR Code
    Route::get('/verify-certificate/{serialNumber}', [CertificateVerificationController::class, 'verify'])
        ->middleware('throttle:api');

    // Public Cached Platform Catalogs (Champions, Announcements, Pricing Plans, Operational Status)
    Route::prefix('public')->middleware('throttle:public-catalog')->group(function () {
        Route::get('/champions', [PlatformPublicController::class, 'champions']);
        Route::get('/announcements', [PlatformPublicController::class, 'announcements']);
        Route::get('/pricing-plans', [PlatformPublicController::class, 'pricingPlans']);
        Route::get('/status', [PlatformPublicController::class, 'status']);
    });

    /*
    |----------------------------------------------------------------------
    | Public (guest) auth endpoints
    |----------------------------------------------------------------------
    */
    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('/register', [AuthController::class, 'register']);
            Route::post('/login', [AuthController::class, 'login']);
        });

        Route::middleware('throttle:otp')->prefix('password')->group(function () {
            Route::post('/forgot', [PasswordResetController::class, 'forgot']);
            Route::post('/verify-otp', [PasswordResetController::class, 'verifyOtp']);
            Route::post('/reset', [PasswordResetController::class, 'reset']);
        });

        // Refresh: only a REFRESH token is accepted here
        Route::post('/refresh', [AuthController::class, 'refresh'])
            ->middleware(['auth:sanctum', 'abilities:refresh', 'throttle:refresh']);
    });

    /*
    |----------------------------------------------------------------------
    | Authenticated — usable even before email verification / approval
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'abilities:access', 'account.active', 'throttle:api'])
        ->prefix('auth')
        ->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/logout-all', [AuthController::class, 'logoutAll']);
            Route::delete('/account', [AuthController::class, 'deleteAccount'])->middleware('throttle:strict');

            Route::middleware('throttle:otp')->prefix('email')->group(function () {
                Route::post('/verify', [EmailVerificationController::class, 'verify']);
                Route::post('/resend', [EmailVerificationController::class, 'resend']);
            });
        });

    /*
    |----------------------------------------------------------------------
    | Fully protected — verified email + active (approved) account
    |----------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'abilities:access', 'account.active:strict', 'email.verified', 'throttle:api'])
        ->group(function () {

            Route::prefix('auth')->group(function () {
                Route::put('/profile', [AuthController::class, 'updateProfile']);
                Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:strict');

                Route::get('/sessions', [SessionController::class, 'index']);
                Route::delete('/sessions', [SessionController::class, 'destroyOthers']);
                Route::delete('/sessions/{sessionId}', [SessionController::class, 'destroy'])->whereUuid('sessionId');
                Route::get('/login-history', [SessionController::class, 'history']);
            });

            // Centralized Supabase Storage Direct Upload & Media Management
            Route::prefix('storage')->group(function () {
                Route::post('/presign-upload', [StorageController::class, 'presignUpload'])
                    ->middleware('throttle:teacher-uploads');
                Route::post('/confirm-upload', [StorageController::class, 'confirmUpload'])
                    ->middleware('throttle:api');
                Route::get('/download-url/{fileUuid}', [StorageController::class, 'getDownloadUrl'])
                    ->whereUuid('fileUuid');
                Route::delete('/files/{fileUuid}', [StorageController::class, 'destroy'])
                    ->whereUuid('fileUuid');
            });

            // Legacy Student aliases (strictly protected with role:student + idempotency)
            Route::middleware('role:student')->group(function () {
                Route::post('/groups/join', [StudentGroupScheduleController::class, 'join'])
                    ->middleware(['throttle:join-code', 'idempotent']);

                Route::post('/exams/{uuid}/submit', [StudentExamTakingController::class, 'submit'])
                    ->whereUuid('uuid')
                    ->middleware(['throttle:exam-submit', 'idempotent']);

                Route::post('/homeworks/{uuid}/submit', [StudentHomeworkController::class, 'submit'])
                    ->whereUuid('uuid')
                    ->middleware(['throttle:student-uploads', 'idempotent']);

                Route::get('/homeworks/submissions/{submissionUuid}/attachment', [StudentHomeworkController::class, 'downloadSubmissionAttachment'])
                    ->whereUuid('submissionUuid');
            });

            // Student Portal area (strictly isolated to active, verified students)
            Route::prefix('student')->middleware('role:student')->group(function () {
                // Phase 2: Profile, Study Goals, Preferences & Privacy
                Route::get('/profile', [StudentProfileController::class, 'show'])
                    ->middleware('throttle:student-read');
                Route::put('/profile', [StudentProfileController::class, 'update'])
                    ->middleware('throttle:student-write');
                Route::post('/profile/avatar', [StudentProfileController::class, 'uploadAvatar'])
                    ->middleware('throttle:student-uploads');
                Route::delete('/profile/avatar', [StudentProfileController::class, 'deleteAvatar'])
                    ->middleware('throttle:student-write');

                // Phase 3: Study Groups, Weekly Schedule, Personal Study Sessions & Attendance
                Route::get('/groups', [StudentGroupScheduleController::class, 'index'])
                    ->middleware('throttle:student-read');
                Route::post('/groups/join', [StudentGroupScheduleController::class, 'join'])
                    ->middleware(['throttle:join-code', 'idempotent']);
                Route::delete('/groups/{uuid}/leave', [StudentGroupScheduleController::class, 'leave'])
                    ->whereUuid('uuid')
                    ->middleware('throttle:student-write');
                Route::post('/groups/invitations/{enrollmentUuid}/accept', [StudentGroupScheduleController::class, 'acceptInvitation'])
                    ->whereUuid('enrollmentUuid')
                    ->middleware(['throttle:student-write', 'idempotent']);
                Route::post('/groups/invitations/{enrollmentUuid}/decline', [StudentGroupScheduleController::class, 'declineInvitation'])
                    ->whereUuid('enrollmentUuid')
                    ->middleware(['throttle:student-write', 'idempotent']);

                Route::get('/schedule', [StudentGroupScheduleController::class, 'schedule'])
                    ->middleware('throttle:student-read');
                Route::post('/study-sessions', [StudentGroupScheduleController::class, 'storeStudySession'])
                    ->middleware('throttle:student-write');
                Route::put('/study-sessions/{uuid}', [StudentGroupScheduleController::class, 'updateStudySession'])
                    ->whereUuid('uuid')
                    ->middleware('throttle:student-write');
                Route::patch('/study-sessions/{uuid}/toggle', [StudentGroupScheduleController::class, 'toggleStudySession'])
                    ->whereUuid('uuid')
                    ->middleware('throttle:student-write');
                Route::delete('/study-sessions/{uuid}', [StudentGroupScheduleController::class, 'destroyStudySession'])
                    ->whereUuid('uuid')
                    ->middleware('throttle:student-write');

                Route::get('/attendance', [StudentGroupScheduleController::class, 'attendance'])
                    ->middleware('throttle:student-read');

                // Phase 4: Courses, Lessons, Anti-Skip Progress Heartbeat, Bookmarks & Notes
                Route::get('/courses', [StudentCourseLessonController::class, 'courses'])
                    ->middleware('throttle:student-read');
                Route::get('/courses/{uuid}', [StudentCourseLessonController::class, 'showCourse'])
                    ->whereUuid('uuid')
                    ->middleware('throttle:student-read');
                Route::get('/lessons/{uuid}', [StudentCourseLessonController::class, 'showLesson'])
                    ->whereUuid('uuid')
                    ->middleware('throttle:student-read');
                Route::post('/lessons/{uuid}/progress', [StudentCourseLessonController::class, 'recordProgress'])
                    ->whereUuid('uuid')
                    ->middleware('throttle:progress-heartbeat');
                Route::post('/lessons/{uuid}/toggle-complete', [StudentCourseLessonController::class, 'toggleComplete'])
                    ->whereUuid('uuid')
                    ->middleware('throttle:student-write');
                Route::post('/lessons/{uuid}/bookmark', [StudentCourseLessonController::class, 'toggleBookmark'])
                    ->whereUuid('uuid')
                    ->middleware('throttle:student-write');
                Route::post('/lessons/{uuid}/notes', [StudentCourseLessonController::class, 'storeNote'])
                    ->whereUuid('uuid')
                    ->middleware('throttle:student-write');
                Route::put('/lessons/notes/{noteUuid}', [StudentCourseLessonController::class, 'updateNote'])
                    ->whereUuid('noteUuid')
                    ->middleware('throttle:student-write');
                Route::delete('/lessons/notes/{noteUuid}', [StudentCourseLessonController::class, 'destroyNote'])
                    ->whereUuid('noteUuid')
                    ->middleware('throttle:student-write');

                // Phase 5: Exams, Server-Timed Attempts, Auto-Graded Submission & Mistake Bank
                Route::get('/exams', [StudentExamTakingController::class, 'index'])
                    ->middleware('throttle:student-read');
                Route::get('/exams/{uuid}/result', [StudentExamTakingController::class, 'result'])
                    ->whereUuid('uuid')
                    ->middleware('throttle:student-read');
                Route::post('/exams/{uuid}/start', [StudentExamTakingController::class, 'start'])
                    ->whereUuid('uuid')
                    ->middleware(['throttle:exam-submit', 'idempotent']);
                Route::post('/exams/{uuid}/submit', [StudentExamTakingController::class, 'submit'])
                    ->whereUuid('uuid')
                    ->middleware(['throttle:exam-submit', 'idempotent']);
                Route::get('/mistake-bank', [StudentExamTakingController::class, 'mistakeBank'])
                    ->middleware(['throttle:student-read', 'student.plan:weak_areas']);

                // Official Egyptian Ministry of Education Curricula & Guided Practice Exams
                Route::prefix('ministry')->group(function () {
                    Route::get('/curricula', [StudentMinistryController::class, 'curricula'])
                        ->middleware('throttle:student-read');
                    Route::post('/curricula/{code}/units/{unitIndex}/toggle', [StudentMinistryController::class, 'toggleUnit'])
                        ->where(['code' => '[a-z0-9\-]+', 'unitIndex' => '[0-9]+'])
                        ->middleware('throttle:student-write');
                    Route::get('/exams', [StudentMinistryController::class, 'exams'])
                        ->middleware('throttle:student-read');
                    Route::post('/exams/{code}/start', [StudentMinistryController::class, 'startExam'])
                        ->where('code', '[a-z0-9\-]+')
                        ->middleware(['throttle:exam-submit', 'idempotent']);
                    Route::post('/exams/{code}/submit', [StudentMinistryController::class, 'submitExam'])
                        ->where('code', '[a-z0-9\-]+')
                        ->middleware(['throttle:exam-submit', 'idempotent']);
                    Route::get('/exams/{code}/result', [StudentMinistryController::class, 'examResult'])
                        ->where('code', '[a-z0-9\-]+')
                        ->middleware('throttle:student-read');
                });

                // Phase 6: Homeworks & Secure Attachment Streaming
                Route::get('/homeworks', [StudentHomeworkController::class, 'index'])
                    ->middleware('throttle:student-read');
                Route::get('/homeworks/{uuid}/attachment', [StudentHomeworkController::class, 'downloadHomeworkAttachment'])
                    ->whereUuid('uuid')
                    ->middleware('throttle:student-read');
                Route::post('/homeworks/{uuid}/submit', [StudentHomeworkController::class, 'submit'])
                    ->whereUuid('uuid')
                    ->middleware(['throttle:student-uploads', 'idempotent']);
                Route::get('/homeworks/submissions/{submissionUuid}/attachment', [StudentHomeworkController::class, 'downloadSubmissionAttachment'])
                    ->whereUuid('submissionUuid')
                    ->middleware('throttle:student-read');

                // Phase 7 & 8: Gamification (Immutable XP Ledger), Leagues, Leaderboard & Certificates (Plus / Pro)
                Route::get('/gamification', [StudentGamificationController::class, 'summary'])
                    ->middleware(['throttle:student-read', 'student.plan:gamification']);
                Route::get('/gamification/workspace', [StudentGamificationController::class, 'workspace'])
                    ->middleware(['throttle:student-read', 'student.plan:gamification']);
                Route::get('/leagues', [StudentGamificationController::class, 'leagues'])
                    ->middleware(['throttle:student-read', 'student.plan:leagues']);
                Route::get('/leagues/general/leaderboard', [StudentGamificationController::class, 'generalLeaderboard'])
                    ->middleware(['throttle:student-read', 'student.plan:leagues']);
                Route::get('/leagues/{uuid}/leaderboard', [StudentGamificationController::class, 'leaderboard'])
                    ->whereUuid('uuid')
                    ->middleware(['throttle:student-read', 'student.plan:leagues']);
                Route::get('/certificates', [StudentGamificationController::class, 'certificates'])
                    ->middleware(['throttle:student-read', 'student.plan:certificates']);
                Route::get('/certificates/workspace', [StudentGamificationController::class, 'certificatesWorkspace'])
                    ->middleware(['throttle:student-read', 'student.plan:certificates']);
                Route::post('/certificates/claim', [StudentGamificationController::class, 'claimCourseCertificate'])
                    ->middleware(['throttle:student-write', 'idempotent', 'student.plan:certificates']);

                // Phase 9: Billing, Student Subscriptions, Coupon Validation & Direct Teacher Payments
                Route::get('/billing', [StudentBillingController::class, 'overview'])
                    ->middleware('throttle:student-read');
                Route::post('/billing/subscribe', [StudentBillingController::class, 'subscribe'])
                    ->middleware(['throttle:student-checkout', 'idempotent']);
                Route::post('/billing/coupons/validate', [StudentBillingController::class, 'validateCoupon'])
                    ->middleware('throttle:student-checkout');
                Route::post('/billing/payments', [StudentBillingController::class, 'storePayment'])
                    ->middleware(['throttle:student-checkout', 'idempotent']);

                // Phase 10: Dashboard, Daily Activity Heartbeat, Analytics (Plus) & Revision Hub (Pro)
                Route::get('/dashboard', [StudentDashboardController::class, 'dashboard'])
                    ->middleware('throttle:student-read');
                Route::post('/activity/heartbeat', [StudentDashboardController::class, 'heartbeat'])
                    ->middleware('throttle:activity-heartbeat');
                Route::get('/analytics', [StudentDashboardController::class, 'analytics'])
                    ->middleware(['throttle:student-read', 'student.plan:analytics']);
                Route::get('/revision', [StudentDashboardController::class, 'revision'])
                    ->middleware(['throttle:student-read', 'student.plan:revision']);

                // Phase 12: Schedule Notes, Converted Lectures Archive (Pro AI) & Extended Gamification (Plus)
                Route::post('/schedule/notes', [StudentGroupScheduleController::class, 'storeScheduleNote'])
                    ->middleware('throttle:student-write');
                Route::put('/schedule/notes/{uuid}', [StudentGroupScheduleController::class, 'updateScheduleNote'])
                    ->whereUuid('uuid')
                    ->middleware('throttle:student-write');
                Route::patch('/schedule/notes/{uuid}/toggle', [StudentGroupScheduleController::class, 'toggleScheduleNote'])
                    ->whereUuid('uuid')
                    ->middleware('throttle:student-write');
                Route::delete('/schedule/notes/{uuid}', [StudentGroupScheduleController::class, 'destroyScheduleNote'])
                    ->whereUuid('uuid')
                    ->middleware('throttle:student-write');

                Route::get('/converted-lectures', [StudentConvertedLectureController::class, 'index'])
                    ->middleware(['throttle:student-read', 'student.plan:converted_lectures']);
                Route::post('/converted-lectures', [StudentConvertedLectureController::class, 'store'])
                    ->middleware(['throttle:student-uploads', 'idempotent', 'student.plan:converted_lectures']);
                Route::get('/converted-lectures/{uuid}', [StudentConvertedLectureController::class, 'show'])
                    ->whereUuid('uuid')
                    ->middleware(['throttle:student-read', 'student.plan:converted_lectures']);
                Route::delete('/converted-lectures/{uuid}', [StudentConvertedLectureController::class, 'destroy'])
                    ->whereUuid('uuid')
                    ->middleware(['throttle:student-write', 'student.plan:converted_lectures']);

                Route::get('/gamification/heatmap', [StudentGamificationController::class, 'activityHeatmap'])
                    ->middleware(['throttle:student-read', 'student.plan:gamification']);
                Route::get('/gamification/challenges', [StudentGamificationController::class, 'weeklyChallenges'])
                    ->middleware(['throttle:student-read', 'student.plan:gamification']);
                Route::get('/leagues/history', [StudentGamificationController::class, 'pastLeagues'])
                    ->middleware(['throttle:student-read', 'student.plan:leagues']);
                Route::get('/certificates/eligibility', [StudentGamificationController::class, 'certificateEligibility'])
                    ->middleware(['throttle:student-read', 'student.plan:certificates']);
            });

            // Platform Admin Portal (strictly isolated to active, verified platform administrators)
            Route::prefix('admin')->middleware('role:admin')->group(function () {
                // Tab 1: Executive Dashboard, KPIs, Real-Time Traffic & AI Unit Economics
                Route::get('/dashboard', [AdminDashboardController::class, 'index'])
                    ->middleware('throttle:admin-read');
                Route::get('/dashboard/active-users', [AdminDashboardController::class, 'activeUsers'])
                    ->middleware('throttle:admin-read');

                // Tab 2: Honor Roll Cup Champions & General Republic League
                Route::prefix('champions')->group(function () {
                    Route::get('/', [AdminChampionController::class, 'index'])
                        ->middleware('throttle:admin-read');
                    Route::post('/', [AdminChampionController::class, 'store'])
                        ->middleware(['throttle:admin-write', 'idempotent']);
                    Route::post('/approve-nominee', [AdminChampionController::class, 'approveNominee'])
                        ->middleware(['throttle:admin-write', 'idempotent']);
                    Route::post('/upload-photo', [AdminChampionController::class, 'uploadPhoto'])
                        ->middleware('throttle:admin-write');
                    Route::put('/{uuid}', [AdminChampionController::class, 'update'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:admin-write');
                    Route::patch('/{uuid}/toggle-pin', [AdminChampionController::class, 'togglePin'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:admin-write');
                    Route::delete('/{uuid}', [AdminChampionController::class, 'destroy'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:admin-write');
                });

                Route::prefix('leagues/republic')->group(function () {
                    Route::get('/', [AdminRepublicLeagueController::class, 'show'])
                        ->middleware('throttle:admin-read');
                    Route::get('/leaderboard', [AdminRepublicLeagueController::class, 'leaderboard'])
                        ->middleware('throttle:admin-read');
                    Route::get('/nominees', [AdminRepublicLeagueController::class, 'nominees'])
                        ->middleware('throttle:admin-read');
                    Route::post('/start', [AdminRepublicLeagueController::class, 'start'])
                        ->middleware(['throttle:admin-write', 'idempotent']);
                    Route::post('/stop', [AdminRepublicLeagueController::class, 'stop'])
                        ->middleware('throttle:admin-sensitive');
                    Route::post('/announce-end', [AdminRepublicLeagueController::class, 'announceEnd'])
                        ->middleware(['throttle:admin-sensitive', 'idempotent']);
                });

                // Tab 3: User Management, KYC Verification Queue & Security Controls
                Route::prefix('users')->group(function () {
                    Route::get('/', [AdminUserController::class, 'index'])
                        ->middleware('throttle:admin-read');
                    Route::get('/verifications', [AdminUserController::class, 'verifications'])
                        ->middleware('throttle:admin-read');
                    Route::get('/export', [AdminUserController::class, 'exportCsv'])
                        ->middleware('throttle:admin-sensitive');
                    Route::get('/export-excel', [AdminUserController::class, 'exportExcel'])
                        ->middleware('throttle:admin-sensitive');
                    Route::post('/', [AdminUserController::class, 'store'])
                        ->middleware(['throttle:admin-write', 'idempotent']);
                    Route::get('/{id}', [AdminUserController::class, 'show'])
                        ->whereNumber('id')
                        ->middleware('throttle:admin-read');
                    Route::put('/{id}', [AdminUserController::class, 'update'])
                        ->whereNumber('id')
                        ->middleware('throttle:admin-write');
                    Route::patch('/{id}/toggle-status', [AdminUserController::class, 'toggleStatus'])
                        ->whereNumber('id')
                        ->middleware('throttle:admin-sensitive');
                    Route::post('/{id}/verify', [AdminUserController::class, 'verify'])
                        ->whereNumber('id')
                        ->middleware(['throttle:admin-write', 'idempotent']);
                    Route::post('/{id}/reject-verification', [AdminUserController::class, 'rejectVerification'])
                        ->whereNumber('id')
                        ->middleware(['throttle:admin-write', 'idempotent']);
                    Route::post('/{id}/reset-password', [AdminUserController::class, 'resetPassword'])
                        ->whereNumber('id')
                        ->middleware(['throttle:admin-sensitive', 'idempotent']);
                    Route::post('/{id}/revoke-sessions', [AdminUserController::class, 'revokeSessions'])
                        ->whereNumber('id')
                        ->middleware('throttle:admin-sensitive');
                    Route::delete('/{id}', [AdminUserController::class, 'destroy'])
                        ->whereNumber('id')
                        ->middleware('throttle:admin-sensitive');
                });

                // Tab 4: Courses & Content Moderation
                Route::prefix('courses')->group(function () {
                    Route::get('/', [AdminCourseController::class, 'index'])
                        ->middleware('throttle:admin-read');
                    Route::get('/{uuid}', [AdminCourseController::class, 'show'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:admin-read');
                    Route::patch('/{uuid}/toggle-publish', [AdminCourseController::class, 'togglePublish'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:admin-write');
                    Route::patch('/{uuid}/toggle-featured', [AdminCourseController::class, 'toggleFeatured'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:admin-write');
                    Route::delete('/{uuid}', [AdminCourseController::class, 'destroy'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:admin-sensitive');
                });

                // Tab 5: Financials, Tier Pricing Plans, Payout Settlements & Discount Coupons
                Route::prefix('financials')->group(function () {
                    Route::get('/overview', [AdminFinancialController::class, 'overview'])
                        ->middleware('throttle:admin-read');

                    Route::prefix('plans')->group(function () {
                        Route::get('/', [AdminFinancialController::class, 'plans'])
                            ->middleware('throttle:admin-read');
                        Route::post('/', [AdminFinancialController::class, 'storePlan'])
                            ->middleware(['throttle:admin-financial', 'idempotent']);
                        Route::post('/reset-defaults', [AdminFinancialController::class, 'resetPlans'])
                            ->middleware(['throttle:admin-sensitive', 'idempotent']);
                        Route::put('/{uuid}', [AdminFinancialController::class, 'updatePlan'])
                            ->whereUuid('uuid')
                            ->middleware('throttle:admin-financial');
                        Route::patch('/{uuid}/toggle-active', [AdminFinancialController::class, 'togglePlan'])
                            ->whereUuid('uuid')
                            ->middleware('throttle:admin-financial');
                        Route::delete('/{uuid}', [AdminFinancialController::class, 'destroyPlan'])
                            ->whereUuid('uuid')
                            ->middleware('throttle:admin-financial');
                    });

                    Route::prefix('payouts')->group(function () {
                        Route::get('/', [AdminFinancialController::class, 'payouts'])
                            ->middleware('throttle:admin-read');
                        Route::post('/', [AdminFinancialController::class, 'storePayout'])
                            ->middleware(['throttle:admin-financial', 'idempotent']);
                        Route::post('/{uuid}/approve', [AdminFinancialController::class, 'approvePayout'])
                            ->whereUuid('uuid')
                            ->middleware(['throttle:admin-financial', 'idempotent']);
                        Route::post('/{uuid}/reject', [AdminFinancialController::class, 'rejectPayout'])
                            ->whereUuid('uuid')
                            ->middleware(['throttle:admin-financial', 'idempotent']);
                    });

                    Route::prefix('coupons')->group(function () {
                        Route::get('/', [AdminFinancialController::class, 'coupons'])
                            ->middleware('throttle:admin-read');
                        Route::post('/', [AdminFinancialController::class, 'storeCoupon'])
                            ->middleware(['throttle:admin-financial', 'idempotent']);
                        Route::put('/{uuid}', [AdminFinancialController::class, 'updateCoupon'])
                            ->whereUuid('uuid')
                            ->middleware('throttle:admin-financial');
                        Route::patch('/{uuid}/toggle-active', [AdminFinancialController::class, 'toggleCoupon'])
                            ->whereUuid('uuid')
                            ->middleware('throttle:admin-financial');
                        Route::delete('/{uuid}', [AdminFinancialController::class, 'destroyCoupon'])
                            ->whereUuid('uuid')
                            ->middleware('throttle:admin-financial');
                    });
                });

                // Tab 6: Global Center Gate Attendance Monitoring & Export
                Route::prefix('attendance')->group(function () {
                    Route::get('/gates', [AdminAttendanceController::class, 'gates'])
                        ->middleware('throttle:admin-read');
                    Route::get('/gates/export', [AdminAttendanceController::class, 'exportCsv'])
                        ->middleware('throttle:admin-sensitive');
                });

                // Tab 7: Platform Announcements & News Ticker
                Route::prefix('announcements')->group(function () {
                    Route::get('/', [AdminAnnouncementController::class, 'index'])
                        ->middleware('throttle:admin-read');
                    Route::post('/', [AdminAnnouncementController::class, 'store'])
                        ->middleware(['throttle:admin-write', 'idempotent']);
                    Route::put('/{uuid}', [AdminAnnouncementController::class, 'update'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:admin-write');
                    Route::patch('/{uuid}/toggle-active', [AdminAnnouncementController::class, 'toggleActive'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:admin-write');
                    Route::delete('/{uuid}', [AdminAnnouncementController::class, 'destroy'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:admin-write');
                });

                // Tab 8: Infrastructure Telemetry, Platform Policies, Cache Control & Immutable Security Audit Trail
                Route::prefix('system')->group(function () {
                    Route::get('/overview', [AdminSystemController::class, 'overview'])
                        ->middleware('throttle:admin-read');
                    Route::put('/settings', [AdminSystemController::class, 'updateSettings'])
                        ->middleware('throttle:admin-sensitive');
                    Route::post('/cache/flush', [AdminSystemController::class, 'flushCache'])
                        ->middleware('throttle:admin-sensitive');
                    Route::get('/audit-logs', [AdminSystemController::class, 'auditLogs'])
                        ->middleware('throttle:admin-read');
                    Route::get('/security-summary', [AdminSystemController::class, 'securitySummary'])
                        ->middleware('throttle:admin-read');
                });
            });

            // Teacher Portal area (strictly isolated to active, verified teachers)
            Route::prefix('teacher')->middleware('role:teacher')->group(function () {
                // Stage 2: Academic Profile, Stages & Preferences Settings
                Route::prefix('settings')->group(function () {
                    Route::get('/', [TeacherSettingsController::class, 'show']);
                    Route::put('/', [TeacherSettingsController::class, 'update']);
                    Route::put('/profile', [TeacherSettingsController::class, 'update']);
                    Route::put('/stages', [TeacherSettingsController::class, 'updateStages']);
                    Route::put('/preferences', [TeacherSettingsController::class, 'updatePreferences']);
                    Route::post('/avatar', [TeacherSettingsController::class, 'uploadAvatar'])
                        ->middleware('throttle:teacher-uploads');
                    Route::delete('/avatar', [TeacherSettingsController::class, 'deleteAvatar']);
                });

                // Incoming Educational Center Invitations (Teacher must approve/decline)
                Route::prefix('center-invitations')->group(function () {
                    Route::get('/', [TeacherCenterInvitationController::class, 'index']);
                    Route::post('/{uuid}/accept', [TeacherCenterInvitationController::class, 'accept'])
                        ->whereUuid('uuid')
                        ->middleware('idempotent');
                    Route::post('/{uuid}/decline', [TeacherCenterInvitationController::class, 'decline'])
                        ->whereUuid('uuid')
                        ->middleware('idempotent');
                });

                // Stage 3: Encrypted Payout & Direct Receiving Accounts (Plus & Pro)
                Route::prefix('payout-accounts')->middleware('teacher.plan:financials')->group(function () {
                    Route::get('/', [TeacherPayoutAccountController::class, 'index']);

                    Route::middleware('throttle:financial-mutation')->group(function () {
                        Route::post('/', [TeacherPayoutAccountController::class, 'store']);
                        Route::patch('/{uuid}/toggle-active', [TeacherPayoutAccountController::class, 'toggleActive'])
                            ->whereUuid('uuid');
                        Route::patch('/{uuid}/set-default', [TeacherPayoutAccountController::class, 'setDefault'])
                            ->whereUuid('uuid');
                        Route::delete('/{uuid}', [TeacherPayoutAccountController::class, 'destroy'])
                            ->whereUuid('uuid');
                    });
                });

                // Stage 4: Courses & Curriculum Management (Protected by Plan Quota on creation)
                Route::prefix('courses')->group(function () {
                    Route::get('/', [TeacherCourseController::class, 'index']);
                    Route::post('/', [TeacherCourseController::class, 'store'])
                        ->middleware('teacher.quota:courses');
                    Route::get('/{uuid}', [TeacherCourseController::class, 'show'])
                        ->whereUuid('uuid');
                    Route::put('/{uuid}', [TeacherCourseController::class, 'update'])
                        ->whereUuid('uuid');
                    Route::delete('/{uuid}', [TeacherCourseController::class, 'destroy'])
                        ->whereUuid('uuid');
                });

                // Stage 5: Recording Studio & Secure Media / Attachments (Quota-gated on creation; AI gated to Pro)
                Route::prefix('lessons')->group(function () {
                    Route::get('/', [TeacherStudioController::class, 'index']);
                    Route::post('/', [TeacherStudioController::class, 'store'])
                        ->middleware('teacher.quota:lessons');
                    Route::get('/{uuid}', [TeacherStudioController::class, 'show'])
                        ->whereUuid('uuid');
                    Route::put('/{uuid}', [TeacherStudioController::class, 'update'])
                        ->whereUuid('uuid');
                    Route::delete('/{uuid}', [TeacherStudioController::class, 'destroy'])
                        ->whereUuid('uuid');

                    Route::middleware('throttle:teacher-uploads')->group(function () {
                        Route::post('/{uuid}/media', [TeacherStudioController::class, 'uploadMedia'])
                            ->whereUuid('uuid');
                        Route::post('/{uuid}/attachments/pdfs', [TeacherStudioController::class, 'uploadPdf'])
                            ->whereUuid('uuid');
                        Route::post('/{uuid}/attachments/images', [TeacherStudioController::class, 'uploadImage'])
                            ->whereUuid('uuid');
                    });

                    Route::put('/{uuid}/attachments/reorder', [TeacherStudioController::class, 'reorderAttachments'])
                        ->whereUuid('uuid');
                    Route::delete('/{uuid}/attachments/{attachmentUuid}', [TeacherStudioController::class, 'destroyAttachment'])
                        ->whereUuid(['uuid', 'attachmentUuid']);
                });

                // Stage 6: Lesson Workspace, Chapters, Transcripts & Concept Graphs (AI tabs gated to Pro)
                Route::prefix('workspace')->group(function () {
                    Route::get('/', [LessonWorkspaceController::class, 'index']);
                    Route::get('/lessons/{uuid}', [LessonWorkspaceController::class, 'show'])
                        ->whereUuid('uuid');
                    Route::put('/lessons/{uuid}/chapters', [LessonWorkspaceController::class, 'syncChapters'])
                        ->whereUuid('uuid');
                    Route::put('/lessons/{uuid}/transcript', [LessonWorkspaceController::class, 'syncTranscript'])
                        ->whereUuid('uuid')
                        ->middleware('teacher.plan:ai');
                    Route::put('/lessons/{uuid}/concept-graph', [LessonWorkspaceController::class, 'saveConceptGraph'])
                        ->whereUuid('uuid')
                        ->middleware('teacher.plan:ai');
                });

                // Stage 7 & 8: Study Groups, Schedule & Student Roster
                Route::prefix('groups')->group(function () {
                    Route::get('/', [TeacherStudyGroupController::class, 'index']);
                    Route::post('/', [TeacherStudyGroupController::class, 'store'])
                        ->middleware('teacher.quota:groups');
                    Route::get('/{uuid}', [TeacherStudyGroupController::class, 'show'])
                        ->whereUuid('uuid');
                    Route::put('/{uuid}', [TeacherStudyGroupController::class, 'update'])
                        ->whereUuid('uuid');
                    Route::delete('/{uuid}', [TeacherStudyGroupController::class, 'destroy'])
                        ->whereUuid('uuid');
                    Route::post('/{uuid}/regenerate-code', [TeacherStudyGroupController::class, 'regenerateCode'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:join-code');

                    // Cohort Announcements / Quick Broadcast Alerts
                    Route::get('/{uuid}/announcements', [TeacherStudyGroupController::class, 'announcements'])
                        ->whereUuid('uuid');
                    Route::post('/{uuid}/announcements', [TeacherStudyGroupController::class, 'storeAnnouncement'])
                        ->whereUuid('uuid');

                    // Stage 8: Student Roster, Invitations & Join Requests
                    Route::get('/{uuid}/roster', [TeacherRosterController::class, 'show'])
                        ->whereUuid('uuid');
                    Route::post('/{uuid}/invite', [TeacherRosterController::class, 'invite'])
                        ->whereUuid('uuid');
                    Route::post('/{uuid}/enrollments/{enrollmentUuid}/accept', [TeacherRosterController::class, 'accept'])
                        ->whereUuid(['uuid', 'enrollmentUuid']);
                    Route::post('/{uuid}/enrollments/{enrollmentUuid}/reject', [TeacherRosterController::class, 'reject'])
                        ->whereUuid(['uuid', 'enrollmentUuid']);
                    Route::delete('/{uuid}/enrollments/{enrollmentUuid}', [TeacherRosterController::class, 'destroy'])
                        ->whereUuid(['uuid', 'enrollmentUuid']);

                    // Stage 9: Smart QR/Barcode Attendance
                    Route::prefix('/{uuid}/attendance')->group(function () {
                        Route::post('/scan', [TeacherAttendanceController::class, 'scan'])
                            ->middleware('throttle:qr-scan');
                        Route::post('/toggle', [TeacherAttendanceController::class, 'toggle']);
                        Route::get('/sessions', [TeacherAttendanceController::class, 'index']);
                        Route::get('/sessions/{sessionUuid}', [TeacherAttendanceController::class, 'show'])
                            ->whereUuid('sessionUuid');
                    })->whereUuid('uuid');
                });

                Route::get('/students/search', [TeacherRosterController::class, 'search']);

                Route::prefix('schedule')->middleware('teacher.plan:schedule')->group(function () {
                    Route::get('/', [TeacherScheduleController::class, 'index']);
                    Route::post('/notes', [TeacherScheduleController::class, 'storeNote']);
                    Route::patch('/notes/{uuid}/toggle', [TeacherScheduleController::class, 'toggleNote'])
                        ->whereUuid('uuid');
                    Route::delete('/notes/{uuid}', [TeacherScheduleController::class, 'destroyNote'])
                        ->whereUuid('uuid');
                });

                // Stage 10: Question Bank & Bubble Sheet Exams (Plus & Pro)
                Route::prefix('exams')->middleware('teacher.plan:exams')->group(function () {
                    Route::get('/', [TeacherExamController::class, 'index']);
                    Route::post('/', [TeacherExamController::class, 'store']);
                    Route::get('/{uuid}', [TeacherExamController::class, 'show'])
                        ->whereUuid('uuid');
                    Route::put('/{uuid}', [TeacherExamController::class, 'update'])
                        ->whereUuid('uuid');
                    Route::patch('/{uuid}/toggle-status', [TeacherExamController::class, 'toggleStatus'])
                        ->whereUuid('uuid');
                    Route::patch('/{uuid}/toggle-results', [TeacherExamController::class, 'toggleResults'])
                        ->whereUuid('uuid');
                    Route::post('/{uuid}/duplicate', [TeacherExamController::class, 'duplicate'])
                        ->whereUuid('uuid');
                    Route::delete('/{uuid}', [TeacherExamController::class, 'destroy'])
                        ->whereUuid('uuid');

                    // Stage 11: Exam Submissions, Student Answers & Grading Workflow
                    Route::get('/{uuid}/submissions', [TeacherExamGradingController::class, 'index'])
                        ->whereUuid('uuid');
                    Route::get('/{uuid}/submissions/{submissionUuid}', [TeacherExamGradingController::class, 'show'])
                        ->whereUuid('uuid')
                        ->whereUuid('submissionUuid');
                    Route::patch('/{uuid}/submissions/{submissionUuid}/grade-essay', [TeacherExamGradingController::class, 'gradeEssay'])
                        ->whereUuid('uuid')
                        ->whereUuid('submissionUuid');
                });

                // Stage 12: Homework Management & Grading Workflow (Plus & Pro)
                Route::prefix('homeworks')->middleware('teacher.plan:homework')->group(function () {
                    Route::get('/', [TeacherHomeworkController::class, 'index']);
                    Route::post('/', [TeacherHomeworkController::class, 'store'])
                        ->middleware('throttle:teacher-uploads');
                    Route::get('/{uuid}', [TeacherHomeworkController::class, 'show'])
                        ->whereUuid('uuid');
                    Route::put('/{uuid}', [TeacherHomeworkController::class, 'update'])
                        ->whereUuid('uuid');
                    Route::delete('/{uuid}', [TeacherHomeworkController::class, 'destroy'])
                        ->whereUuid('uuid');
                    Route::get('/{uuid}/submissions', [TeacherHomeworkController::class, 'submissions'])
                        ->whereUuid('uuid');
                    Route::get('/{uuid}/submissions/{submissionUuid}', [TeacherHomeworkController::class, 'submission'])
                        ->whereUuid('uuid')
                        ->whereUuid('submissionUuid');
                    Route::patch('/{uuid}/submissions/{submissionUuid}/grade', [TeacherHomeworkController::class, 'gradeSubmission'])
                        ->whereUuid('uuid')
                        ->whereUuid('submissionUuid');
                    Route::get('/{uuid}/attachment', [TeacherHomeworkController::class, 'downloadAttachment'])
                        ->whereUuid('uuid');
                    Route::get('/submissions/{submissionUuid}/attachment', [TeacherHomeworkController::class, 'downloadSubmissionAttachment'])
                        ->whereUuid('submissionUuid');
                });

                // Stage 13: Course Leagues, XP & Leaderboards (Plus & Pro)
                Route::prefix('leagues')->middleware('teacher.plan:leagues')->group(function () {
                    Route::get('/', [TeacherLeagueController::class, 'index']);
                    Route::post('/', [TeacherLeagueController::class, 'store']);
                    Route::get('/{uuid}', [TeacherLeagueController::class, 'show'])
                        ->whereUuid('uuid');
                    Route::put('/{uuid}', [TeacherLeagueController::class, 'update'])
                        ->whereUuid('uuid');
                    Route::patch('/{uuid}', [TeacherLeagueController::class, 'update'])
                        ->whereUuid('uuid');
                    Route::post('/{uuid}/participants', [TeacherLeagueController::class, 'addParticipant'])
                        ->whereUuid('uuid');
                    Route::delete('/{uuid}/participants/{participantId}', [TeacherLeagueController::class, 'removeParticipant'])
                        ->whereUuid('uuid');
                    Route::post('/{uuid}/award-xp', [TeacherLeagueController::class, 'awardXp'])
                        ->whereUuid('uuid');
                    Route::post('/{uuid}/recalculate', [TeacherLeagueController::class, 'recalculate'])
                        ->whereUuid('uuid');
                    Route::post('/{uuid}/crown', [TeacherLeagueController::class, 'crown'])
                        ->whereUuid('uuid');
                    Route::delete('/{uuid}', [TeacherLeagueController::class, 'destroy'])
                        ->whereUuid('uuid');
                });

                // Stage 14: Teacher Analytics, Weak Areas, At-Risk Radar & Dashboard
                Route::get('/dashboard', [TeacherAnalyticsController::class, 'dashboard']);

                Route::prefix('analytics')->group(function () {
                    Route::get('/overview', [TeacherAnalyticsController::class, 'overview']);
                    Route::get('/weak-areas', [TeacherAnalyticsController::class, 'weakAreas']);
                    Route::get('/at-risk-radar', [TeacherAnalyticsController::class, 'atRiskRadar']);
                    Route::post('/at-risk/{uuid}/resolve', [TeacherAnalyticsController::class, 'resolveRiskSignal'])
                        ->whereUuid('uuid');
                });

                // Stage 15: Certificates, Direct Payments & Teacher Billing
                Route::prefix('certificates')->middleware('teacher.plan:certificates')->group(function () {
                    Route::get('/', [TeacherCertificateController::class, 'index']);
                    Route::get('/eligibility-context', [TeacherCertificateController::class, 'eligibilityContext']);
                    Route::post('/', [TeacherCertificateController::class, 'store']);
                    Route::post('/bulk-issue', [TeacherCertificateController::class, 'bulkStore']);
                    Route::patch('/{uuid}/revoke', [TeacherCertificateController::class, 'revoke'])
                        ->whereUuid('uuid');
                });

                Route::prefix('financials')->middleware('teacher.plan:financials')->group(function () {
                    Route::get('/', [TeacherFinancialController::class, 'index']);
                    Route::post('/payments/{uuid}/confirm', [TeacherFinancialController::class, 'confirm'])
                        ->whereUuid('uuid');
                    Route::post('/payments/{uuid}/reject', [TeacherFinancialController::class, 'reject'])
                        ->whereUuid('uuid');
                });

                Route::prefix('billing')->group(function () {
                    Route::get('/', [TeacherBillingController::class, 'index']);
                    Route::post('/validate-coupon', [TeacherBillingController::class, 'validateCoupon']);
                    Route::post('/subscribe', [TeacherBillingController::class, 'subscribe']);
                    Route::post('/cancel', [TeacherBillingController::class, 'cancel']);
                });
            });

            // Educational Center Portal area (strictly isolated to active, verified centers)
            Route::prefix('center')->middleware('role:center')->group(function () {
                // Dashboard, Live Daily Queue & Administrative Executive Report
                Route::get('/dashboard', [CenterDashboardController::class, 'index'])
                    ->middleware('throttle:center-read');
                Route::prefix('queue')->middleware('throttle:center-write')->group(function () {
                    Route::post('/complete', [CenterDashboardController::class, 'completeQueueItem']);
                    Route::post('/restore', [CenterDashboardController::class, 'restoreQueueItem']);
                    Route::post('/reset', [CenterDashboardController::class, 'resetQueue']);
                });
                Route::get('/reports/executive', [CenterDashboardController::class, 'executiveReport'])
                    ->middleware(['throttle:center-read', 'center.plan:executive_reports']);

                // Branches CRUD & Export (Quota-gated on creation)
                Route::prefix('branches')->group(function () {
                    Route::get('/', [CenterBranchController::class, 'index'])
                        ->middleware('throttle:center-read');
                    Route::get('/export', [CenterBranchController::class, 'exportCsv'])
                        ->middleware('throttle:center-read');
                    Route::post('/', [CenterBranchController::class, 'store'])
                        ->middleware(['throttle:center-write', 'center.quota:branches']);
                    Route::get('/{uuid}', [CenterBranchController::class, 'show'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-read');
                    Route::put('/{uuid}', [CenterBranchController::class, 'update'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                    Route::delete('/{uuid}', [CenterBranchController::class, 'destroy'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                });

                // Classrooms / Halls CRUD & Conflict-Free Master Schedule (Quota-gated on creation)
                Route::prefix('rooms')->group(function () {
                    Route::get('/', [CenterRoomController::class, 'index'])
                        ->middleware('throttle:center-read');
                    Route::post('/', [CenterRoomController::class, 'store'])
                        ->middleware(['throttle:center-write', 'center.quota:rooms']);
                    Route::get('/{uuid}', [CenterRoomController::class, 'show'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-read');
                    Route::put('/{uuid}', [CenterRoomController::class, 'update'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                    Route::delete('/{uuid}', [CenterRoomController::class, 'destroy'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                });

                Route::prefix('schedule')->middleware('center.plan:master_schedule')->group(function () {
                    Route::get('/', [CenterRoomController::class, 'masterSchedule'])
                        ->middleware('throttle:center-read');
                    Route::get('/availability', [CenterRoomController::class, 'slotAvailability'])
                        ->middleware('throttle:center-read');
                    Route::post('/check-conflicts', [CenterRoomController::class, 'checkConflicts'])
                        ->middleware('throttle:center-read');
                });

                // Certified Teachers Network & Motafawweq Connect (Quota-gated on creation)
                Route::prefix('teachers')->group(function () {
                    Route::get('/', [CenterTeacherController::class, 'index'])
                        ->middleware('throttle:center-read');
                    Route::post('/', [CenterTeacherController::class, 'store'])
                        ->middleware(['throttle:center-write', 'center.quota:teachers']);
                    Route::get('/{uuid}', [CenterTeacherController::class, 'show'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-read');
                    Route::put('/{uuid}', [CenterTeacherController::class, 'update'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                    Route::delete('/{uuid}', [CenterTeacherController::class, 'destroy'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                    Route::post('/{uuid}/resend-invite', [CenterTeacherController::class, 'resendInvite'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                });

                Route::prefix('connect')->group(function () {
                    Route::get('/search', [CenterTeacherController::class, 'searchGlobalUsers'])
                        ->middleware('throttle:center-read');
                    Route::get('/users', [CenterTeacherController::class, 'listConnectedUsers'])
                        ->middleware('throttle:center-read');
                    Route::post('/users', [CenterTeacherController::class, 'connectUser'])
                        ->middleware(['throttle:center-write', 'idempotent']);
                    Route::delete('/users/{uuid}', [CenterTeacherController::class, 'disconnectUser'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                });

                // Administrative Staff Roles & Immutable Tenant Audit Trail
                Route::prefix('staff')->middleware('center.plan:staff_management')->group(function () {
                    Route::get('/', [CenterStaffController::class, 'index'])
                        ->middleware('throttle:center-read');
                    Route::post('/', [CenterStaffController::class, 'store'])
                        ->middleware(['throttle:center-write', 'center.quota:staff']);
                    Route::put('/{uuid}', [CenterStaffController::class, 'update'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                    Route::delete('/{uuid}', [CenterStaffController::class, 'destroy'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                });

                Route::get('/audit-logs', [CenterStaffController::class, 'auditLogs'])
                    ->middleware(['throttle:center-read', 'center.plan:audit_logs']);

                // Study Groups, Cohorts, Rosters, Student Enrollment & Group Attendance
                Route::get('/students/search', [CenterGroupController::class, 'searchStudents'])
                    ->middleware('throttle:center-read');

                Route::prefix('groups')->group(function () {
                    Route::get('/', [CenterGroupController::class, 'index'])
                        ->middleware('throttle:center-read');
                    Route::post('/', [CenterGroupController::class, 'store'])
                        ->middleware(['throttle:center-write', 'center.quota:groups']);
                    Route::get('/{uuid}', [CenterGroupController::class, 'show'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-read');
                    Route::put('/{uuid}', [CenterGroupController::class, 'update'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                    Route::delete('/{uuid}', [CenterGroupController::class, 'destroy'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                    Route::post('/{uuid}/regenerate-code', [CenterGroupController::class, 'regenerateCode'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:join-code');

                    Route::get('/{uuid}/roster', [CenterGroupController::class, 'roster'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-read');
                    Route::post('/{uuid}/students', [CenterGroupController::class, 'enrollStudent'])
                        ->whereUuid('uuid')
                        ->middleware(['throttle:center-write', 'center.quota:users']);
                    Route::post('/{uuid}/enrollments/{enrollmentUuid}/accept', [CenterGroupController::class, 'acceptPending'])
                        ->whereUuid(['uuid', 'enrollmentUuid'])
                        ->middleware(['throttle:center-write', 'center.quota:users']);
                    Route::post('/{uuid}/enrollments/{enrollmentUuid}/reject', [CenterGroupController::class, 'rejectPending'])
                        ->whereUuid(['uuid', 'enrollmentUuid'])
                        ->middleware('throttle:center-write');
                    Route::patch('/{uuid}/enrollments/{enrollmentUuid}/payment', [CenterGroupController::class, 'updatePayment'])
                        ->whereUuid(['uuid', 'enrollmentUuid'])
                        ->middleware('throttle:center-financial');
                    Route::get('/{uuid}/enrollments/{enrollmentUuid}/card', [CenterGroupController::class, 'studentCard'])
                        ->whereUuid(['uuid', 'enrollmentUuid'])
                        ->middleware('throttle:center-read');
                    Route::delete('/{uuid}/enrollments/{enrollmentUuid}', [CenterGroupController::class, 'removeStudent'])
                        ->whereUuid(['uuid', 'enrollmentUuid'])
                        ->middleware('throttle:center-write');

                    // Group Attendance (QR Scan requires Plus+; Manual toggle works on all tiers)
                    Route::prefix('/{uuid}/attendance')->whereUuid('uuid')->group(function () {
                        Route::post('/scan', [CenterAttendanceController::class, 'scanInGroup'])
                            ->middleware(['throttle:center-qr-scan', 'center.plan:qr_scanner']);
                        Route::post('/toggle', [CenterAttendanceController::class, 'toggleInGroup'])
                            ->middleware('throttle:center-write');
                        Route::get('/sessions', [CenterAttendanceController::class, 'groupSessions'])
                            ->middleware('throttle:center-read');
                        Route::get('/sessions/{sessionUuid}', [CenterAttendanceController::class, 'groupSessionShow'])
                            ->whereUuid('sessionUuid')
                            ->middleware('throttle:center-read');
                    });
                });

                // Smart Gate Attendance, Register, Absence Alerts & Export
                Route::prefix('attendance')->group(function () {
                    Route::post('/smart-scan', [CenterAttendanceController::class, 'smartGateScan'])
                        ->middleware(['throttle:center-qr-scan', 'center.plan:qr_scanner']);
                    Route::get('/records', [CenterAttendanceController::class, 'records'])
                        ->middleware('throttle:center-read');
                    Route::get('/export', [CenterAttendanceController::class, 'exportCsv'])
                        ->middleware('throttle:center-read');
                    Route::post('/absence-alerts', [CenterAttendanceController::class, 'sendAbsenceAlert'])
                        ->middleware(['throttle:center-alerts', 'center.plan:whatsapp_alerts']);
                });

                // Center Financials ERP: P&L, Expenses, Automated Teacher Settlements & Dues (Plus & Pro)
                Route::prefix('financials')->middleware('center.plan:financials')->group(function () {
                    Route::get('/overview', [CenterFinancialController::class, 'overview'])
                        ->middleware('throttle:center-read');
                    Route::get('/expenses', [CenterFinancialController::class, 'expenses'])
                        ->middleware('throttle:center-read');
                    Route::get('/expenses/export', [CenterFinancialController::class, 'exportExpensesCsv'])
                        ->middleware('throttle:center-read');
                    Route::post('/expenses', [CenterFinancialController::class, 'storeExpense'])
                        ->middleware('throttle:center-financial');
                    Route::delete('/expenses/{uuid}', [CenterFinancialController::class, 'destroyExpense'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-financial');

                    Route::get('/settlements', [CenterFinancialController::class, 'settlements'])
                        ->middleware('throttle:center-read');
                    Route::post('/settlements/{teacherUuid}/approve', [CenterFinancialController::class, 'approveSettlement'])
                        ->whereUuid('teacherUuid')
                        ->middleware(['throttle:center-financial', 'idempotent']);

                    Route::get('/dues', [CenterFinancialController::class, 'dues'])
                        ->middleware('throttle:center-read');
                    Route::post('/dues/{enrollmentUuid}/remind', [CenterFinancialController::class, 'remindDue'])
                        ->whereUuid('enrollmentUuid')
                        ->middleware('throttle:center-alerts');
                    Route::post('/dues/{enrollmentUuid}/mark-paid', [CenterFinancialController::class, 'markDuePaid'])
                        ->whereUuid('enrollmentUuid')
                        ->middleware(['throttle:center-financial', 'idempotent']);
                });

                // Center 3-Tier Subscription Plans & Billing (Free / Plus / Pro)
                Route::prefix('billing')->group(function () {
                    Route::get('/', [CenterBillingController::class, 'index'])
                        ->middleware('throttle:center-read');
                    Route::post('/validate-coupon', [CenterBillingController::class, 'validateCoupon'])
                        ->middleware('throttle:center-financial');
                    Route::post('/subscribe', [CenterBillingController::class, 'subscribe'])
                        ->middleware(['throttle:center-financial', 'idempotent']);
                    Route::post('/cancel', [CenterBillingController::class, 'cancel'])
                        ->middleware('throttle:center-financial');
                });

                // Center Settings, Policies, Custom KPIs, Daily Tasks, Memos, Import & Cloud Backups
                Route::prefix('settings')->group(function () {
                    Route::get('/', [CenterSettingsController::class, 'show'])
                        ->middleware('throttle:center-read');
                    Route::put('/', [CenterSettingsController::class, 'update'])
                        ->middleware('throttle:center-write');
                    Route::post('/avatar', [CenterSettingsController::class, 'uploadAvatar'])
                        ->middleware('throttle:center-write');
                    Route::delete('/avatar', [CenterSettingsController::class, 'deleteAvatar'])
                        ->middleware('throttle:center-write');
                    Route::put('/kpis', [CenterSettingsController::class, 'updateKpis'])
                        ->middleware('throttle:center-write');
                });

                Route::prefix('tasks')->group(function () {
                    Route::get('/', [CenterSettingsController::class, 'tasks'])
                        ->middleware('throttle:center-read');
                    Route::post('/', [CenterSettingsController::class, 'storeTask'])
                        ->middleware('throttle:center-write');
                    Route::put('/{uuid}', [CenterSettingsController::class, 'updateTask'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                    Route::patch('/{uuid}/toggle', [CenterSettingsController::class, 'toggleTask'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                    Route::delete('/{uuid}', [CenterSettingsController::class, 'destroyTask'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                });

                Route::prefix('announcements')->group(function () {
                    Route::get('/', [CenterSettingsController::class, 'announcements'])
                        ->middleware('throttle:center-read');
                    Route::post('/', [CenterSettingsController::class, 'storeAnnouncement'])
                        ->middleware('throttle:center-write');
                    Route::delete('/{uuid}', [CenterSettingsController::class, 'destroyAnnouncement'])
                        ->whereUuid('uuid')
                        ->middleware('throttle:center-write');
                });

                Route::prefix('import')->group(function () {
                    Route::get('/template', [CenterSettingsController::class, 'importTemplate'])
                        ->middleware('throttle:center-read');
                    Route::post('/students', [CenterSettingsController::class, 'importStudents'])
                        ->middleware(['throttle:center-import', 'idempotent']);
                });

                Route::prefix('backups')->middleware('center.plan:cloud_backup')->group(function () {
                    Route::get('/', [CenterSettingsController::class, 'backups'])
                        ->middleware('throttle:center-read');
                    Route::post('/', [CenterSettingsController::class, 'createBackup'])
                        ->middleware(['throttle:center-import', 'idempotent']);
                });
            });
        });

    // Signed media & attachment streaming (authorized via cryptographic temporary signed URLs)
    Route::prefix('teacher/lessons')->group(function () {
        Route::get('/{uuid}/media/stream', [TeacherStudioController::class, 'streamMedia'])
            ->whereUuid('uuid')
            ->name('api.v1.teacher.lessons.media.stream');
        Route::get('/{uuid}/attachments/{attachmentUuid}/stream', [TeacherStudioController::class, 'streamAttachment'])
            ->whereUuid(['uuid', 'attachmentUuid'])
            ->name('api.v1.teacher.lessons.attachments.stream');
    });

    Route::prefix('media/student-lessons')->group(function () {
        Route::get('/{uuid}/media/stream', [StudentCourseLessonController::class, 'streamMedia'])
            ->whereUuid('uuid')
            ->name('api.v1.student.lessons.media.stream');
        Route::get('/{uuid}/attachments/{attachmentUuid}/stream', [StudentCourseLessonController::class, 'streamAttachment'])
            ->whereUuid(['uuid', 'attachmentUuid'])
            ->name('api.v1.student.lessons.attachments.stream');
    });

    Route::prefix('media/student-homeworks')->group(function () {
        Route::get('/{uuid}/attachment/stream', [StudentHomeworkController::class, 'streamHomeworkAttachment'])
            ->whereUuid('uuid')
            ->name('api.v1.student.homeworks.attachment.stream');
        Route::get('/submissions/{submissionUuid}/attachment/stream', [StudentHomeworkController::class, 'streamSubmissionAttachment'])
            ->whereUuid('submissionUuid')
            ->name('api.v1.student.homeworks.submission.attachment.stream');
    });

    Route::prefix('media/teacher-homeworks')->group(function () {
        Route::get('/{uuid}/attachment/stream', [TeacherHomeworkController::class, 'streamAttachment'])
            ->whereUuid('uuid')
            ->name('api.v1.teacher.homeworks.attachment.stream');
        Route::get('/submissions/{submissionUuid}/attachment/stream', [TeacherHomeworkController::class, 'streamSubmissionAttachment'])
            ->whereUuid('submissionUuid')
            ->name('api.v1.teacher.homeworks.submission.attachment.stream');
    });
});
