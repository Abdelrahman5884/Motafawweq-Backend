<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Student\JoinGroupByCodeRequest;
use App\Http\Requests\V1\Teacher\InviteStudentRequest;
use App\Http\Resources\V1\Teacher\EnrolledStudentResource;
use App\Http\Resources\V1\Teacher\PendingStudentResource;
use App\Http\Resources\V1\Teacher\StudentSearchResultResource;
use App\Http\Resources\V1\Teacher\StudyGroupResource;
use App\Services\V1\Teacher\TeacherRosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherRosterController extends BaseApiController
{
    public function __construct(
        protected TeacherRosterService $rosterService
    ) {}

    /**
     * GET /api/v1/teacher/groups/{groupUuid}/roster
     * Retrieve the enrolled and pending students roster for a group.
     */
    public function show(Request $request, string $groupUuid): JsonResponse
    {
        $roster = $this->rosterService->getRoster(
            $this->authenticatedUser(),
            $groupUuid,
            $request->query('q') ?? $request->query('search')
        );

        return $this->successResponse(
            [
                'group'    => new StudyGroupResource($roster['group']),
                'enrolled' => EnrolledStudentResource::collection($roster['enrolled'])->resolve($request),
                'pending'  => PendingStudentResource::collection($roster['pending'])->resolve($request),
                'stats'    => $roster['stats'],
            ],
            __('messages.roster_fetched')
        );
    }

    /**
     * GET /api/v1/teacher/students/search?q=...
     * Search registered platform students to invite to a group.
     */
    public function search(Request $request): JsonResponse
    {
        $students = $this->rosterService->searchStudents(
            $this->authenticatedUser(),
            (string) ($request->query('q') ?? $request->query('search') ?? ''),
            (int) ($request->query('limit') ?? 20)
        );

        return $this->successResponse(
            StudentSearchResultResource::collection($students),
            __('messages.students_searched')
        );
    }

    /**
     * POST /api/v1/teacher/groups/{groupUuid}/invite
     * Invite a student directly to the study group.
     */
    public function invite(InviteStudentRequest $request, string $groupUuid): JsonResponse
    {
        $enrollment = $this->rosterService->inviteStudent(
            $this->authenticatedUser(),
            $groupUuid,
            $request->validated()
        );

        return $this->createdResponse(
            new PendingStudentResource($enrollment),
            __('messages.student_invited')
        );
    }

    /**
     * POST /api/v1/teacher/groups/{groupUuid}/enrollments/{enrollmentUuid}/accept
     * Accept a pending student and generate barcode + passcode.
     */
    public function accept(string $groupUuid, string $enrollmentUuid): JsonResponse
    {
        $enrollment = $this->rosterService->acceptStudent(
            $this->authenticatedUser(),
            $groupUuid,
            $enrollmentUuid
        );

        return $this->successResponse(
            new EnrolledStudentResource($enrollment),
            __('messages.student_accepted')
        );
    }

    /**
     * POST /api/v1/teacher/groups/{groupUuid}/enrollments/{enrollmentUuid}/reject
     * Reject a pending student request.
     */
    public function reject(string $groupUuid, string $enrollmentUuid): JsonResponse
    {
        $enrollment = $this->rosterService->rejectStudent(
            $this->authenticatedUser(),
            $groupUuid,
            $enrollmentUuid
        );

        return $this->successResponse(
            new PendingStudentResource($enrollment),
            __('messages.student_rejected')
        );
    }

    /**
     * DELETE /api/v1/teacher/groups/{groupUuid}/enrollments/{enrollmentUuid}
     * Remove an enrolled student from the study group.
     */
    public function destroy(string $groupUuid, string $enrollmentUuid): JsonResponse
    {
        $this->rosterService->removeStudent(
            $this->authenticatedUser(),
            $groupUuid,
            $enrollmentUuid
        );

        return $this->successResponse(
            null,
            __('messages.student_removed')
        );
    }

    /**
     * POST /api/v1/groups/join
     * Endpoint for students to join a group via join code or QR scan.
     */
    public function studentJoin(JoinGroupByCodeRequest $request): JsonResponse
    {
        $enrollment = $this->rosterService->studentJoinByCode(
            $this->authenticatedUser(),
            $request->validated('join_code'),
            $request->validated()
        );

        return $this->createdResponse(
            new PendingStudentResource($enrollment),
            __('messages.join_request_submitted')
        );
    }
}

