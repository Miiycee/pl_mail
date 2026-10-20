<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\ArrivalPlace;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\NewMailNotificationMessage;
use App\Repository\User\UserRepository;
use App\Service\Mail\MailNotificationSender;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Sends notifications off the sync path, after mail has already committed. */
#[AsMessageHandler]
final readonly class NewMailNotificationMessageHandler
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private MailNotificationSender $sender,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(NewMailNotificationMessage $job): void
    {
        $user = $this->users->find($job->userId);
        if (!$user instanceof User || true !== $user->getSetting('mail_notification.enabled', false)) {
            return;
        }

        $webhookReady = true === $user->getSetting('mail_notification.webhook_enabled', false)
            && '' !== trim((string) $user->getSetting('mail_notification.webhook_url', ''));
        $telegramReady = true === $user->getSetting('mail_notification.telegram_enabled', false)
            && '' !== (string) $user->getSetting('mail_notification.telegram_token', '')
            && '' !== trim((string) $user->getSetting('mail_notification.telegram_chat_id', ''));

        if (false === $webhookReady && false === $telegramReady) {
            return;
        }

        // Keep a bounded recent-ID ledger in the user's existing JSON settings.
        // Mark before the external request: if the queue retries after a worker
        // interruption, an already-attempted message is not sent twice. This is
        // intentionally at-most-once; remote HTTP delivery cannot be made
        // exactly-once without cooperation from the receiver.
        $processed = $user->getSetting('mail_notification.recent_message_ids', []);
        $processed = is_array($processed) ? array_map('intval', $processed) : [];
        $repository = $this->em->getRepository(Message::class);

        foreach (array_values(array_unique(array_map('intval', $job->messageIds))) as $id) {
            if (in_array($id, $processed, true)) {
                continue;
            }

            $message = $repository->find($id);
            if (!$message instanceof Message
                || $message->account->usr?->id !== $user->id
                || ArrivalPlace::Inbox !== $message->arrivedIn) {
                continue;
            }

            $processed[] = $id;
            $processed = array_slice(array_values(array_unique($processed)), -500);
            $user->setSetting('mail_notification.recent_message_ids', $processed);
            $this->em->flush();

            try {
                $this->sender->sendNewMail($user, $message);
            } catch (\Throwable $e) {
                // Notification errors must never re-run a successful mail sync.
                $this->logger->warning('New-mail notification processing failed', [
                    'userId' => $user->id, 'messageId' => $id, 'errorClass' => $e::class,
                ]);
            }
        }
    }
}
