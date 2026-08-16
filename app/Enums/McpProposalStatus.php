<?php

namespace App\Enums;

/**
 * Lifecycle of an agentic-MCP write proposal. A proposal is `Pending` until a
 * ConfirmProposal call commits it (`Confirmed`) — at which point its replay is a
 * no-op — or it lapses (`Expired`) / is refused (`Rejected`). Only `Pending`
 * proposals can be confirmed.
 */
enum McpProposalStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Expired = 'expired';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Confirmed => __('Confirmed'),
            self::Expired => __('Expired'),
            self::Rejected => __('Rejected'),
        };
    }

    public function isConfirmable(): bool
    {
        return $this === self::Pending;
    }
}
