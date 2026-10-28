<?php

namespace App\Mail;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Envia email pela API Microsoft Graph (Microsoft 365), com uma app registada no Entra ID
 * (client credentials, permissão de aplicação Mail.Send). O email sai da caixa do remetente.
 */
class MicrosoftGraphTransport extends AbstractTransport
{
    public function __construct(
        private string $tenantId,
        private string $clientId,
        private string $clientSecret,
        private string $sender,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $html = $email->getHtmlBody();
        $body = $html ?? $email->getTextBody() ?? '';
        if (is_resource($body)) {
            $body = stream_get_contents($body);
        }

        $payload = [
            'message' => array_filter([
                'subject' => (string) $email->getSubject(),
                'body' => ['contentType' => $html !== null ? 'HTML' : 'Text', 'content' => $body],
                'toRecipients' => $this->recipients($email->getTo()),
                'ccRecipients' => $this->recipients($email->getCc()),
                'bccRecipients' => $this->recipients($email->getBcc()),
                'replyTo' => $this->recipients($email->getReplyTo()),
                'attachments' => array_map($this->attachment(...), $email->getAttachments()),
            ]),
            'saveToSentItems' => false,
        ];

        Http::withToken($this->token())
            ->timeout(60)
            ->post('https://graph.microsoft.com/v1.0/users/'.rawurlencode($this->sender).'/sendMail', $payload)
            ->throw();
    }

    private function token(): string
    {
        // O token dura ~1 hora; guarda-se 50 minutos.
        return Cache::remember('msgraph-mail-token-'.md5($this->clientId), 3000, fn () => Http::asForm()
            ->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ])
            ->throw()
            ->json('access_token'));
    }

    /** @param Address[] $addresses */
    private function recipients(array $addresses): array
    {
        return array_map(fn (Address $a) => ['emailAddress' => array_filter([
            'address' => $a->getAddress(),
            'name' => $a->getName() ?: null,
        ])], $addresses);
    }

    private function attachment(DataPart $part): array
    {
        $headers = $part->getPreparedHeaders();

        return array_filter([
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $part->getFilename() ?? 'anexo',
            'contentType' => $headers->get('Content-Type')?->getBody(),
            'contentBytes' => base64_encode($part->getBody()),
            'isInline' => $part->getDisposition() === 'inline' ?: null,
            'contentId' => $part->hasContentId() ? $part->getContentId() : null,
        ], fn ($v) => $v !== null);
    }

    public function __toString(): string
    {
        return 'msgraph';
    }
}
