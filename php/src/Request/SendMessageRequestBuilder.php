<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Request;

use E258Tech\NexoraSms\Enum\Channel;

/**
 * Builds a {@see SendMessageRequest}. Each channel may appear once (1 to 3 channels).
 */
final class SendMessageRequestBuilder
{
    public const MAX_LIST = 1000;

    /** @var list<array<string, mixed>> */
    private array $channels = [];
    /** @var list<string> */
    private array $recipients = [];
    /** @var list<string> */
    private array $contactIds = [];
    private ?bool $ignoreQuietHours = null;
    private ?string $idempotencyKey = null;

    /**
     * SMS, with an optional approved Sender ID.
     */
    public function sms(string $content, ?string $sender = null): self
    {
        $this->channels[] = ['channel' => Channel::Sms->value, 'content' => $content, 'sender' => $sender];

        return $this;
    }

    /**
     * Email (HTML content), with an optional sender at a verified domain.
     */
    public function email(string $subject, string $content, ?string $sender = null): self
    {
        $this->channels[] = ['channel' => Channel::Email->value, 'subject' => $subject, 'content' => $content, 'sender' => $sender];

        return $this;
    }

    /**
     * WhatsApp free text (accepted only within 24 h of the customer's last message).
     */
    public function whatsapp(string $content): self
    {
        $this->channels[] = ['channel' => Channel::Whatsapp->value, 'content' => $content];

        return $this;
    }

    /**
     * WhatsApp with a template approved by Meta; parameters fill {{1}}, {{2}}… in order.
     *
     * @param string|list<string> ...$parameters
     */
    public function whatsappTemplate(string $name, string $language, string|array ...$parameters): self
    {
        $this->channels[] = [
            'channel' => Channel::Whatsapp->value,
            'whatsapp_template' => ['name' => $name, 'language' => $language, 'parameters' => self::flatten($parameters)],
        ];

        return $this;
    }

    /**
     * E.164 phone numbers and/or email addresses (accumulates). The SDK does not validate their format.
     *
     * @param string|list<string> ...$recipients
     */
    public function to(string|array ...$recipients): self
    {
        array_push($this->recipients, ...self::flatten($recipients));

        return $this;
    }

    /**
     * An existing contact id (accumulates).
     */
    public function contact(string $id): self
    {
        $this->contactIds[] = $id;

        return $this;
    }

    /**
     * Existing contact ids (accumulates).
     *
     * @param string|list<string> ...$ids
     */
    public function contacts(string|array ...$ids): self
    {
        array_push($this->contactIds, ...self::flatten($ids));

        return $this;
    }

    /**
     * Sends even during the account's quiet hours (codes, urgent notices).
     */
    public function ignoreQuietHours(bool $value = true): self
    {
        $this->ignoreQuietHours = $value;

        return $this;
    }

    /**
     * Your own Idempotency-Key (1 to 200 visible ASCII characters), ideally tied to the operation in your system.
     *
     * @throws \InvalidArgumentException
     */
    public function idempotencyKey(string $key): self
    {
        $this->idempotencyKey = self::checkIdempotencyKey($key);

        return $this;
    }

    /**
     * @throws \InvalidArgumentException When the request is incomplete or invalid.
     */
    public function build(): SendMessageRequest
    {
        $count = count($this->channels);
        if ($count < 1 || $count > 3) {
            throw new \InvalidArgumentException('Indique entre 1 e 3 canais.');
        }
        $names = array_column($this->channels, 'channel');
        if (count(array_unique($names)) !== $count) {
            throw new \InvalidArgumentException('Cada canal só pode aparecer uma vez.');
        }

        $channels = [];
        foreach ($this->channels as $channel) {
            $content = $channel['content'] ?? null;
            $template = $channel['whatsapp_template'] ?? null;
            switch ($channel['channel']) {
                case Channel::Sms->value:
                    if (!self::filled($content)) {
                        throw new \InvalidArgumentException('O SMS precisa de conteúdo.');
                    }
                    break;
                case Channel::Email->value:
                    if (!self::filled($channel['subject'] ?? null)) {
                        throw new \InvalidArgumentException('O email precisa de assunto.');
                    }
                    if (!self::filled($content)) {
                        throw new \InvalidArgumentException('O email precisa de conteúdo.');
                    }
                    break;
                case Channel::Whatsapp->value:
                    if (!self::filled($content) && !is_array($template)) {
                        throw new \InvalidArgumentException('O WhatsApp precisa de conteúdo ou de um template.');
                    }
                    if (is_array($template) && (!self::filled($template['name'] ?? null) || !self::filled($template['language'] ?? null))) {
                        throw new \InvalidArgumentException('O template do WhatsApp precisa de nome e idioma.');
                    }
                    break;
            }
            $channels[] = array_filter($channel, static fn (mixed $value): bool => $value !== null);
        }

        if ($this->recipients === [] && $this->contactIds === []) {
            throw new \InvalidArgumentException('Indique pelo menos um destinatário ou contacto.');
        }
        if (count($this->recipients) > self::MAX_LIST) {
            throw new \InvalidArgumentException(sprintf('No máximo %d destinatários.', self::MAX_LIST));
        }
        if (count($this->contactIds) > self::MAX_LIST) {
            throw new \InvalidArgumentException(sprintf('No máximo %d contactos.', self::MAX_LIST));
        }
        if ($this->idempotencyKey !== null) {
            self::checkIdempotencyKey($this->idempotencyKey);
        }

        $body = ['channels' => $channels];
        if ($this->recipients !== []) {
            $body['recipients'] = $this->recipients;
        }
        if ($this->contactIds !== []) {
            $body['contact_ids'] = $this->contactIds;
        }
        if ($this->ignoreQuietHours !== null) {
            $body['ignore_quiet_hours'] = $this->ignoreQuietHours;
        }

        return new SendMessageRequest($body, $this->idempotencyKey);
    }

    /**
     * Checks an Idempotency-Key: 1 to 200 visible ASCII characters (0x21–0x7E).
     *
     * @internal
     */
    public static function checkIdempotencyKey(string $key): string
    {
        if (preg_match('/^[\x21-\x7E]{1,200}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('idempotencyKey deve ter de 1 a 200 caracteres ASCII visíveis.');
        }

        return $key;
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @param array<array-key, string|list<string>> $values
     *
     * @return list<string>
     */
    private static function flatten(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
