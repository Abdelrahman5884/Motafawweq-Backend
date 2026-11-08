<?php

namespace App\Enums;

enum StageType: string
{
    case THANAWYA  = 'stage-thanawya';
    case SECONDARY = 'stage-secondary';
    case PREP      = 'stage-prep';
    case PRIMARY   = 'stage-primary';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * All allowed Egyptian curriculum grade names (Arabic) supported in TeacherSettingsView.
     *
     * @return list<string>
     */
    public static function allowedGradesAr(): array
    {
        return [
            'الصف الثالث الثانوي',
            'الصف الثاني الثانوي',
            'الصف الأول الثانوي',
            'الصف الثالث الإعدادي',
            'الصف الثاني الإعدادي',
            'الصف الأول الإعدادي',
            'الصف السادس الابتدائي',
            'الصف الخامس الابتدائي',
            'الصف الرابع الابتدائي',
        ];
    }

    /**
     * Structured 4-stage configuration matching TeacherSettingsView STAGES_CONFIG.
     */
    public static function catalog(): array
    {
        return [
            [
                'id'     => self::THANAWYA->value,
                'nameAr' => 'مرحلة الثانوية العامة',
                'nameEn' => 'Thanawya Amma Stage',
                'descAr' => 'الشهادة الرسمية والمؤهلة للجامعات ونظام البابل شيت الحديث',
                'descEn' => 'Official high school exit certification & university admission',
                'grades' => [
                    ['id' => 'g-sec3', 'nameAr' => 'الصف الثالث الثانوي', 'nameEn' => '3rd Secondary (Thanawya Amma)'],
                ],
            ],
            [
                'id'     => self::SECONDARY->value,
                'nameAr' => 'المرحلة الثانوية',
                'nameEn' => 'Secondary Stage',
                'descAr' => 'صفوف النقل وتأسيس المواد العلمية والأدبية',
                'descEn' => '1st & 2nd secondary transition and foundation curricula',
                'grades' => [
                    ['id' => 'g-sec2', 'nameAr' => 'الصف الثاني الثانوي', 'nameEn' => '2nd Secondary'],
                    ['id' => 'g-sec1', 'nameAr' => 'الصف الأول الثانوي', 'nameEn' => '1st Secondary'],
                ],
            ],
            [
                'id'     => self::PREP->value,
                'nameAr' => 'المرحلة الإعدادية',
                'nameEn' => 'Preparatory Stage',
                'descAr' => 'مناهج العلوم والرياضيات واللغات للإعدادية العامة والشهادة',
                'descEn' => 'General prep stage curriculum and certificate level',
                'grades' => [
                    ['id' => 'g-prep3', 'nameAr' => 'الصف الثالث الإعدادي', 'nameEn' => '3rd Preparatory'],
                    ['id' => 'g-prep2', 'nameAr' => 'الصف الثاني الإعدادي', 'nameEn' => '2nd Preparatory'],
                    ['id' => 'g-prep1', 'nameAr' => 'الصف الأول الإعدادي', 'nameEn' => '1st Preparatory'],
                ],
            ],
            [
                'id'     => self::PRIMARY->value,
                'nameAr' => 'المرحلة الابتدائية',
                'nameEn' => 'Primary Stage',
                'descAr' => 'التأسيس المبكر ومناهج الصفوف الابتدائية المطورة',
                'descEn' => 'Early education foundations and primary grades',
                'grades' => [
                    ['id' => 'g-pri6', 'nameAr' => 'الصف السادس الابتدائي', 'nameEn' => '6th Primary'],
                    ['id' => 'g-pri5', 'nameAr' => 'الصف الخامس الابتدائي', 'nameEn' => '5th Primary'],
                    ['id' => 'g-pri4', 'nameAr' => 'الصف الرابع الابتدائي', 'nameEn' => '4th Primary'],
                ],
            ],
        ];
    }
}

