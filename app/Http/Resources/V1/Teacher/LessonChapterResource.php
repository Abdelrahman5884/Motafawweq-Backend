<?php

namespace App\Http\Resources\V1\Teacher;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class LessonChapterResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->uuid,
            'title'        => $this->title_en ?? $this->title_ar,
            'titleAr'      => $this->title_ar,
            'titleEn'      => $this->title_en ?? $this->title_ar,
            'startSeconds' => (int) $this->start_seconds,
            'timestamp'    => $this->timestamp_label,
            'order'        => (int) $this->display_order,
        ];
    }
}

