<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Resource;

use E258Tech\NexoraSms\Exception\NexoraSmsException;
use E258Tech\NexoraSms\Internal\ApiCore;
use E258Tech\NexoraSms\Model\Campaign;
use E258Tech\NexoraSms\Model\CampaignStats;

/**
 * /api/v1/campaigns
 */
final class CampaignsApi
{
    /**
     * @internal
     */
    public function __construct(private readonly ApiCore $core)
    {
    }

    /**
     * @throws NexoraSmsException
     */
    public function get(string $id): Campaign
    {
        return Campaign::fromArray($this->core->request('GET', '/api/v1/campaigns/' . PathSegment::encode($id), true)->data);
    }

    /**
     * @throws NexoraSmsException
     */
    public function stats(string $id): CampaignStats
    {
        return CampaignStats::fromArray(
            $this->core->request('GET', '/api/v1/campaigns/' . PathSegment::encode($id) . '/stats', true)->data,
        );
    }
}
