<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/** A post-commit notification job; IDs keep the Messenger envelope small. */
final readonly class NewMailNotificationMessage
{
    /** @param list<int> $messageIds */
    public function __construct(
        public int $userId,
        public array $messageIds,
    ) {}
}
