<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Admin\AdminMinistryCurriculumService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMinistryCurriculumController extends BaseApiController
{
    public function __construct(
        protected AdminMinistryCurriculumService $ministryCurriculumService
    ) {}

    /**
     * GET /api/v1/admin/ministry-curricula
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->ministryCurriculumService->listCurricula([
            'search' => $request->query('search'),
            'stage' => $request->query('stage'),
            'grade' => $request->query('grade'),
            'status' => $request->query('status'),
            'term' => $request->query('term'),
            'per_page' => $request->query('per_page', 12),
        ]);

        return $this->successResponse(
            $result['items'],
            __('messages.admin.ministry_curricula_fetched'),
            200,
            array_merge($result['meta'], [
                'summary' => $result['summary'],
                'gradeCatalog' => $result['gradeCatalog'],
            ])
        );
    }

    /**
     * GET /api/v1/admin/ministry-curricula/{uuid}
     */
    public function show(string $uuid): JsonResponse
    {
        $curriculum = $this->ministryCurriculumService->getCurriculum($uuid);

        return $this->successResponse($curriculum, __('messages.admin.ministry_curriculum_fetched'));
    }

    /**
     * POST /api/v1/admin/ministry-curricula
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->validationRules());

        $created = $this->ministryCurriculumService->createCurriculum(
            $this->authenticatedUser(),
            $validated
        );

        return $this->createdResponse($created, __('messages.admin.ministry_curriculum_created'));
    }

    /**
     * PUT /api/v1/admin/ministry-curricula/{uuid}
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate($this->validationRules(true));

        $updated = $this->ministryCurriculumService->updateCurriculum(
            $this->authenticatedUser(),
            $uuid,
            $validated
        );

        return $this->successResponse($updated, __('messages.admin.ministry_curriculum_updated'));
    }

    /**
     * POST /api/v1/admin/ministry-curricula/{uuid}/units
     */
    public function storeUnit(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'titleAr' => ['required', 'string', 'max:220'],
            'titleEn' => ['nullable', 'string', 'max:220'],
            'summaryAr' => ['nullable', 'string', 'max:6000'],
            'topics' => ['nullable'],
        ]);

        $updated = $this->ministryCurriculumService->addUnit(
            $this->authenticatedUser(),
            $uuid,
            $validated
        );

        return $this->createdResponse($updated, __('messages.admin.ministry_curriculum_updated'));
    }

    /**
     * PUT /api/v1/admin/ministry-curricula/{uuid}/units/{unitIndex}
     */
    public function updateUnit(Request $request, string $uuid, int $unitIndex): JsonResponse
    {
        $validated = $request->validate([
            'titleAr' => ['sometimes', 'required', 'string', 'max:220'],
            'titleEn' => ['nullable', 'string', 'max:220'],
            'summaryAr' => ['nullable', 'string', 'max:6000'],
            'topics' => ['nullable'],
        ]);

        $updated = $this->ministryCurriculumService->updateUnit(
            $this->authenticatedUser(),
            $uuid,
            $unitIndex,
            $validated
        );

        return $this->successResponse($updated, __('messages.admin.ministry_curriculum_updated'));
    }

    /**
     * DELETE /api/v1/admin/ministry-curricula/{uuid}/units/{unitIndex}
     */
    public function destroyUnit(string $uuid, int $unitIndex): JsonResponse
    {
        $updated = $this->ministryCurriculumService->deleteUnit(
            $this->authenticatedUser(),
            $uuid,
            $unitIndex
        );

        return $this->successResponse($updated, __('messages.admin.ministry_curriculum_updated'));
    }

    /**
     * POST /api/v1/admin/ministry-curricula/{uuid}/lessons
     */
    public function storeLesson(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate($this->lessonValidationRules());

        $updated = $this->ministryCurriculumService->addLesson(
            $this->authenticatedUser(),
            $uuid,
            $validated
        );

        return $this->createdResponse($updated, 'تم إضافة الحصة إلى المقرر بنجاح');
    }

    /**
     * PUT /api/v1/admin/ministry-curricula/{uuid}/units/{unitIndex}/lessons/{lessonId}
     */
    public function updateLesson(Request $request, string $uuid, int $unitIndex, string $lessonId): JsonResponse
    {
        $validated = $request->validate($this->lessonValidationRules(true));

        $updated = $this->ministryCurriculumService->updateLesson(
            $this->authenticatedUser(),
            $uuid,
            $unitIndex,
            $lessonId,
            $validated
        );

        return $this->successResponse($updated, 'تم حفظ تعديلات الحصة بنجاح');
    }

    /**
     * DELETE /api/v1/admin/ministry-curricula/{uuid}/units/{unitIndex}/lessons/{lessonId}
     */
    public function destroyLesson(string $uuid, int $unitIndex, string $lessonId): JsonResponse
    {
        $updated = $this->ministryCurriculumService->deleteLesson(
            $this->authenticatedUser(),
            $uuid,
            $unitIndex,
            $lessonId
        );

        return $this->successResponse($updated, 'تم حذف الحصة بنجاح');
    }

    /**
     * PATCH /api/v1/admin/ministry-curricula/{uuid}/units/{unitIndex}/lessons/reorder
     */
    public function reorderLessons(Request $request, string $uuid, int $unitIndex): JsonResponse
    {
        $validated = $request->validate([
            'lessonIds' => ['required', 'array', 'max:60'],
            'lessonIds.*' => ['string', 'max:60'],
        ]);

        $updated = $this->ministryCurriculumService->reorderLessons(
            $this->authenticatedUser(),
            $uuid,
            $unitIndex,
            $validated['lessonIds']
        );

        return $this->successResponse($updated, 'تم تحديث ترتيب الحصص بنجاح');
    }

    /**
     * PATCH /api/v1/admin/ministry-curricula/{uuid}/toggle-publish
     */
    public function togglePublish(string $uuid): JsonResponse
    {
        $updated = $this->ministryCurriculumService->togglePublish(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse($updated, __('messages.admin.ministry_curriculum_publish_toggled'));
    }

    /**
     * DELETE /api/v1/admin/ministry-curricula/{uuid}
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->ministryCurriculumService->deleteCurriculum(
            $this->authenticatedUser(),
            $uuid,
            $validated['reason'] ?? null
        );

        return $this->successResponse(null, __('messages.admin.ministry_curriculum_deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function lessonValidationRules(bool $isUpdate = false): array
    {
        $req = $isUpdate ? 'sometimes' : 'required';

        return [
            'unitIndex' => ['nullable', 'integer', 'min:0', 'max:39'],
            'unitTitleAr' => ['nullable', 'string', 'max:220'],
            'titleAr' => [$req, 'string', 'max:220'],
            'youtubeUrl' => ['nullable', 'string', 'max:500'],
            'durationMinutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'contentAr' => ['nullable', 'string', 'max:8000'],
            'attachments' => ['nullable', 'array', 'max:15'],
            'attachments.*.id' => ['nullable', 'string', 'max:40'],
            'attachments.*.titleAr' => ['nullable', 'string', 'max:200'],
            'attachments.*.type' => ['nullable', 'string', 'in:pdf,summary,worksheet,link'],
            'attachments.*.url' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function validationRules(bool $isUpdate = false): array
    {
        $req = $isUpdate ? 'sometimes' : 'required';

        return [
            'code' => ['nullable', 'string', 'max:80'],
            'titleAr' => [$req, 'string', 'max:255'],
            'titleEn' => ['nullable', 'string', 'max:255'],
            'subjectAr' => [$req, 'string', 'max:120'],
            'subjectEn' => ['nullable', 'string', 'max:120'],
            'gradeAr' => [$req, 'string', 'max:100'],
            'gradeSubtitleAr' => ['nullable', 'string', 'max:180'],
            'gradeSubtitleEn' => ['nullable', 'string', 'max:180'],
            'term' => ['nullable', 'string', 'in:full_year,first,second'],
            'tracks' => ['nullable', 'array', 'max:10'],
            'tracks.*' => ['string', 'max:60'],
            'descriptionAr' => ['nullable', 'string', 'max:4000'],
            'descriptionEn' => ['nullable', 'string', 'max:4000'],
            'textbookUrl' => ['nullable', 'string', 'max:500'],
            'examCode' => ['nullable', 'string', 'max:80'],
            'isPublished' => ['nullable', 'boolean'],
            'sortOrder' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'units' => ['nullable', 'array', 'max:40'],
            'units.*.titleAr' => ['required', 'string', 'max:220'],
            'units.*.titleEn' => ['nullable', 'string', 'max:220'],
            'units.*.summaryAr' => ['nullable', 'string', 'max:6000'],
            'units.*.chaptersCount' => ['nullable', 'integer', 'min:1', 'max:60'],
            'units.*.topics' => ['nullable'],
            'units.*.lessons' => ['nullable', 'array', 'max:60'],
            'units.*.videos' => ['nullable', 'array', 'max:30'],
            'units.*.videos.*.id' => ['nullable', 'string', 'max:40'],
            'units.*.videos.*.titleAr' => ['nullable', 'string', 'max:220'],
            'units.*.videos.*.youtubeUrl' => ['nullable', 'string', 'max:500'],
            'units.*.videos.*.durationMinutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'units.*.videos.*.notesAr' => ['nullable', 'string', 'max:2000'],
            'units.*.resources' => ['nullable', 'array', 'max:20'],
            'units.*.resources.*.id' => ['nullable', 'string', 'max:40'],
            'units.*.resources.*.titleAr' => ['nullable', 'string', 'max:200'],
            'units.*.resources.*.type' => ['nullable', 'string', 'in:pdf,summary,worksheet,link'],
            'units.*.resources.*.url' => ['nullable', 'string', 'max:500'],
            'units.*.resources.*.contentAr' => ['nullable', 'string', 'max:6000'],
        ];
    }
}
