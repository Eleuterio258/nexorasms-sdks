<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Enum;

/**
 * Status of a campaign.
 *
 * Models keep the raw text next to the enum, so values added later by the API do not break parsing.
 */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
