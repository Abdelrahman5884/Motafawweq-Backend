<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use App\Traits\HasSecurityAudit;

abstract class BaseApiController extends Controller
{
    use ApiResponse, HasSecurityAudit;

    /**
     * Get the authenticated user with strong typing.
     */
    protected function authenticatedUser(): ?User
    {
        return request()->user();
    }
}
