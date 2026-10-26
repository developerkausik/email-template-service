<?php

namespace App\Enums;

enum Tone: string
{
    case Formal = 'formal';
    case Friendly = 'friendly';
    case Apologetic = 'apologetic';
    case Persuasive = 'persuasive';
    case Neutral = 'neutral';

    /**
     * Style guide inserted into the prompt, so each tone means the same thing every time.
     */
    public function guide(): string
    {
        return match ($this) {
            self::Formal => 'Polite and professional. Full sentences, no contractions, no slang, no exclamation marks.',
            self::Friendly => 'Warm and conversational, like a helpful person rather than a company. Contractions are fine; stay professional.',
            self::Apologetic => 'Acknowledge the problem plainly, take responsibility without making excuses, and state what happens next.',
            self::Persuasive => 'Lead with the benefit to the reader, give one concrete reason to act, and end with a clear call to action. No pressure tactics.',
            self::Neutral => 'Clear, plain and matter-of-fact. Inform without emotion or sales language.',
        };
    }
}
