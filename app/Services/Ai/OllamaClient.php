<?php

namespace App\Services\Ai;

use App\Contracts\AiClient;
use App\Exceptions\AiServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Runs the prompt on a local model through Ollama's chat API. Free and offline,
 * but slower and less polished than a hosted model.
 */
final class OllamaClient implements AiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly float $timeout,
    ) {}

    public function structured(string $system, string $prompt, array $schema): AiResult
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->post('/api/chat', [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    // Ollama constrains the output to this JSON schema.
                    'format' => $schema,
                    'stream' => false,
                ]);
        } catch (ConnectionException $e) {
            // cURL error 28 is a timeout; anything else means Ollama is not running.
            throw str_contains($e->getMessage(), 'cURL error 28')
                ? AiServiceException::timeout($e)
                : AiServiceException::unavailable($e);
        }

        if ($response->notFound()) {
            throw new AiServiceException(
                "Ollama model [{$this->model}] is not installed. Run: ollama pull {$this->model}",
                'ai_model_not_found',
                502,
            );
        }

        if ($response->failed()) {
            throw AiServiceException::upstream(new RuntimeException('Ollama: '.($response->json('error') ?? $response->body())));
        }

        if ($response->json('done_reason') === 'length') {
            throw AiServiceException::invalidResponse('The model response was cut off.');
        }

        $data = json_decode((string) $response->json('message.content'), true);

        if (! is_array($data)) {
            throw AiServiceException::invalidResponse('The model did not return valid JSON.');
        }

        return new AiResult(
            data: $data,
            model: (string) ($response->json('model') ?? $this->model),
            inputTokens: (int) $response->json('prompt_eval_count'),
            outputTokens: (int) $response->json('eval_count'),
        );
    }
}
