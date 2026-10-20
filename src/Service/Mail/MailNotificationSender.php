<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Encryption\Encryptor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\UnicodeString;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Delivers optional per-user new-mail notifications.
 *
 * Webhook URLs go through the same private-network-aware client used by
 * integrations. To reach a private/LAN Message Nest host, an administrator
 * must allow-list its hostname in INTEGRATIONS_ALLOWED_HOSTS.
 */
final readonly class MailNotificationSender
{
    public function __construct(
        #[Autowire(service: 'app.http_client.integration')]
        private HttpClientInterface $webhookClient,
        private HttpClientInterface $httpClient,
        private Encryptor $encryptor,
        private LoggerInterface $logger,
    ) {}

    /** Send a harmless sample event through whichever transports are enabled. */
    public function sendTest(User $user): array
    {
        $sample = [
            'event' => 'mail.received',
            'test' => true,
            'account' => (string) ($user->email ?? 'plMail'),
            'from' => 'plMail 测试通知 <noreply@example.invalid>',
            'subject' => '这是一条测试通知',
            'received_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'snippet' => '如果你能看到这条消息，通知配置已基本连通。',
        ];

        return $this->deliver($user, $sample, true);
    }

    public function sendNewMail(User $user, Message $message): array
    {
        $from = trim(implode(' ', array_filter([$message->fromName, $message->fromAddress])));
        $body = trim((string) ($message->bodyText ?? ''));
        if ('' === $body && null !== $message->bodyHtmlSafe) {
            $body = trim(strip_tags($message->bodyHtmlSafe));
        }
        $snippet = preg_replace('/\s+/u', ' ', $body) ?? $body;
        $unicodeSnippet = new UnicodeString($snippet);
        if ($unicodeSnippet->length() > 500) {
            $snippet = $unicodeSnippet->slice(0, 497)->append('…')->toString();
        }

        return $this->deliver($user, [
            'event' => 'mail.received',
            'account' => (string) ($message->account->email ?? $message->account->name ?? ''),
            'account_id' => (int) $message->account->id,
            'message_id' => (int) $message->id,
            'from' => '' !== $from ? $from : (string) ($message->fromAddress ?? '未知发件人'),
            'from_address' => (string) ($message->fromAddress ?? ''),
            'from_name' => (string) ($message->fromName ?? ''),
            'subject' => (string) ($message->subject ?? '(无主题)'),
            'received_at' => ($message->receivedAt ?? new \DateTimeImmutable())->format(DATE_ATOM),
            'snippet' => $snippet,
        ], false);
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,string> transport => result
     */
    private function deliver(User $user, array $event, bool $test): array
    {
        $results = [];
        $webhookEnabled = true === $user->getSetting('mail_notification.webhook_enabled', false);
        $storedWebhookUrl = trim((string) $user->getSetting('mail_notification.webhook_url', ''));

        if ($webhookEnabled && '' !== $storedWebhookUrl) {
            try {
                $webhookUrl = $this->encryptor->isEncrypted($storedWebhookUrl)
                    ? $this->encryptor->decrypt($storedWebhookUrl)
                    : $storedWebhookUrl;
                $response = $this->webhookClient->request('POST', $webhookUrl, [
                    'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                    'json' => $event,
                    'timeout' => 8,
                    'max_redirects' => 0,
                ]);
                $status = $response->getStatusCode();
                $results['webhook'] = $status >= 200 && $status < 300 ? 'ok' : 'failed';
                if ($status < 200 || $status >= 300) {
                    $this->logger->warning('Mail notification webhook returned a non-success status', [
                        'userId' => $user->id, 'status' => $status,
                    ]);
                }
            } catch (\Throwable $e) {
                $results['webhook'] = 'failed';
                $this->logger->warning('Mail notification webhook failed', [
                    'userId' => $user->id, 'errorClass' => $e::class,
                ]);
            }
        } elseif ($webhookEnabled) {
            $results['webhook'] = 'not_configured';
        }

        $telegramEnabled = true === $user->getSetting('mail_notification.telegram_enabled', false);
        $storedToken = (string) $user->getSetting('mail_notification.telegram_token', '');
        $chatId = trim((string) $user->getSetting('mail_notification.telegram_chat_id', ''));

        if ($telegramEnabled && '' !== $storedToken && '' !== $chatId) {
            try {
                $token = $this->encryptor->isEncrypted($storedToken)
                    ? $this->encryptor->decrypt($storedToken)
                    : '';
                if ('' === $token) {
                    $results['telegram'] = 'not_configured';
                } else {
                    $text = sprintf(
                        "%s\n发件人：%s\n主题：%s\n时间：%s\n摘要：%s",
                        $test ? 'plMail 测试通知' : '收到新邮件',
                        (string) ($event['from'] ?? ''),
                        (string) ($event['subject'] ?? ''),
                        (string) ($event['received_at'] ?? ''),
                        (string) ($event['snippet'] ?? ''),
                    );
                    $response = $this->httpClient->request('POST', 'https://api.telegram.org/bot' . $token . '/sendMessage', [
                        'json' => ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true],
                        'timeout' => 8,
                        'max_redirects' => 0,
                    ]);
                    $status = $response->getStatusCode();
                    $results['telegram'] = $status >= 200 && $status < 300 ? 'ok' : 'failed';
                    if ($status < 200 || $status >= 300) {
                        $this->logger->warning('Telegram mail notification returned a non-success status', [
                            'userId' => $user->id, 'status' => $status,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $results['telegram'] = 'failed';
                $this->logger->warning('Telegram mail notification failed', [
                    'userId' => $user->id, 'errorClass' => $e::class,
                ]);
            }
        } elseif ($telegramEnabled) {
            $results['telegram'] = 'not_configured';
        }

        return $results;
    }
}
