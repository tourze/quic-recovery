<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery;

use Tourze\QUIC\Packets\Packet;

/**
 * 已发送包信息
 */
final class SentPacketInfo
{
    public function __construct(
        private readonly int $packetNumber,
        private readonly Packet $packet,
        private readonly float $sentTime,
        private readonly bool $ackEliciting = true,
    ) {
    }

    public function getPacketNumber(): int
    {
        return $this->packetNumber;
    }

    public function getPacket(): Packet
    {
        return $this->packet;
    }

    public function getSentTime(): float
    {
        return $this->sentTime;
    }

    public function isAckEliciting(): bool
    {
        return $this->ackEliciting;
    }

    public function getSize(): int
    {
        return $this->packet->getSize();
    }
}
