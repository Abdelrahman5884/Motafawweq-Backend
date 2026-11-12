<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Public\CertificateVerificationController;
use App\Http\Controllers\Api\V1\Storage\StorageController;
use App\Http\Controllers\Api\V1\Student\StudentExamTakingController;
use App\Http\Controllers\Api\V1\Student\StudentHomeworkController;
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
        'success'   => true,
        'message'   => 'Motafawweq Platform API is active and healthy.',
        'timestamp' => now()->toIso8601String(),
        'version'   => 'v1',
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

            // Student: join study group by join code or QR scan
            Route::post('/groups/join', [TeacherRosterController::class, 'studentJoin'])
                ->middleware('throttle:join-code');

            // Stage 11: Student exam taking & submission
            Route::post('/exams/{uuid}/submit', [StudentExamTakingController::class, 'submit'])
                ->whereUuid('uuid')
                ->middleware('throttle:exam-submit');

            // Stage 12: Student homework submission
            Route::post('/homeworks/{uuid}/submit', [StudentHomeworkController::class, 'submit'])
                ->whereUuid('uuid')
                ->middleware('throttle:teacher-uploads');
            Route::get('/homeworks/submissions/{submissionUuid}/attachment', [StudentHomeworkController::class, 'downloadSubmissionAttachment'])
                ->whereUuid('submissionUuid');

            // Admin area
            Route::prefix('admin')->middleware('role:admin')->group(function () {
                Route::get('/users', [UserController::class, 'index']);
                Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');
                Route::delete('/users/{id}', [UserController::class, 'destroy'])->whereNumber('id');
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

                    Route::get('/{uuid}/media/stream', [TeacherStudioController::class, 'streamMedia'])
                        ->whereUuid('uuid')
                        ->name('api.v1.teacher.lessons.media.stream');
                    Route::get('/{uuid}/attachments/{attachmentUuid}/stream', [TeacherStudioController::class, 'streamAttachment'])
                        ->whereUuid(['uuid', 'attachmentUuid'])
                        ->name('api.v1.teacher.lessons.attachments.stream');
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
});
