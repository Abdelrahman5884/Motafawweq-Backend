<?php

namespace App\Http\Resources\V1\Teacher;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class LessonTranscriptResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->uuid,
            'speaker'      => $this->speaker ?? 'المعلم',
            'startSeconds' => (int) $this->start_seconds,
            'timestamp'    => $this->timestamp_label,
            'text'         => $this->text_en ?? $this->text_ar,
            'textAr'       => $this->text_ar,
            'textEn'       => $this->text_en ?? $this->text_ar,
            'order'        => (int) $this->display_order,
        ];
    }
}

