<?php

namespace App\Mail\Transports;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stringable;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Native Brevo (Sendinblue) v3 HTTP API Transport (`POST https://api.brevo.com/v3/smtp/email`).
 */
class BrevoApiTransport extends AbstractTransport implements Stringable
{
    public const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    public static ?string $lastError = null;

    public function __construct(
        protected string $apiKey
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        self::$lastError = null;

        if (empty($this->apiKey)) {
            self::$lastError = 'BREVO_API_KEY is not configured.';
            throw new TransportException(self::$lastError);
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $from = $email->getFrom()[0] ?? new Address(config('mail.from.address'), config('mail.from.name'));

        $to = array_map(fn (Address $addr) => array_filter([
            'email' => $addr->getAddress(),
            'name'  => $addr->getName() ?: null,
        ]), $email->getTo());

        $htmlBody = (string) $email->getHtmlBody();
        $textBody = (string) $email->getTextBody();
        if ($textBody === '' && $htmlBody !== '') {
            $textBody = trim(preg_replace('/\s+/', ' ', strip_tags($htmlBody)));
        }

        $payload = array_filter([
            'sender' => array_filter([
                'email' => $from->getAddress(),
                'name'  => $from->getName() ?: config('app.name', 'Motafawweq'),
            ]),
            'to'          => array_values($to),
            'subject'     => $email->getSubject(),
            'htmlContent' => $htmlBody !== '' ? $htmlBody : nl2br($textBody),
            'textContent' => $textBody,
            'headers'     => [
                'X-Entity-Ref-ID' => (string) Str::uuid(),
            ],
        ]);

        $response = Http::withHeaders([
            'api-key'      => $this->apiKey,
            'accept'       => 'application/json',
            'content-type' => 'application/json',
        ])->timeout(15)->post(self::ENDPOINT, $payload);

        if ($response->failed()) {
            $errMsg = 'Brevo API error (' . $response->status() . '): ' . ($response->json('message') ?? $response->body());
            self::$lastError = $errMsg;

            Log::warning('[BREVO-API-MAIL-FAILED]', [
                'status' => $response->status(),
                'body'   => $response->json() ?? $response->body(),
            ]);

            throw new TransportException($errMsg);
        }
    }

    public function __toString(): string
    {
        return 'brevo+api';
    }
}

