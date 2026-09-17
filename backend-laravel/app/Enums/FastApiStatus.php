<?php

namespace App\Enums;

/**
 * Explicit states for the internal FastAPI (AI/CV) service integration.
 *
 * The AI service is never assumed to be healthy and its failure is never
 * silently treated as a successful verification result. Laravel keeps the
 * authority to decide what an AI fact — or an AI failure — means.
 */
enum FastApiStatus: string
{
    case Available = 'available';

    case Unavailable = 'unavailable';

    case Timeout = 'timeout';

    case InvalidResponse = 'invalid_response';

    case Conflict = 'conflict';
}
