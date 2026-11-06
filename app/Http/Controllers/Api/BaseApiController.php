<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Traits\HasSecurityAudit;

abstract class BaseApiController extends Controller
{
    use ApiResponse, HasSecurityAudit;

    /**
     * Get the authenticated user with strong typing.
     *
     * @return \App\Models\User|null
     */
    protected function authenticatedUser(): ?\App\Models\User
    {
        return request()->user();
    }
}
