<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\Response;

trait ApiResponse
{
    public function successResponse(
        mixed $data = null,
        ?string $message = null,
        int $statusCode = Response::HTTP_OK,
        array $meta = []
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message ?? __('messages.operation_success'),
            'data'    => $data,
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $statusCode);
    }

    public function createdResponse(
        mixed $data = null,
        ?string $message = null
    ): JsonResponse {
        return $this->successResponse($data, $message ?? __('messages.resource_created'), Response::HTTP_CREATED);
    }

    public function errorResponse(
        ?string $message = null,
        int $statusCode = Response::HTTP_BAD_REQUEST,
        mixed $errors = null
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => $message ?? __('messages.general_error'),
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }

    public function validationErrorResponse(
        mixed $errors,
        ?string $message = null
    ): JsonResponse {
        return $this->errorResponse($message ?? __('messages.validation_failed'), Response::HTTP_UNPROCESSABLE_ENTITY, $errors);
    }

    public function unauthorizedResponse(
        ?string $message = null
    ): JsonResponse {
        return $this->errorResponse($message ?? __('messages.unauthenticated'), Response::HTTP_UNAUTHORIZED);
    }

    public function forbiddenResponse(
        ?string $message = null
    ): JsonResponse {
        return $this->errorResponse($message ?? __('messages.forbidden'), Response::HTTP_FORBIDDEN);
    }

    public function notFoundResponse(
        ?string $message = null
    ): JsonResponse {
        return $this->errorResponse($message ?? __('messages.not_found'), Response::HTTP_NOT_FOUND);
    }

    public function paginatedResponse(
        LengthAwarePaginator $paginator,
        ?string $resourceClass = null,
        ?string $message = null
    ): JsonResponse {
        $items = $resourceClass ? $resourceClass::collection($paginator->items()) : $paginator->items();

        return response()->json([
            'success' => true,
            'message' => $message ?? __('messages.data_retrieved'),
            'data'    => $items,
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'has_more'     => $paginator->hasMorePages(),
            ],
        ], Response::HTTP_OK);
    }
}
