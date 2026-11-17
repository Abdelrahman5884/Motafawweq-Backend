<?php

namespace App\Services\V1\Student;

use App\Models\Lesson;
use App\Models\StudentConvertedLecture;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;

class StudentConvertedLectureService extends BaseService
{
    private const ALLOWED_OUTPUTS = ['graph', 'transcript', 'topics', 'quiz'];

    private const ALLOWED_MIME_PREFIXES = ['audio/', 'video/', 'application/pdf'];

    public function __construct(
        protected StudentAccessService $accessService
    ) {}

    public function list(User $student): array
    {
        return StudentConvertedLecture::forStudent($student->id)
            ->with('lesson:id,uuid')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (StudentConvertedLecture $l) => $this->format($l, false))
            ->values()
            ->all();
    }

    public function show(User $student, string $uuid): array
    {
        return $this->format($this->findOwned($student, $uuid), true);
    }

    /**
     * Register a lecture conversion request. File bytes are validated (MIME) but never stored
     * publicly; only metadata and the generated outputs are persisted for the owning student.
     */
    public function create(User $student, array $data, ?UploadedFile $file = null): array
    {
        $outputs = array_values(array_intersect(
            self::ALLOWED_OUTPUTS,
            array_map('strval', (array) ($data['outputs'] ?? ['graph', 'transcript', 'topics']))
        ));
        if (empty($outputs)) {
            $outputs = ['transcript'];
        }

        $fileName = null;
        $fileSize = null;
        if ($file) {
            $mime = (string) $file->getMimeType();
            $allowed = false;
            foreach (self::ALLOWED_MIME_PREFIXES as $prefix) {
                if (str_starts_with($mime, $prefix)) {
                    $allowed = true;
                    break;
                }
            }
            if (! $allowed) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'file' => ['نوع الملف غير مدعوم. يرجى رفع ملف صوت أو فيديو أو PDF.'],
                ]);
            }
            $fileName = mb_substr(preg_replace('/[^\pL\pN._\- ]/u', '', $file->getClientOriginalName()) ?: 'lecture', 0, 200);
            $fileSize = round($file->getSize() / (1024 * 1024), 1).' MB';
        } elseif (! empty($data['file_name'])) {
            $fileName = mb_substr(trim(strip_tags((string) $data['file_name'])), 0, 200);
            $fileSize = isset($data['file_size']) ? mb_substr(trim(strip_tags((string) $data['file_size'])), 0, 60) : null;
        }

        $lessonId = null;
        if (! empty($data['lesson_uuid'])) {
            $lesson = Lesson::where('uuid', $data['lesson_uuid'])->first();
            if ($lesson) {
                $this->accessService->assertCanAccess($student, $lesson, (int) $lesson->teacher_id, (int) $lesson->course_id);
                $lessonId = $lesson->id;
            }
        }

        $title = trim(strip_tags((string) ($data['title_ar'] ?? '')));
        if ($title === '') {
            $title = $fileName ? preg_replace('/\.[^.]+$/', '', $fileName) : 'محاضرة محولة';
        }

        $lecture = StudentConvertedLecture::create([
            'student_id' => $student->id,
            'lesson_id' => $lessonId,
            'title_ar' => mb_substr($title, 0, 255),
            'subject_ar' => isset($data['subject_ar']) ? mb_substr(trim(strip_tags((string) $data['subject_ar'])), 0, 120) : null,
            'grade_ar' => isset($data['grade_ar']) ? mb_substr(trim(strip_tags((string) $data['grade_ar'])), 0, 120) : null,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'duration_label' => null,
            'summary_ar' => 'تم استلام المحاضرة وتسجيل طلب التحويل. ستظهر المخرجات (التفريغ، المحاور، الخريطة الذهنية، الكويز) فور انتهاء المعالجة.',
            'outputs' => $outputs,
            'transcript' => [],
            'topics' => [],
            'mindmap' => null,
            'quiz_questions' => [],
            'questions_count' => 0,
            'concepts_count' => 0,
        ]);

        $this->logSecurityEvent('student.lecture.converted', ['lecture_uuid' => $lecture->uuid], 'info', $lecture);

        return $this->format($lecture->fresh('lesson'), true);
    }

    public function delete(User $student, string $uuid): bool
    {
        $lecture = $this->findOwned($student, $uuid);
        $lecture->delete();

        return true;
    }

    protected function findOwned(User $student, string $uuid): StudentConvertedLecture
    {
        /** @var StudentConvertedLecture|null $lecture */
        $lecture = StudentConvertedLecture::where('uuid', $uuid)->with('lesson:id,uuid')->first();
        if (! $lecture) {
            throw (new ModelNotFoundException)->setModel(StudentConvertedLecture::class, [$uuid]);
        }
        $lecture->assertOwnedByStudent($student);

        return $lecture;
    }

    protected function format(StudentConvertedLecture $l, bool $withContent): array
    {
        $base = [
            'id' => $l->uuid,
            'lessonId' => $l->lesson?->uuid,
            'titleAr' => $l->title_ar,
            'subjectAr' => $l->subject_ar,
            'gradeAr' => $l->grade_ar,
            'fileName' => $l->file_name,
            'fileSize' => $l->file_size,
            'duration' => $l->duration_label,
            'summaryAr' => $l->summary_ar,
            'outputs' => $l->outputs ?? [],
            'questionsCount' => (int) $l->questions_count,
            'conceptsCount' => (int) $l->concepts_count,
            'keyTopics' => collect($l->topics ?? [])->pluck('titleAr')->filter()->take(5)->values()->all(),
            'createdAt' => $l->created_at?->toIso8601String(),
            'dateAr' => $l->created_at?->format('Y-m-d'),
        ];

        if ($withContent) {
            $base['transcript'] = $l->transcript ?? [];
            $base['topics'] = $l->topics ?? [];
            $base['mindmap'] = $l->mindmap;
            $base['quizQuestions'] = $l->quiz_questions ?? [];
        }

        return $base;
    }
}
