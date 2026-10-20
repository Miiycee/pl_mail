<?php

declare(strict_types=1);

namespace App\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Enum\Mail\ArrivalPlace;
use App\Domain\Interface\PostIngestStepInterface;
use App\Infrastructure\Messaging\Message\NewMailNotificationMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/** Queue only inbox arrivals from live syncs; never notify on initial import. */
final readonly class NewMailNotificationStep implements PostIngestStepInterface
{
    public function __construct(private MessageBusInterface $bus) {}

    public function afterCommit(PostIngestResult $result): void
    {
        if (false === $result->live) {
            return;
        }

        $byUser = [];
        foreach ($result->messages as $message) {
            if (ArrivalPlace::Inbox !== $message->arrivedIn || null === $message->account->usr?->id) {
                continue;
            }
            $userId = (int) $message->account->usr->id;
            $byUser[$userId][] = (int) $message->id;
        }

        foreach ($byUser as $userId => $messageIds) {
            if ([] !== $messageIds) {
                $this->bus->dispatch(new NewMailNotificationMessage((int) $userId, $messageIds));
            }
        }
    }
}
