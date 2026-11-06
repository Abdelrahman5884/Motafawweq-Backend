<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with platform demo accounts.
     */
    public function run(): void
    {
        $accounts = [
            [
                'name'         => 'عمر طارق القاضي',
                'email'        => 'omar.tarek@motafawweq.me',
                'phone'        => '01123456789',
                'role'         => UserRole::STUDENT->value,
                'student_code' => 'OMAR-2026',
                'avatar'       => 'https://images.unsplash.com/photo-1539571696357-5a69c17a67c6?w=150&auto=format&fit=crop&q=80',
            ],
            [
                'name'   => 'د. سلمى السيد',
                'email'  => 'salma.biology@motafawweq.me',
                'phone'  => '01012345678',
                'role'   => UserRole::TEACHER->value,
                'avatar' => 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=150&auto=format&fit=crop&q=80',
            ],
            [
                'name'   => 'م. طارق القاضي',
                'email'  => 'tarek.kady@gmail.com',
                'phone'  => '01223344556',
                'role'   => UserRole::PARENT->value,
                'avatar' => 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=150&auto=format&fit=crop&q=80',
            ],
            [
                'name'   => 'أكاديمية الرواد التعليمية',
                'email'  => 'admin@alrowad.edu.eg',
                'phone'  => '01009876543',
                'role'   => UserRole::CENTER->value,
                'avatar' => 'https://images.unsplash.com/photo-1562774053-701939374585?w=150&auto=format&fit=crop&q=80',
            ],
            [
                'name'   => 'مدير منصة متفوّق',
                'email'  => 'hq@motafawweq.me',
                'phone'  => '01000000000',
                'role'   => UserRole::ADMIN->value,
                'avatar' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80',
            ],
        ];

        User::unguarded(function () use ($accounts) {
            foreach ($accounts as $account) {
                User::query()->updateOrCreate(
                    ['email' => $account['email']],
                    [
                        'name'                => $account['name'],
                        'phone'               => $account['phone'],
                        'avatar'              => $account['avatar'],
                        'password'            => 'Motafawweq@2026',
                        'role'                => $account['role'],
                        'student_code'        => $account['student_code'] ?? null,
                        'status'              => UserStatus::ACTIVE->value,
                        'email_verified_at'   => now(),
                        'password_changed_at' => now(),
                    ]
                );
            }

            $omar = User::query()->where('email', 'omar.tarek@motafawweq.me')->first();
            $tarek = User::query()->where('email', 'tarek.kady@gmail.com')->first();
            if ($omar && $tarek) {
                $tarek->children()->syncWithoutDetaching([$omar->id]);
            }
        });
    }
}
