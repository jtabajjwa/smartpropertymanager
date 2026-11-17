<?php

namespace App\Payments\Contracts;

/**
 * A gateway that can report what it currently holds for us, so the books
 * can be checked against it (see App\Services\Reconciliation).
 */
interface BalanceGateway
{
    /**
     * The money available in our account, in UGX, with each wallet's share.
     *
     * @return array{total: float, wallets: array<string, float>}
     *
     * @throws \RuntimeException with a message safe to show staff
     */
    public function accountBalance(): array;
}
