<?php

return [
    // General API responses
    'operation_success'   => 'Operation completed successfully.',
    'resource_created'    => 'Resource created successfully.',
    'general_error'       => 'An error occurred.',
    'validation_failed'   => 'The given data was invalid. Please review the errors.',
    'unauthenticated'     => 'Unauthenticated. Please sign in first to access this resource.',
    'forbidden'           => 'You do not have permission to perform this action.',
    'not_found'           => 'The requested resource was not found.',
    'data_retrieved'      => 'Data retrieved successfully.',
    'too_many_requests'   => 'Too many requests. Please wait a moment and try again.',
    'server_error'        => 'An unexpected server error occurred. Please try again later.',
    'invalid_token_type'  => 'Invalid token type for this request.',

    // Rate limiters
    'rate_limit_api'      => 'Rate limit exceeded. Please wait a moment.',
    'rate_limit_auth'     => 'Too many attempts. Requests are temporarily blocked to protect your account. Please try again in a minute.',
    'rate_limit_otp'      => 'Too many verification attempts. Please wait before trying again.',
    'rate_limit_refresh'  => 'Too many token refresh requests. Please try again later.',

    // Auth Security Exceptions
    'invalid_credentials'   => 'Invalid email address or password.',
    'account_locked'        => 'Account is temporarily locked due to repeated failed login attempts. Try again in :minutes minute(s).',
    'account_suspended'     => 'This account has been suspended. Please contact platform support.',
    'account_pending'       => 'Your account is pending administrator review. You will be notified once activated.',
    'email_not_verified'    => 'Please verify your email address first to access this section.',
    'invalid_refresh_token' => 'Your session is invalid or has expired. Please sign in again.',

    // Auth Controller & Services
    'register_success'        => 'Account created successfully. A verification code has been sent to your email.',
    'login_success'           => 'Signed in successfully.',
    'refresh_success'         => 'Session refreshed successfully.',
    'profile_fetched'         => 'Profile retrieved successfully.',
    'profile_updated'         => 'Profile updated successfully.',
    'password_changed'        => 'Password changed successfully and all other devices have been signed out.',
    'current_password_wrong'  => 'The current password is incorrect.',
    'logout_success'          => 'Signed out successfully.',
    'logout_all_success'      => 'Signed out from all devices successfully.',
    'email_verified_success'  => 'Email address verified successfully.',
    'email_already_verified'  => 'Email address is already verified.',
    'email_otp_resent'        => 'A new verification code has been sent to your email.',
    'email_not_found'         => 'This email address is not registered on our platform.',
    'mail_delivery_failed'    => 'Unable to send the verification email right now. Please try again.',
    'password_forgot_sent'    => 'A 6-digit verification code has been sent to your email address.',
    'otp_verified_success'    => 'Verification code confirmed. You can now set your new password.',
    'password_reset_success'  => 'Your password has been reset successfully. Please sign in.',
    'sessions_fetched'        => 'Active sessions retrieved.',
    'session_not_found'       => 'Session not found.',
    'session_revoked'         => 'Device signed out successfully.',
    'user_deleted'            => 'User deleted successfully.',
    'role_not_allowed'        => 'The selected role is not available for registration.',
    'invalid_student_code'    => 'Invalid student code. No student was found with this code.',

    // OTP Service
    'otp_cooldown'      => 'Please wait :seconds seconds before requesting a new code.',
    'otp_invalid'       => 'The verification code is invalid or expired. Please request a new code.',
    'otp_max_attempts'  => 'Maximum verification attempts exceeded for this code. Please request a new code.',
    'otp_wrong'         => 'Incorrect verification code. Remaining attempts: :remaining.',

    // Role & Status Labels
    'roles' => [
        'admin'   => 'System Administrator',
        'teacher' => 'Teacher',
        'student' => 'Student',
        'parent'  => 'Parent',
        'center'  => 'Learning Center Admin',
    ],
    'statuses' => [
        'active'    => 'Active',
        'suspended' => 'Suspended',
        'pending'   => 'Pending Approval',
    ],

    // Teacher Security & Plan Quotas
    'forbidden_resource_owner'       => 'You do not have permission to access this resource as it belongs to another educator.',
    'rate_limit_teacher_uploads'     => 'Upload rate limit exceeded. Please wait a moment before uploading more files.',
    'rate_limit_qr_scan'             => 'QR scan rate exceeded. Please wait a few seconds.',
    'rate_limit_financial'           => 'Too many financial operations in a short period. Please wait a minute to protect your account.',
    'rate_limit_join_code'           => 'Too many group join code attempts. Please wait a minute before trying again.',
    'quota_courses_exceeded'         => 'You have reached the maximum active courses limit (:max) on your :plan. Please upgrade your plan to create more courses.',
    'quota_students_exceeded'        => 'You have reached the maximum student enrollment capacity (:max) on your :plan. Please upgrade your plan to add more students.',
    'quota_certificates_not_allowed' => 'Issuing QR-verified certificates is not available on the :plan. Please upgrade to Teacher Plus or Pro.',
    'quota_leagues_not_allowed'      => 'Launching competitive course leagues is not available on the :plan. Please upgrade to Teacher Plus or Pro.',
    'quota_whatsapp_not_allowed'     => 'Automated WhatsApp parent reports are exclusively available on the Teacher Pro plan.',
    'teacher_plans' => [
        'free' => 'Free Plan',
        'plus' => 'Teacher Plus',
        'pro'  => 'Teacher Pro',
    ],

    // Stage 2: Teacher Profile, Stages & Preferences
    'teacher_settings_fetched'     => 'Teacher profile and settings retrieved successfully.',
    'teacher_settings_updated'     => 'All teacher profile changes and settings updated successfully.',
    'teacher_stages_updated'       => 'Curriculum stages and grades updated successfully.',
    'teacher_preferences_updated'  => 'Appearance, language, and alert preferences updated successfully.',
    'teacher_avatar_updated'       => 'Verified educator portrait updated successfully.',
    'invalid_image_signature'      => 'The uploaded file is not a valid image or its signature is unsupported.',

    // Stage 3: Encrypted Payout Accounts
    'payout_accounts_fetched'        => 'Payout receiving accounts retrieved successfully.',
    'payout_account_created'         => 'Payout receiving account added and encrypted successfully.',
    'payout_account_updated'         => 'Payout account active status updated successfully.',
    'payout_account_default_updated' => 'Default payout receiving account updated successfully.',
    'payout_account_deleted'         => 'Payout receiving account deleted successfully.',

    // Stage 4: Courses & Curriculum Management
    'courses_fetched' => 'Courses retrieved successfully.',
    'course_fetched'  => 'Course details retrieved successfully.',
    'course_created'  => 'Course created and published successfully.',
    'course_updated'  => 'Course details updated successfully.',
    'course_deleted'  => 'Course deleted successfully.',

    // Stage 5: Recording Studio & Secure Media / Attachments
    'lessons_fetched'              => 'Recorded lessons retrieved successfully.',
    'lesson_fetched'               => 'Lesson details and attachments retrieved successfully.',
    'lesson_created'               => 'Studio lesson created successfully.',
    'lesson_updated'               => 'Lesson details updated successfully.',
    'lesson_deleted'               => 'Lesson deleted successfully.',
    'lesson_media_uploaded'        => 'Lesson audio/video media uploaded and secured successfully.',
    'lesson_pdf_uploaded'          => 'PDF booklet attached and secured successfully.',
    'lesson_image_uploaded'        => 'Whiteboard photo attached and ordered successfully.',
    'lesson_attachments_reordered' => 'Attachments sequential order updated successfully.',
    'lesson_attachment_deleted'    => 'Attachment deleted and remaining items re-indexed successfully.',
    'invalid_pdf_signature'        => 'The uploaded file is not a valid PDF document or its binary signature is invalid.',
    'invalid_media_signature'      => 'The uploaded file is not a supported audio or video stream.',
    'invalid_embed_url'            => 'External video embed URL must use a secure HTTPS connection.',
    'invalid_signed_url'           => 'The media stream or download link is invalid or has expired.',

    // Stage 6: Lesson Workspace, Chapters, Transcripts & Concept Graphs
    'workspace_overview_fetched'   => 'Lesson workspace overview and statistics retrieved successfully.',
    'lesson_workspace_fetched'     => 'Lesson workspace, chapters, transcript, and concept graph retrieved successfully.',
    'lesson_chapters_updated'      => 'Lesson timestamped chapters updated successfully.',
    'lesson_transcript_updated'    => 'Lesson synchronized transcript updated successfully.',
    'lesson_concept_graph_updated' => 'Interactive NotebookLM concept graph saved successfully.',
    'concept_graph_depth_exceeded' => 'The concept graph exceeded the maximum allowed tree nesting depth.',
    'concept_graph_nodes_exceeded' => 'The concept graph exceeded the maximum allowed total nodes.',

    // Stage 7: Study Groups, Centers & Weekly Schedule
    'groups_fetched'         => 'Study groups and halls retrieved successfully.',
    'group_fetched'          => 'Study group details retrieved successfully.',
    'group_created'          => 'Study group created with schedule slots and join code successfully.',
    'group_updated'          => 'Study group details and schedule slots updated successfully.',
    'group_deleted'          => 'Study group deleted successfully.',
    'group_code_regenerated' => 'Previous join code invalidated and new code generated successfully.',
    'schedule_fetched'       => 'Weekly timetable, sessions, and daily notes retrieved successfully.',
    'schedule_note_created'  => 'Daily schedule note added successfully.',
    'schedule_note_toggled'  => 'Schedule note completion status toggled successfully.',
    'schedule_note_deleted'  => 'Schedule note deleted successfully.',

    // Stage 8: Student Roster, Pending Requests & Direct Invitations
    'roster_fetched'           => 'Student roster and pending requests retrieved successfully.',
    'students_searched'        => 'Platform students searched successfully.',
    'student_invited'          => 'Student invitation sent successfully.',
    'student_accepted'         => 'Student accepted and barcode/passcode generated successfully.',
    'student_rejected'         => 'Join request rejected successfully.',
    'student_removed'          => 'Student removed from study group successfully.',
    'join_request_submitted'   => 'Join request submitted and awaiting teacher approval.',
    'student_already_enrolled' => 'This student is already enrolled in this study group.',
    'student_already_pending'  => 'A join request is already pending for this student.',
    'group_capacity_reached'   => 'Study group capacity of :max students has been reached.',
    'invalid_join_code'        => 'The provided join code is invalid or the group is inactive.',

    // Stage 9: Smart QR/Barcode Attendance
    'attendance_recorded_success' => 'Student attendance recorded and streak updated successfully.',
    'attendance_already_recorded' => 'Student attendance has already been recorded for this session.',
    'attendance_toggled_success'  => 'Student attendance status toggled successfully.',
    'attendance_sessions_fetched' => 'Attendance sessions history retrieved successfully.',
    'attendance_session_fetched'  => 'Attendance session details and roster retrieved successfully.',
    'attendance_student_not_found'=> 'No enrolled student found in this group matching the provided barcode or code.',

    // Stage 10: Question Bank & Bubble Sheet Exams
    'exams_fetched'                => 'Exams and question bank retrieved successfully.',
    'exam_fetched'                 => 'Exam details and questions retrieved successfully.',
    'exam_created'                 => 'Exam created with questions matrix successfully.',
    'exam_updated'                 => 'Exam details and questions updated successfully.',
    'exam_deleted'                 => 'Exam deleted successfully.',
    'exam_status_toggled'          => 'Exam availability status toggled successfully.',
    'exam_results_toggled'         => 'Exam results publication status toggled successfully.',
    'exam_duplicated'              => 'Exam cloned as a fresh copy successfully.',
    'exam_questions_required'      => 'At least one question is required to create an exam.',
    'quota_question_bank_exceeded' => 'You have exceeded the maximum question bank capacity for your plan (:max questions).',

    // Stage 11: Exam Submissions, Student Answers & Grading Workflow
    'exam_submissions_fetched'     => 'Exam submissions and student answers retrieved successfully.',
    'exam_submission_fetched'      => 'Student submission details retrieved successfully.',
    'exam_submitted_successfully'  => 'Exam submitted and objective questions graded successfully.',
    'exam_submission_graded'       => 'Essay questions graded and total score updated successfully.',
    'exam_closed_for_submission'   => 'This exam is currently closed for student submissions.',
    'exam_already_submitted'       => 'You have already submitted this exam.',
    'rate_limit_exam_submit'       => 'Too many exam submission attempts. Please wait a moment.',

    // Stage 12: Homework Management & Grading Workflow
    'homeworks_fetched'                => 'Homework assignments retrieved successfully.',
    'homework_fetched'                 => 'Homework details retrieved successfully.',
    'homework_created'                 => 'Homework assignment created and assigned successfully.',
    'homework_updated'                 => 'Homework assignment updated successfully.',
    'homework_deleted'                 => 'Homework assignment deleted successfully.',
    'homework_submissions_fetched'     => 'Homework submissions retrieved successfully.',
    'homework_submission_fetched'      => 'Homework submission details retrieved successfully.',
    'homework_submission_graded'       => 'Homework submission evaluated and score updated successfully.',
    'homework_submitted_successfully'  => 'Homework solution submitted successfully.',
    'homework_solution_required'       => 'Please provide a solution file or text answer to submit the homework.',
    'homework_already_graded'          => 'This homework submission has already been graded and cannot be modified.',
    'invalid_file_type'                => 'Unsupported file type. Only PDF and JPG/PNG images are allowed.',
    'file_not_found'                   => 'Requested file not found or has been deleted.',

    // Stage 13: Course Leagues, XP & Leaderboards
    'leagues_fetched'                  => 'Course leagues retrieved successfully.',
    'league_fetched'                   => 'League leaderboard and details retrieved successfully.',
    'league_created'                   => 'Course league created and season started successfully.',
    'league_deleted'                   => 'Course league deleted successfully.',
    'league_xp_awarded'                => 'Bonus XP awarded and leaderboard recalculated successfully.',
    'league_recalculated'              => 'Rankings, tiers, and movement indicators recalculated successfully.',
    'student_not_found'                => 'The specified student was not found.',
];
