<?php

namespace groundmail\craft\mail;

use Groundmail\Symfony\Transport\MessageMapper;
use Symfony\Component\Mime\Email;

/**
 * Maps a Craft message to a Groundmail send: sets the email category, and drops headers the
 * Groundmail API sets itself and refuses from callers (Return-Path, Sender…).
 *
 * A message can pick its own category with an X-Groundmail-Category header (transactional,
 * product, or newsletter for an account approved for newsletters).
 */
class CraftMessageMapper extends MessageMapper
{
    public const HEADER_CATEGORY = 'X-Groundmail-Category';

    private const HEADER_CATEGORIES = ['transactional', 'product', 'newsletter'];

    /** Headers the API refuses from callers (the mail server writes them, or they have their own field). */
    private const DROPPED_HEADERS = [
        'return-path',
        'sender',
        'received',
        'delivered-to',
        'dkim-signature',
        'authentication-results',
        'content-transfer-encoding',
        'resent-bcc',
        'resent-cc',
        'resent-date',
        'resent-from',
        'resent-message-id',
        'resent-reply-to',
        'resent-sender',
        'resent-to',
        'x-groundmail-category',
    ];

    public function __construct(private readonly string $category = 'transactional')
    {
    }

    public function toSendParams(Email $email): array
    {
        $category = $this->categoryFor($email);

        $clean = clone $email;
        $headers = $clean->getHeaders();
        foreach ($headers->all() as $header) {
            if (in_array(strtolower($header->getName()), self::DROPPED_HEADERS, true)) {
                $headers->remove($header->getName());
            }
        }

        $params = parent::toSendParams($clean);
        $params['category'] = $category;

        return $params;
    }

    public function categoryFor(Email $email): string
    {
        $header = $email->getHeaders()->get(self::HEADER_CATEGORY);
        if ($header !== null) {
            $value = strtolower(trim($header->getBodyAsString()));
            if (in_array($value, self::HEADER_CATEGORIES, true)) {
                return $value;
            }
        }

        return $this->category;
    }
}
