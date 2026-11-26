<?php

namespace App\Services\V1\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AiMicroserviceClient
{
    protected string $baseUrl;

    protected string $secret;

    protected int $connectTimeout;

    protected int $requestTimeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('ai.service_url', 'http://127.0.0.1:8001'), '/');
        $this->secret = (string) config('ai.internal_secret', '');
        $this->connectTimeout = max(2, (int) config('ai.connect_timeout', 5));
        $this->requestTimeout = max(15, (int) config('ai.request_timeout', 120));
    }

    protected function client(?int $timeout = null): PendingRequest
    {
        $req = Http::baseUrl($this->baseUrl)
            ->connectTimeout($this->connectTimeout)
            ->timeout($timeout ?? $this->requestTimeout)
            ->acceptJson();

        if ($this->secret !== '') {
            $req = $req->withHeaders([
                'X-AI-Internal-Secret' => $this->secret,
            ]);
        }

        return $req;
    }

    /**
     * Check AI microservice availability and loaded Whisper model status.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        try {
            $response = $this->client(5)->get('/health');
            if ($response->successful()) {
                return (array) $response->json();
            }
        } catch (\Throwable $e) {
            Log::warning('AI service health check failed', ['error' => $e->getMessage()]);
        }

        return ['status' => 'unreachable'];
    }

    /**
     * Stream a local audio/video file to the AI microservice and enqueue an async STT job.
     *
     * @return array<string, mixed>
     */
    public function startFileTranscriptionJob(
        string $localFilePath,
        string $originalFilename,
        ?string $language = null,
        ?string $speaker = null
    ): array {
        if (! is_file($localFilePath) || ! is_readable($localFilePath)) {
            throw new RuntimeException(__('messages.ai.media_file_unreadable'));
        }

        $stream = fopen($localFilePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException(__('messages.ai.media_file_unreadable'));
        }

        try {
            $fields = [];
            if ($language !== null && $language !== '' && strtolower($language) !== 'auto') {
                $fields['language'] = strtolower(trim($language));
            }
            if ($speaker !== null && trim($speaker) !== '') {
                $fields['speaker'] = trim($speaker);
            }

            $response = $this->client($this->requestTimeout)
                ->attach('file', $stream, $originalFilename ?: basename($localFilePath))
                ->post('/api/v1/stt/jobs', $fields);

            if (! $response->successful()) {
                $detail = (string) ($response->json('detail') ?? $response->body());
                Log::error('AI STT file job creation failed', [
                    'status' => $response->status(),
                    'detail' => mb_substr($detail, 0, 400),
                ]);

                throw new RuntimeException($detail !== '' ? $detail : __('messages.ai.service_error'));
            }

            return (array) $response->json();
        } catch (ConnectionException $e) {
            Log::error('AI microservice unreachable during STT file submission', [
                'url' => $this->baseUrl,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(__('messages.ai.service_unavailable'), 0, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Submit an HTTPS YouTube/Vimeo link to the AI microservice for async STT transcription.
     *
     * @return array<string, mixed>
     */
    public function startUrlTranscriptionJob(
        string $url,
        ?string $language = null,
        ?string $speaker = null
    ): array {
        try {
            $payload = [
                'url' => $url,
                'language' => ($language && strtolower($language) !== 'auto') ? strtolower(trim($language)) : null,
                'speaker' => ($speaker && trim($speaker) !== '') ? trim($speaker) : null,
            ];

            $response = $this->client(30)->post('/api/v1/stt/jobs/from-url', $payload);

            if (! $response->successful()) {
                $detail = (string) ($response->json('detail') ?? $response->body());
                Log::error('AI STT URL job creation failed', [
                    'status' => $response->status(),
                    'detail' => mb_substr($detail, 0, 400),
                ]);

                throw new RuntimeException($detail !== '' ? $detail : __('messages.ai.service_error'));
            }

            return (array) $response->json();
        } catch (ConnectionException $e) {
            Log::error('AI microservice unreachable during STT URL submission', [
                'url' => $this->baseUrl,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(__('messages.ai.service_unavailable'), 0, $e);
        }
    }

    /**
     * Poll an existing remote STT job for live progress and completed transcript segments.
     *
     * @return array<string, mixed>
     */
    public function getTranscriptionJob(string $remoteJobId): array
    {
        try {
            $response = $this->client(15)->get("/api/v1/stt/jobs/{$remoteJobId}");

            if ($response->status() === 404) {
                return [
                    'job_id' => $remoteJobId,
                    'status' => 'failed',
                    'stage' => 'expired',
                    'progress' => 0,
                    'error' => __('messages.ai.job_expired'),
                ];
            }

            if (! $response->successful()) {
                throw new RuntimeException(__('messages.ai.service_error'));
            }

            return (array) $response->json();
        } catch (ConnectionException $e) {
            throw new RuntimeException(__('messages.ai.service_unavailable'), 0, $e);
        }
    }

    /**
     * Best-effort cleanup of completed job payload in AI microservice memory.
     */
    public function deleteTranscriptionJob(string $remoteJobId): void
    {
        try {
            $this->client(5)->delete("/api/v1/stt/jobs/{$remoteJobId}");
        } catch (\Throwable) {
            // Non-fatal cleanup
        }
    }
}
