<?php

use App\Http\Controllers\Api\V1\Admin\AdminRepublicLeagueController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Public\CertificateVerificationController;
use App\Http\Controllers\Api\V1\Storage\StorageController;
use App\Http\Controllers\Api\V1\Student\StudentBillingController;
use App\Http\Controllers\Api\V1\Student\StudentConvertedLectureController;
use App\Http\Controllers\Api\V1\Student\StudentCourseLessonController;
use App\Http\Controllers\Api\V1\Student\StudentDashboardController;
use App\Http\Controllers\Api\V1\Student\StudentExamTakingController;
use App\Http\Controllers\Api\V1\Student\StudentGamificationController;
use App\Http\Controllers\Api\V1\Student\StudentGroupScheduleController;
use App\Http\Controllers\Api\V1\Student\StudentHomeworkController;
use App\Http\Controllers\Api\V1\Student\StudentProfileController;
use App\Http\Controllers\Api\V1\Teacher\LessonWorkspaceController;
use App\Http\Controllers\Api\V1\Teacher\TeacherAnalyticsController;
use App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController;
use App\Http\Controllers\Api\V1\Teacher\TeacherBillingController;
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
use App\Http\Controllers\Api\V1\UserController;
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

            // Admin area
            Route::prefix('admin')->middleware('role:admin')->group(function () {
                Route::get('/users', [UserController::class, 'index']);
                Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');
                Route::delete('/users/{id}', [UserController::class, 'destroy'])->whereNumber('id');

                // Admin-controlled General Republic League
                Route::get('/leagues/republic', [AdminRepublicLeagueController::class, 'show']);
                Route::post('/leagues/republic/start', [AdminRepublicLeagueController::class, 'start']);
                Route::post('/leagues/republic/stop', [AdminRepublicLeagueController::class, 'stop']);
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
                });

                // Stage 3: Encrypted Payout & Direct Receiving Accounts
                Route::prefix('payout-accounts')->group(function () {
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

                // Stage 5: Recording Studio & Secure Media / Attachments (without AI)
                Route::prefix('lessons')->group(function () {
                    Route::get('/', [TeacherStudioController::class, 'index']);
                    Route::post('/', [TeacherStudioController::class, 'store']);
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

                // Stage 6: Lesson Workspace, Chapters, Transcripts & Concept Graphs (without AI)
                Route::prefix('workspace')->group(function () {
                    Route::get('/', [LessonWorkspaceController::class, 'index']);
                    Route::get('/lessons/{uuid}', [LessonWorkspaceController::class, 'show'])
                        ->whereUuid('uuid');
                    Route::put('/lessons/{uuid}/chapters', [LessonWorkspaceController::class, 'syncChapters'])
                        ->whereUuid('uuid');
                    Route::put('/lessons/{uuid}/transcript', [LessonWorkspaceController::class, 'syncTranscript'])
                        ->whereUuid('uuid');
                    Route::put('/lessons/{uuid}/concept-graph', [LessonWorkspaceController::class, 'saveConceptGraph'])
                        ->whereUuid('uuid');
                });

                // Stage 7 & 8: Study Groups, Schedule & Student Roster
                Route::prefix('groups')->group(function () {
                    Route::get('/', [TeacherStudyGroupController::class, 'index']);
                    Route::post('/', [TeacherStudyGroupController::class, 'store']);
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

                Route::prefix('schedule')->group(function () {
                    Route::get('/', [TeacherScheduleController::class, 'index']);
                    Route::post('/notes', [TeacherScheduleController::class, 'storeNote']);
                    Route::patch('/notes/{uuid}/toggle', [TeacherScheduleController::class, 'toggleNote'])
                        ->whereUuid('uuid');
                    Route::delete('/notes/{uuid}', [TeacherScheduleController::class, 'destroyNote'])
                        ->whereUuid('uuid');
                });

                // Stage 10: Question Bank & Bubble Sheet Exams
                Route::prefix('exams')->group(function () {
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

                // Stage 12: Homework Management & Grading Workflow
                Route::prefix('homeworks')->group(function () {
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

                // Stage 13: Course Leagues, XP & Leaderboards
                Route::prefix('leagues')->group(function () {
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
                Route::prefix('certificates')->group(function () {
                    Route::get('/', [TeacherCertificateController::class, 'index']);
                    Route::post('/', [TeacherCertificateController::class, 'store']);
                    Route::post('/bulk-issue', [TeacherCertificateController::class, 'bulkStore']);
                    Route::patch('/{uuid}/revoke', [TeacherCertificateController::class, 'revoke'])
                        ->whereUuid('uuid');
                });

                Route::prefix('financials')->group(function () {
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
