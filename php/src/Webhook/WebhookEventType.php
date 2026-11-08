<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Webhook;

/**
 * Webhook event types. {@see WebhookEvent} keeps the raw text too, for types added later.
 */
enum WebhookEventType: string
{
    case MessageDelivered = 'message.delivered';
    case MessageFailed = 'message.failed';
    case CampaignCompleted = 'campaign.completed';
    case CampaignPaused = 'campaign.paused';
    case ContactOptedOut = 'contact.opted_out';
    case Ping = 'ping';
}
