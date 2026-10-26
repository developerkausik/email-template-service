<?php

namespace App\Services;

use App\Contracts\AiClient;
use App\Enums\Tone;
use App\Exceptions\AiServiceException;
use App\Prompts\EmailPrompt;
use Illuminate\Support\Facades\Log;

class EmailTemplateService
{
    public function __construct(private readonly AiClient $ai) {}

    public function generate(string $purpose, string $recipientName, Tone $tone): EmailTemplate
    {
        $startedAt = hrtime(true);

        try {
            $result = $this->ai->structured(
                EmailPrompt::system(),
                EmailPrompt::user($purpose, $recipientName, $tone),
                EmailPrompt::schema(),
            );
        } catch (AiServiceException $e) {
            Log::channel('ai')->warning('ai.call', [
                'status' => 'error',
                'error' => $e->errorCode,
                'tone' => $tone->value,
                'latency_ms' => $this->elapsedMs($startedAt),
            ]);

            throw $e;
        }

        $latencyMs = $this->elapsedMs($startedAt);

        Log::channel('ai')->info('ai.call', [
            'status' => 'ok',
            'model' => $result->model,
            'tone' => $tone->value,
            'latency_ms' => $latencyMs,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
        ]);

        $subject = trim((string) ($result->data['subject'] ?? ''));
        $body = trim((string) ($result->data['body'] ?? ''));

        if ($subject === '' || $body === '') {
            throw AiServiceException::invalidResponse('The model returned an empty subject or body.');
        }

        return new EmailTemplate($subject, $body, $tone, $result->model, $latencyMs);
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
