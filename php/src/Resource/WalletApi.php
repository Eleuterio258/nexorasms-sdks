<?php

declare(strict_types=1);

namespace E258Tech\NexoraSms\Resource;

use E258Tech\NexoraSms\Exception\NexoraSmsException;
use E258Tech\NexoraSms\Internal\ApiCore;
use E258Tech\NexoraSms\Model\Page;
use E258Tech\NexoraSms\Model\Wallet;
use E258Tech\NexoraSms\Model\WalletTransaction;
use E258Tech\NexoraSms\Request\QueryValidation;

/**
 * /api/v1/wallet
 */
final class WalletApi
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
    public function get(): Wallet
    {
        return Wallet::fromArray($this->core->request('GET', '/api/v1/wallet', true)->data);
    }

    /**
     * @return Page<WalletTransaction>
     *
     * @throws \InvalidArgumentException When page < 1 or pageSize is outside 1–200.
     * @throws NexoraSmsException
     */
    public function transactions(?int $page = null, ?int $pageSize = null): Page
    {
        $data = $this->core->request('GET', '/api/v1/wallet/transactions', true, [
            'page' => $page === null ? null : QueryValidation::page($page),
            'page_size' => $pageSize === null ? null : QueryValidation::pageSize($pageSize),
        ])->data;

        return Page::fromArray($data, WalletTransaction::fromArray(...));
    }
}
