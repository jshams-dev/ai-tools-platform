<?php

declare(strict_types=1);

namespace App\AI;

/**
 * What a piece of AI work *is*, independent of who performs it.
 *
 * Capabilities are the axis along which this layer grows. Adding video
 * generation means adding a case here, a request class, and an interface —
 * and changing nothing that already exists.
 */
enum Capability: string
{
    case TextGeneration = 'text_generation';
    case Translation = 'translation';

    /**
     * Deliberately not implemented yet. They are listed because the model
     * config and the database already carry capability strings, and it is
     * cheaper to reserve the vocabulary now than to migrate it later.
     */
    case ImageGeneration = 'image_generation';
    case VideoGeneration = 'video_generation';
    case Transcription = 'transcription';
    case VisionAnalysis = 'vision_analysis';

    /**
     * Whether a provider class exists that can serve this capability today.
     *
     * Keeps config validation honest: a model may advertise `video_generation`
     * in config/ai.php long before GoogleProvider learns to do it, and the
     * registry should say so clearly rather than fail at call time.
     */
    public function isImplemented(): bool
    {
        return match ($this) {
            self::TextGeneration, self::Translation => true,
            default => false,
        };
    }
}
