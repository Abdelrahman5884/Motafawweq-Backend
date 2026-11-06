<?php

return [
    'required'    => 'حقل :attribute مطلوب.',
    'required_if' => 'حقل :attribute مطلوب عند التسجيل كـ :value.',
    'string'      => 'حقل :attribute يجب أن يكون نصاً.',
    'email'     => 'صيغة البريد الإلكتروني غير صحيحة.',
    'unique'    => 'قيمة :attribute مستخدمة بالفعل.',
    'confirmed' => 'تأكيد :attribute غير متطابق.',
    'different' => 'يجب أن يكون :attribute مختلفاً عن :other.',
    'digits'    => 'حقل :attribute يجب أن يتكون من :digits أرقام.',
    'in'        => 'القيمة المختارة في :attribute غير متاحة.',
    'regex'     => 'صيغة :attribute غير صالحة.',
    'min' => [
        'string' => 'يجب ألا يقل :attribute عن :min أحرف.',
    ],
    'max' => [
        'string' => 'يجب ألا يزيد :attribute عن :max حرفاً.',
    ],
    'password' => [
        'letters'       => 'يجب أن تحتوي :attribute على حرف واحد على الأقل.',
        'mixed'         => 'يجب أن تحتوي :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers'       => 'يجب أن تحتوي :attribute على رقم واحد على الأقل.',
        'symbols'       => 'يجب أن تحتوي :attribute على رمز خاص واحد على الأقل (مثل @ أو ! أو #).',
        'uncompromised' => ':attribute المدخلة ظهرت في تسريب بيانات سابق، يرجى اختيار كلمة مرور أخرى أكثر أماناً.',
    ],

    'custom' => [
        'name' => [
            'regex' => 'الاسم يجب أن يحتوي على حروف فقط.',
        ],
        'email' => [
            'unique' => 'هذا البريد الإلكتروني مستخدم بالفعل.',
        ],
        'phone' => [
            'unique' => 'رقم الهاتف مسجل مسبقاً.',
            'regex'  => 'رقم الهاتف غير صالح.',
        ],
        'role' => [
            'in' => 'الدور المختار غير متاح للتسجيل.',
        ],
        'code' => [
            'required' => 'رمز التحقق مطلوب.',
            'digits'   => 'رمز التحقق يجب أن يتكون من :digits أرقام.',
        ],
        'student_code' => [
            'required'    => 'كود الطالب مطلوب للتسجيل كولي أمر.',
            'required_if' => 'كود الطالب مطلوب للتسجيل كولي أمر.',
        ],
    ],

    'attributes' => [
        'name'                  => 'الاسم',
        'email'                 => 'البريد الإلكتروني',
        'password'              => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'current_password'      => 'كلمة المرور الحالية',
        'new_password'          => 'كلمة المرور الجديدة',
        'phone'                 => 'رقم الهاتف',
        'role'                  => 'الدور',
        'avatar'                => 'الصورة الشخصية',
        'code'                  => 'رمز التحقق',
        'student_code'          => 'كود الطالب',
    ],
];
