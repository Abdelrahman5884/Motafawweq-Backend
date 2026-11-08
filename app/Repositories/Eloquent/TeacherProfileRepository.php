<?php

namespace App\Repositories\Eloquent;

use App\Models\TeacherProfile;
use App\Models\User;
use App\Repositories\Contracts\TeacherProfileRepositoryInterface;

class TeacherProfileRepository extends BaseRepository implements TeacherProfileRepositoryInterface
{
    public function __construct(TeacherProfile $model)
    {
        parent::__construct($model);
    }

    public function findByUserId(int $userId): ?TeacherProfile
    {
        return $this->model->with('user')->where('user_id', $userId)->first();
    }

    public function firstOrCreateForTeacher(User $teacher, array $defaults = []): TeacherProfile
    {
        $profile = $this->findByUserId($teacher->id);
        if ($profile) {
            return $profile;
        }

        $created = $this->model->create(array_merge([
            'user_id'                      => $teacher->id,
            'name_ar'                      => $teacher->name,
            'name_en'                      => $teacher->name,
            'title_ar'                     => 'معلم خبير ومعتمد بمنصة متفوّق',
            'title_en'                     => 'Verified Motafawweq Educator',
            'subject_ar'                   => 'الأحياء والجيولوجيا',
            'subject_en'                   => 'Biology',
            'bio_ar'                       => 'معلم متخصص في تدريس المناهج الحديثة ونظام البابل شيت.',
            'whatsapp_phone'               => $teacher->phone,
            'stages_taught'                => [
                'الصف الثالث الثانوي',
                'الصف الثاني الثانوي',
                'الصف الأول الثانوي',
                'الصف الثالث الإعدادي',
            ],
            'two_factor_enabled'           => true,
            'notify_whatsapp_intervention' => true,
            'notify_new_enrollment'        => true,
            'notify_exam_submission'       => true,
            'auto_watermark_enabled'       => true,
            'preferred_theme'              => 'light',
            'preferred_lang'               => 'ar',
        ], $defaults));

        return $created->load('user');
    }
}

