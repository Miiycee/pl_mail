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
            $user = $message->account->usr;
            if (ArrivalPlace::Inbox !== $message->arrivedIn
                || null === $user?->id
                || true !== $user->getSetting('mail_notification.enabled', false)) {
                continue;
            }

            $webhookReady = true === $user->getSetting('mail_notification.webhook_enabled', false)
                && '' !== trim((string) $user->getSetting('mail_notification.webhook_url', ''));
            $telegramReady = true === $user->getSetting('mail_notification.telegram_enabled', false)
                && '' !== (string) $user->getSetting('mail_notification.telegram_token', '')
                && '' !== trim((string) $user->getSetting('mail_notification.telegram_chat_id', ''));

            if (false === $webhookReady && false === $telegramReady) {
                continue;
            }

            $userId = (int) $user->id;
            $byUser[$userId][] = (int) $message->id;
        }

        foreach ($byUser as $userId => $messageIds) {
            if ([] !== $messageIds) {
                $this->bus->dispatch(new NewMailNotificationMessage((int) $userId, $messageIds));
            }
        }
    }
}
