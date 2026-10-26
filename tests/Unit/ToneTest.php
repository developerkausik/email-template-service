<?php

namespace Tests\Unit;

use App\Enums\Tone;
use App\Prompts\EmailPrompt;
use PHPUnit\Framework\TestCase;

class ToneTest extends TestCase
{
    public function test_every_tone_has_a_style_guide_in_the_prompt(): void
    {
        foreach (Tone::cases() as $tone) {
            $prompt = EmailPrompt::user('Welcome a new customer', 'Alex', $tone);

            $this->assertNotSame('', $tone->guide());
            $this->assertStringContainsString("<tone name=\"{$tone->value}\">", $prompt);
            $this->assertStringContainsString($tone->guide(), $prompt);
        }
    }
}
