<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class BaseJsonResource extends JsonResource
{
    /**
     * Disable data wrapping to keep response format flat and controlled by ApiResponse trait.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Helper to format a datetime consistently across all API resources.
     *
     * @param  \DateTimeInterface|string|null  $date
     */
    protected function formatDate(mixed $date): ?string
    {
        if (! $date) {
            return null;
        }

        if (is_string($date)) {
            $date = Carbon::parse($date);
        }

        return $date->toIso8601String();
    }
}
