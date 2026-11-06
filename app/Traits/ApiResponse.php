<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\Response;

trait ApiResponse
{
    /**
     * Send a standardized success JSON response.
     *
     * @param  mixed  $data
     * @param  string  $message
     * @param  int  $statusCode
     * @param  array  $meta
     * @return JsonResponse
     */
    public function successResponse(
        mixed $data = null,
        string $message = 'تمت العملية بنجاح',
        int $statusCode = Response::HTTP_OK,
        array $meta = []
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Send a standardized created JSON response (201).
     *
     * @param  mixed  $data
     * @param  string  $message
     * @return JsonResponse
     */
    public function createdResponse(
        mixed $data = null,
        string $message = 'تم إنشاء العنصر بنجاح'
    ): JsonResponse {
        return $this->successResponse($data, $message, Response::HTTP_CREATED);
    }

    /**
     * Send a standardized error JSON response.
     *
     * @param  string  $message
     * @param  int  $statusCode
     * @param  mixed  $errors
     * @return JsonResponse
     */
    public function errorResponse(
        string $message = 'حدث خطأ ما',
        int $statusCode = Response::HTTP_BAD_REQUEST,
        mixed $errors = null
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Validation error response (422).
     */
    public function validationErrorResponse(
        mixed $errors,
        string $message = 'بيانات الإدخال غير صالحة'
    ): JsonResponse {
        return $this->errorResponse($message, Response::HTTP_UNPROCESSABLE_ENTITY, $errors);
    }

    /**
     * Unauthorized response (401).
     */
    public function unauthorizedResponse(
        string $message = 'غير مصرح لك، يرجى تسجيل الدخول أولاً'
    ): JsonResponse {
        return $this->errorResponse($message, Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Forbidden response (403).
     */
    public function forbiddenResponse(
        string $message = 'ليس لديك الصلاحيات الكافية للوصول إلى هذا المورد'
    ): JsonResponse {
        return $this->errorResponse($message, Response::HTTP_FORBIDDEN);
    }

    /**
     * Not found response (404).
     */
    public function notFoundResponse(
        string $message = 'المورد المطلوب غير موجود'
    ): JsonResponse {
        return $this->errorResponse($message, Response::HTTP_NOT_FOUND);
    }

    /**
     * Standardized Paginated JSON response.
     */
    public function paginatedResponse(
        LengthAwarePaginator $paginator,
        ?string $resourceClass = null,
        string $message = 'تم استرجاع البيانات بنجاح'
    ): JsonResponse {
        $items = $resourceClass ? $resourceClass::collection($paginator->items()) : $paginator->items();

        return response()->json([
            'success' => true,
            'message' => $message,
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
