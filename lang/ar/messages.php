<?php

return [
    // General API responses
    'operation_success'   => 'تمت العملية بنجاح',
    'resource_created'    => 'تم إنشاء العنصر بنجاح',
    'general_error'       => 'حدث خطأ ما',
    'validation_failed'   => 'بيانات الإدخال غير صالحة، يرجى مراجعة الأخطاء',
    'unauthenticated'     => 'غير مصرح لك، يرجى تسجيل الدخول أولاً للوصول إلى هذا المورد.',
    'forbidden'           => 'ليس لديك الصلاحيات الكافية للقيام بهذا الإجراء.',
    'not_found'           => 'المورد المطلوب غير موجود.',
    'data_retrieved'      => 'تم استرجاع البيانات بنجاح',
    'too_many_requests'   => 'تم تجاوز الحد المسموح به من الطلبات. يرجى الانتظار قليلاً ثم المحاولة مجدداً.',
    'server_error'        => 'حدث خطأ غير متوقع في الخادم. يرجى المحاولة لاحقاً.',
    'invalid_token_type'  => 'نوع رمز الدخول غير صالح لهذا الطلب.',

    // Rate limiters
    'rate_limit_api'      => 'تم تجاوز معدل الطلبات المسموح به. يرجى الانتظار قليلاً.',
    'rate_limit_auth'     => 'محاولات كثيرة جداً. تم حظر الطلبات مؤقتاً لحماية الحساب. يرجى المحاولة بعد دقيقة.',
    'rate_limit_otp'      => 'محاولات كثيرة جداً على رمز التحقق. يرجى الانتظار ثم المحاولة مجدداً.',
    'rate_limit_refresh'  => 'طلبات تجديد كثيرة جداً. يرجى المحاولة لاحقاً.',

    // Auth Security Exceptions
    'invalid_credentials'   => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
    'account_locked'        => 'تم قفل الحساب مؤقتاً بسبب محاولات دخول فاشلة متكررة. حاول مرة أخرى بعد :minutes دقيقة.',
    'account_suspended'     => 'تم تعليق هذا الحساب. يرجى التواصل مع إدارة المنصة.',
    'account_pending'       => 'حسابك قيد المراجعة من إدارة المنصة. سيتم إشعارك عند التفعيل.',
    'email_not_verified'    => 'يجب تأكيد البريد الإلكتروني أولاً للوصول إلى هذا القسم.',
    'invalid_refresh_token' => 'جلسة الدخول غير صالحة أو منتهية. يرجى تسجيل الدخول مجدداً.',

    // Auth Controller & Services
    'register_success'        => 'تم إنشاء الحساب بنجاح. تم إرسال رمز التأكيد إلى بريدك الإلكتروني.',
    'login_success'           => 'تم تسجيل الدخول بنجاح',
    'refresh_success'         => 'تم تجديد الجلسة بنجاح',
    'profile_fetched'         => 'تم جلب بيانات الملف الشخصي بنجاح',
    'profile_updated'         => 'تم تحديث بيانات الملف الشخصي بنجاح',
    'password_changed'        => 'تم تغيير كلمة المرور بنجاح وتم تسجيل الخروج من باقي الأجهزة',
    'current_password_wrong'  => 'كلمة المرور الحالية غير صحيحة.',
    'logout_success'          => 'تم تسجيل الخروج بنجاح',
    'logout_all_success'      => 'تم تسجيل الخروج من جميع الأجهزة بنجاح',
    'email_verified_success'  => 'تم تأكيد البريد الإلكتروني بنجاح',
    'email_already_verified'  => 'البريد الإلكتروني مؤكد بالفعل.',
    'email_otp_resent'        => 'تم إرسال رمز تأكيد جديد إلى بريدك الإلكتروني',
    'email_not_found'         => 'هذا البريد الإلكتروني غير مسجل لدينا في المنصة.',
    'mail_delivery_failed'    => 'تعذر إرسال رمز التحقق عبر البريد الإلكتروني حالياً. يرجى المحاولة مرة أخرى.',
    'password_forgot_sent'    => 'تم إرسال رمز التحقق المكون من 6 أرقام إلى بريدك الإلكتروني.',
    'otp_verified_success'    => 'تم التحقق من صحة الرمز بنجاح. يمكنك الآن تعيين كلمة المرور الجديدة.',
    'password_reset_success'  => 'تم تعيين كلمة المرور الجديدة بنجاح. يرجى تسجيل الدخول.',
    'sessions_fetched'        => 'تم جلب الأجهزة النشطة',
    'session_not_found'       => 'الجلسة غير موجودة.',
    'session_revoked'         => 'تم تسجيل الخروج من الجهاز بنجاح',
    'user_deleted'            => 'تم حذف المستخدم بنجاح',
    'role_not_allowed'        => 'الدور المختار غير متاح للتسجيل.',
    'invalid_student_code'    => 'كود الطالب غير صحيح أو غير مسجل لأي طالب في المنصة.',

    // OTP Service
    'otp_cooldown'      => 'يرجى الانتظار :seconds ثانية قبل طلب رمز جديد.',
    'otp_invalid'       => 'الرمز غير صالح أو منتهي الصلاحية. اطلب رمزاً جديداً.',
    'otp_max_attempts'  => 'تم تجاوز عدد المحاولات المسموح بها لهذا الرمز. اطلب رمزاً جديداً.',
    'otp_wrong'         => 'الرمز غير صحيح. المحاولات المتبقية: :remaining.',

    // Role & Status Labels
    'roles' => [
        'admin'   => 'مدير النظام',
        'teacher' => 'معلم',
        'student' => 'طالب',
        'parent'  => 'ولي أمر',
        'center'  => 'إدارة سنتر تعليمي',
    ],
    'statuses' => [
        'active'    => 'نشط',
        'suspended' => 'معلق / محظور',
        'pending'   => 'قيد المراجعة',
    ],

    // Teacher Security & Plan Quotas
    'forbidden_resource_owner'       => 'غير مصرح لك بالوصول إلى هذا السجل لأنه يخص معلماً آخر.',
    'rate_limit_teacher_uploads'     => 'تم تجاوز الحد المسموح به لرفع الملفات في الدقيقة. يرجى الانتظار قليلاً.',
    'rate_limit_qr_scan'             => 'معدل مسح الباركود سريع جداً. يرجى الانتظار ثوانٍ قليلة.',
    'rate_limit_financial'           => 'تم إجراء عمليات مالية كثيرة في وقت قصير. يرجى الانتظار دقيقة لحماية حسابك.',
    'rate_limit_join_code'           => 'محاولات كثيرة لإدخال كود المجموعة. يرجى الانتظار دقيقة قبل المحاولة مجدداً.',
    'quota_courses_exceeded'         => 'لقد وصلت للحد الأقصى لعدد الكورسات النشطة (:max كورس) في :plan. يرجى ترقية باقتك لإضافة المزيد.',
    'quota_students_exceeded'        => 'لقد وصلت للحد الأقصى لسعة الطلاب (:max طالب) في :plan. يرجى ترقية باقتك لاستيعاب طلاب إضافيين.',
    'quota_certificates_not_allowed' => 'ميزة إصدار الشهادات المعتمدة برمز QR غير متاحة في :plan. يرجى الترقية إلى باقة بلس أو برو.',
    'quota_leagues_not_allowed'      => 'ميزة إطلاق دوري الكورس والتحديات التنافسية غير متاحة في :plan. يرجى الترقية إلى باقة بلس أو برو.',
    'quota_whatsapp_not_allowed'     => 'ميزة إرسال تقارير واتساب الفورية لأولياء الأمور متاحة حصرياً في باقة المعلم برو (Pro).',
    'teacher_plans' => [
        'free' => 'الباقة المجانية',
        'plus' => 'باقة المعلم بلس',
        'pro'  => 'باقة المعلم برو',
    ],

    // Stage 2: Teacher Profile, Stages & Preferences
    'teacher_settings_fetched'     => 'تم جلب إعدادات وملف المعلم بنجاح',
    'teacher_settings_updated'     => 'تم حفظ كافة تعديلات ملف المعلم وتحديث الإعدادات بنجاح',
    'teacher_stages_updated'       => 'تم تحديث المراحل والصفوف الدراسية المعتمدة بنجاح',
    'teacher_preferences_updated'  => 'تم تحديث تفضيلات المظهر واللغة والتنبيهات بنجاح',
    'teacher_avatar_updated'       => 'تم تحديث الصورة الشخصية المعتمدة بنجاح',
    'invalid_image_signature'      => 'الملف المرفوع ليس صورة صالحة أو أن صيغته غير مدعومة.',

    // Stage 3: Encrypted Payout Accounts
    'payout_accounts_fetched'        => 'تم جلب حسابات استلام المدفوعات بنجاح',
    'payout_account_created'         => 'تمت إضافة وسيلة استلام المدفوعات وتشفير بياناتها بنجاح',
    'payout_account_updated'         => 'تم تحديث حالة تفعيل حساب الاستلام بنجاح',
    'payout_account_default_updated' => 'تم تعيين حساب الاستلام الافتراضي بنجاح',
    'payout_account_deleted'         => 'تم حذف وسيلة استلام المدفوعات بنجاح',

    // Stage 4: Courses & Curriculum Management
    'courses_fetched' => 'تم جلب قائمة الكورسات والمقررات الدراسية بنجاح',
    'course_fetched'  => 'تم جلب بيانات المقرر الدراسي بنجاح',
    'course_created'  => 'تم إنشاء ونشر المقرر الدراسي بنجاح',
    'course_updated'  => 'تم حفظ تعديلات المقرر الدراسي بنجاح',
    'course_deleted'  => 'تم حذف المقرر الدراسي بنجاح',

    // Stage 5: Recording Studio & Secure Media / Attachments
    'lessons_fetched'              => 'تم جلب قائمة الحصص المسجلة بنجاح',
    'lesson_fetched'               => 'تم جلب بيانات الحصة والمرفقات بنجاح',
    'lesson_created'               => 'تم إنشاء الحصة وإعداد استوديو التسجيل بنجاح',
    'lesson_updated'               => 'تم تحديث بيانات الحصة بنجاح',
    'lesson_deleted'               => 'تم حذف الحصة بنجاح',
    'lesson_media_uploaded'        => 'تم رفع ملف الصوت أو الفيديو للحصة وتأمينه بنجاح',
    'lesson_pdf_uploaded'          => 'تم إرفاق مذكرة الـ PDF وتأمينها بنجاح',
    'lesson_image_uploaded'        => 'تم إرفاق صورة السبورة وحفظ ترتيبها بنجاح',
    'lesson_attachments_reordered' => 'تم تحديث الترتيب التسلسلي للمرفقات بنجاح',
    'lesson_attachment_deleted'    => 'تم حذف المرفق وإعادة ترتيب العناصر المتبقية بنجاح',
    'invalid_pdf_signature'        => 'الملف المرفوع ليس ملف PDF صالحاً أو أن بصمته الرقمية غير مطابقة.',
    'invalid_media_signature'      => 'الملف المرفوع ليس ملف صوت أو فيديو مدعوماً.',
    'invalid_embed_url'            => 'رابط الفيديو الخارجي يجب أن يبدأ ببروتوكول آمن (https://).',
    'invalid_signed_url'           => 'رابط التحميل أو المشاهدة غير صالح أو منتهي الصلاحية.',

    // Stage 6: Lesson Workspace, Chapters, Transcripts & Concept Graphs
    'workspace_overview_fetched'   => 'تم جلب نظرة عامة على مساحة الحصص والإحصائيات بنجاح',
    'lesson_workspace_fetched'     => 'تم جلب بيانات مساحة الحصة والفصول والتفريغ النصي وخريطة المفاهيم بنجاح',
    'lesson_chapters_updated'      => 'تم حفظ وتحديث فصول الحصة والتوقيتات الزمنية بنجاح',
    'lesson_transcript_updated'    => 'تم حفظ وتحديث التفريغ النصي المتزامن للحصة بنجاح',
    'lesson_concept_graph_updated' => 'تم حفظ وتحديث خريطة المفاهيم التفاعلية (NotebookLM) بنجاح',
    'concept_graph_depth_exceeded' => 'تجاوزت خريطة المفاهيم الحد الأقصى لعمق التفرع الشجري المسموح به.',
    'concept_graph_nodes_exceeded' => 'تجاوزت خريطة المفاهيم الحد الأقصى لعدد العقد المفاهيمية المسموح به.',

    // Stage 7: Study Groups, Centers & Weekly Schedule
    'groups_fetched'         => 'تم جلب قائمة المجموعات والقاعات الدراسية بنجاح',
    'group_fetched'          => 'تم جلب بيانات المجموعة الدراسية بنجاح',
    'group_created'          => 'تم إنشاء المجموعة وتوليد كود الانضمام والمواعيد بنجاح',
    'group_updated'          => 'تم حفظ تعديلات المجموعة والمواعيد بنجاح',
    'group_deleted'          => 'تم حذف المجموعة الدراسية بنجاح',
    'group_code_regenerated' => 'تم إبطال الكود القديم وتوليد كود انضمام جديد بنجاح',
    'schedule_fetched'       => 'تم جلب الجدول الأسبوعي والمواعيد والملاحظات بنجاح',
    'schedule_note_created'  => 'تمت إضافة الملاحظة بنجاح إلى جدول المواعيد',
    'schedule_note_toggled'  => 'تم تغيير حالة إنجاز الملاحظة بنجاح',
    'schedule_note_deleted'  => 'تم حذف الملاحظة بنجاح',

    // Stage 8: Student Roster, Pending Requests & Direct Invitations
    'roster_fetched'           => 'تم جلب سجل طلاب المجموعة وقوائم الانتظار بنجاح',
    'students_searched'        => 'تم البحث في قاعدة بيانات الطلاب بنجاح',
    'student_invited'          => 'تم إرسال دعوة الانضمام للطالب بنجاح',
    'student_accepted'         => 'تم قبول الطالب وتوليد الباركود وكود المرور بنجاح',
    'student_rejected'         => 'تم رفض طلب الانضمام بنجاح',
    'student_removed'          => 'تم إلغاء قيد الطالب من المجموعة بنجاح',
    'join_request_submitted'   => 'تم إرسال طلب انضمامك للمجموعة بنجاح وبانتظار موافقة المعلم',
    'student_already_enrolled' => 'هذا الطالب مقيد بالفعل في هذه المجموعة.',
    'student_already_pending'  => 'يوجد طلب انضمام معلق بالفعل لهذا الطالب.',
    'group_capacity_reached'   => 'اكتملت سعة المجموعة الدراسية (:max طالب). لا يمكن إضافة طلاب جدد.',
    'invalid_join_code'        => 'كود الانضمام غير صحيح أو أن المجموعة غير نشطة.',

    // Stage 9: Smart QR/Barcode Attendance
    'attendance_recorded_success' => 'تم تسجيل حضور الطالب بنجاح وتحديث نسبة الالتزام',
    'attendance_already_recorded' => 'تم تسجيل حضور هذا الطالب مسبقاً في هذه الحصة',
    'attendance_toggled_success'  => 'تم تحديث حالة حضور الطالب بنجاح',
    'attendance_sessions_fetched' => 'تم جلب سجل جلسات الحضور والغياب بنجاح',
    'attendance_session_fetched'  => 'تم جلب تفاصيل جلسة الحضور وقائمة الطلاب بنجاح',
    'attendance_student_not_found'=> 'لم يتم العثور على طالب مقيد في هذه المجموعة يطابق الباركود أو الكود المدخل.',

    // Stage 10: Question Bank & Bubble Sheet Exams
    'exams_fetched'                => 'تم جلب قائمة الامتحانات وبنك الأسئلة بنجاح',
    'exam_fetched'                 => 'تم جلب تفاصيل الامتحان والأسئلة بنجاح',
    'exam_created'                 => 'تم إنشاء الامتحان وبنك الأسئلة بنجاح',
    'exam_updated'                 => 'تم تحديث بيانات الامتحان والأسئلة بنجاح',
    'exam_deleted'                 => 'تم حذف الامتحان بنجاح',
    'exam_status_toggled'          => 'تم تغيير حالة إتاحة الامتحان للطلاب بنجاح',
    'exam_results_toggled'         => 'تم تغيير حالة إعلان النتائج ونماذج الإجابة للطلاب بنجاح',
    'exam_duplicated'              => 'تم تكرار الامتحان وأسئلته بنجاح كنسخة جديدة',
    'exam_questions_required'      => 'يجب إضافة سؤال واحد على الأقل داخل الامتحان.',
    'quota_question_bank_exceeded' => 'تجاوزت الحد المسموح به لعدد الأسئلة في باقتك الحالية (:max سؤال).',

    // Stage 11: Exam Submissions, Student Answers & Grading Workflow
    'exam_submissions_fetched'     => 'تم جلب قائمة إجابات وتسليمات الطلاب بنجاح',
    'exam_submission_fetched'      => 'تم جلب تفاصيل إجابة الطالب والأسئلة بنجاح',
    'exam_submitted_successfully'  => 'تم تسليم الامتحان وتصحيح الأسئلة الموضوعية بنجاح',
    'exam_submission_graded'       => 'تم رصد وتصحيح الإجابات المقالية وتحديث الدرجة النهائية بنجاح',
    'exam_closed_for_submission'   => 'عفواً، هذا الامتحان غير متاح للتسليم حالياً.',
    'exam_already_submitted'       => 'لقد قمت بتسليم هذا الامتحان مسبقاً ولا يسمح بإعادة التسليم.',
    'rate_limit_exam_submit'       => 'تجاوزت الحد المسموح لتسليم إجابات الامتحانات، يرجى الانتظار قليلاً.',

    // Stage 12: Homework Management & Grading Workflow
    'homeworks_fetched'                => 'تم جلب قائمة الواجبات بنجاح',
    'homework_fetched'                 => 'تم جلب تفاصيل الواجب بنجاح',
    'homework_created'                 => 'تم إنشاء الواجب وتعيينه للطلاب بنجاح',
    'homework_updated'                 => 'تم تحديث بيانات الواجب بنجاح',
    'homework_deleted'                 => 'تم حذف الواجب بنجاح',
    'homework_submissions_fetched'     => 'تم جلب قائمة تسليمات الطلاب للواجب بنجاح',
    'homework_submission_fetched'      => 'تم جلب تفاصيل حل الطالب للواجب بنجاح',
    'homework_submission_graded'       => 'تم رصد درجة الواجب والتعليق بنجاح',
    'homework_submitted_successfully'  => 'تم تسليم حل الواجب بنجاح',
    'homework_solution_required'       => 'يجب تقديم ملف الحل أو كتابة الإجابة النصية لتسليم الواجب.',
    'homework_already_graded'          => 'تم تصحيح هذا الواجب بالفعل ولا يمكن التعديل عليه.',
    'invalid_file_type'                => 'نوع الملف غير مدعوم، يسمح فقط بملفات PDF والصور JPG/PNG.',
    'file_not_found'                   => 'الملف المطلوب غير موجود أو تم حذفه.',
];
