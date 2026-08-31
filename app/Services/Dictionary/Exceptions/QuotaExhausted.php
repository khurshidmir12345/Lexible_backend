<?php

namespace App\Services\Dictionary\Exceptions;

use RuntimeException;

/**
 * The provider's daily request allowance is gone.
 *
 * Distinct from an ordinary failure because there is nothing to retry today:
 * every further call in this run would fail the same way, and marking those
 * words "failed" would hide the ones that genuinely could not be translated.
 */
class QuotaExhausted extends RuntimeException
{
}
