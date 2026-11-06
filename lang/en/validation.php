<?php

return [
    'required'    => 'The :attribute field is required.',
    'required_if' => 'The :attribute field is required when registering as :value.',
    'string'      => 'The :attribute field must be a string.',
    'email'     => 'Please provide a valid email address.',
    'unique'    => 'This :attribute is already taken.',
    'confirmed' => 'The :attribute confirmation does not match.',
    'different' => 'The :attribute and :other must be different.',
    'digits'    => 'The :attribute must be :digits digits.',
    'in'        => 'The selected :attribute is invalid.',
    'regex'     => 'The :attribute format is invalid.',
    'min' => [
        'string' => 'The :attribute must be at least :min characters.',
    ],
    'max' => [
        'string' => 'The :attribute must not exceed :max characters.',
    ],
    'password' => [
        'letters'       => 'The :attribute must contain at least one letter.',
        'mixed'         => 'The :attribute must contain at least one uppercase and one lowercase letter.',
        'numbers'       => 'The :attribute must contain at least one number.',
        'symbols'       => 'The :attribute must contain at least one special symbol (e.g. @, !, #).',
        'uncompromised' => 'The given :attribute has appeared in a data leak. Please choose a different :attribute.',
    ],

    'custom' => [
        'name' => [
            'regex' => 'The name may only contain letters and spaces.',
        ],
        'email' => [
            'unique' => 'This email address is already registered.',
        ],
        'phone' => [
            'unique' => 'This phone number is already registered.',
            'regex'  => 'Please enter a valid phone number.',
        ],
        'role' => [
            'in' => 'The selected role is not available for registration.',
        ],
        'code' => [
            'required' => 'The verification code is required.',
            'digits'   => 'The verification code must be :digits digits.',
        ],
        'student_code' => [
            'required'    => 'The student code is required to register as a parent.',
            'required_if' => 'The student code is required to register as a parent.',
        ],
    ],

    'attributes' => [
        'name'                  => 'full name',
        'email'                 => 'email address',
        'password'              => 'password',
        'password_confirmation' => 'password confirmation',
        'current_password'      => 'current password',
        'new_password'          => 'new password',
        'phone'                 => 'phone number',
        'role'                  => 'role',
        'avatar'                => 'avatar',
        'code'                  => 'verification code',
        'student_code'          => 'student code',
    ],
];
