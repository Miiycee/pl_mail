<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use App\Infrastructure\Encryption\Encryptor;
use App\Service\Mail\MailNotificationSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Per-user Webhook and Telegram settings; credentials are encrypted at rest. */
#[Route('/settings/mail-notifications', name: 'app_settings_mail_notifications_')]
#[IsGranted('ROLE_USER')]
final class MailNotificationController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request, EntityManagerInterface $em, Encryptor $encryptor): Response
    {
        $this->assertCsrf($request, 'mail_notification_settings');
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $webhookUrl = trim((string) $request->request->get('webhook_url', ''));
        if ('' !== $webhookUrl) {
            $parts = parse_url($webhookUrl);
            if (false === $parts || !isset($parts['scheme'], $parts['host'])
                || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass'])) {
                $this->addFlash('error', $this->trans('mail_notification.invalid_url'));
                return $this->redirectToRoute('app_settings_index', ['section' => 'notifications']);
            }
        }

        $token = trim((string) $request->request->get('telegram_token', ''));
        if ('' !== $token) {
            $user->setSetting('mail_notification.telegram_token', $encryptor->encrypt($token));
        }

        $user->setSetting('mail_notification.enabled', '1' === (string) $request->request->get('enabled'));
        $user->setSetting('mail_notification.webhook_enabled', '1' === (string) $request->request->get('webhook_enabled'));
        $user->setSetting('mail_notification.webhook_url', $webhookUrl);
        $user->setSetting('mail_notification.telegram_enabled', '1' === (string) $request->request->get('telegram_enabled'));
        $user->setSetting('mail_notification.telegram_chat_id', trim((string) $request->request->get('telegram_chat_id', '')));

        $em->flush();
        $this->addFlash('success', $this->trans('mail_notification.saved'));

        return $this->redirectToRoute('app_settings_index', ['section' => 'notifications']);
    }

    #[Route('/test', name: 'test', methods: ['POST'])]
    public function test(Request $request, MailNotificationSender $sender): Response
    {
        $this->assertCsrf($request, 'mail_notification_settings');
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $results = $sender->sendTest($user);
        if ([] === $results) {
            $this->addFlash('error', $this->trans('mail_notification.none_enabled'));
        } else {
            $failed = in_array('failed', $results, true) || in_array('not_configured', $results, true);
            $this->addFlash($failed ? 'error' : 'success', $this->trans('mail_notification.test_result', ['%results%' => implode('；', array_map(
                static fn (string $channel, string $result): string => $channel . ' = ' . $result,
                array_keys($results), array_values($results),
            ))]));
        }

        return $this->redirectToRoute('app_settings_index', ['section' => 'notifications']);
    }
}
