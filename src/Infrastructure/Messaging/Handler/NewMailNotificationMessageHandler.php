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

        $repository = $this->em->getRepository(Message::class);
        foreach (array_values(array_unique($job->messageIds)) as $id) {
            $message = $repository->find($id);
            if (!$message instanceof Message
                || $message->account->usr?->id !== $user->id
                || ArrivalPlace::Inbox !== $message->arrivedIn) {
                continue;
            }

            try {
                $this->sender->sendNewMail($user, $message);
            } catch (\Throwable $e) {
                // Notification errors must never re-run a successful mail sync.
                $this->logger->warning('New-mail notification processing failed', [
                    'userId' => $user->id, 'messageId' => $id, 'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
