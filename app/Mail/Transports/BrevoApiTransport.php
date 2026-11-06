<?php

namespace App\Mail\Transports;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function __construct(
        protected string $apiKey
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if (empty($this->apiKey)) {
            throw new TransportException('BREVO_API_KEY is not configured.');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $from = $email->getFrom()[0] ?? new Address(config('mail.from.address'), config('mail.from.name'));

        $to = array_map(fn (Address $addr) => array_filter([
            'email' => $addr->getAddress(),
            'name'  => $addr->getName() ?: null,
        ]), $email->getTo());

        $payload = array_filter([
            'sender' => array_filter([
                'email' => $from->getAddress(),
                'name'  => $from->getName() ?: config('app.name', 'Motafawweq'),
            ]),
            'to'          => array_values($to),
            'subject'     => $email->getSubject(),
            'htmlContent' => $email->getHtmlBody() ?: nl2br((string) $email->getTextBody()),
            'textContent' => $email->getTextBody() ?: strip_tags((string) $email->getHtmlBody()),
        ]);

        $response = Http::withHeaders([
            'api-key'      => $this->apiKey,
            'accept'       => 'application/json',
            'content-type' => 'application/json',
        ])->timeout(15)->post(self::ENDPOINT, $payload);

        if ($response->failed()) {
            Log::warning('[BREVO-API-MAIL-FAILED]', [
                'status' => $response->status(),
                'body'   => $response->json() ?? $response->body(),
            ]);

            throw new TransportException(
                'Brevo API error (' . $response->status() . '): ' . ($response->json('message') ?? $response->body())
            );
        }
    }

    public function __toString(): string
    {
        return 'brevo+api';
    }
}

