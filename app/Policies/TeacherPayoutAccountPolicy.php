<?php

namespace App\Policies;

class TeacherPayoutAccountPolicy extends BaseTeacherPolicy
{
    // Inherits strict ownership enforcement (teacher_id === $user->id) and security audit logging from BaseTeacherPolicy.
}

