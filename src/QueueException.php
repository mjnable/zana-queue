<?php

declare(strict_types=1);

namespace Zana\Queue;

/**
 * A job cannot be queued, read back or retried.
 */
class QueueException extends \RuntimeException
{
}
