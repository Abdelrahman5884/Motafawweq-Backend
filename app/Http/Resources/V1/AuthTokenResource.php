<?php

namespace App\Http\Resources\V1;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

/**
 * Wraps the token pair returned by TokenService::issuePair().
 */
class AuthTokenResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'access_token'       => $this->resource['access_token'],
            'refresh_token'      => $this->resource['refresh_token'],
            'token_type'         => $this->resource['token_type'],
            'expires_in'         => $this->resource['expires_in'],
            'refresh_expires_in' => $this->resource['refresh_expires_in'],
        ];
    }
}

