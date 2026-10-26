<?php

namespace App\Services;

use App\Enums\Tone;

final readonly class EmailTemplate
{
    public function __construct(
        public string $subject,
        public string $body,
        public Tone $tone,
        public string $model,
        public int $aiLatencyMs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'body' => $this->body,
            'tone' => $this->tone->value,
            'meta' => [
                'model' => $this->model,
                'ai_latency_ms' => $this->aiLatencyMs,
            ],
        ];
    }
}
