<?php

namespace App\Policies;

class TeacherProfilePolicy extends BaseTeacherPolicy
{
    // Inherits strict ownership check (user_id === $user->id) and admin override from BaseTeacherPolicy.
}

