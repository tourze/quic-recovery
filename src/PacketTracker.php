<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery;

use Tourze\QUIC\Packets\Packet;
use Tourze\QUIC\Recovery\Exception\InvalidPacketNumberException;

/**
 * 包追踪器
 *
 * 用于跟踪已发送数据包的状态，支持丢包检测和重传
 */
final class PacketTracker
{
    /** @var array<int, SentPacketInfo> 已发送包信息 */
    private array $sentPackets = [];

    /** @var array<int, bool> 已确认包号集合 */
    private array $ackedPackets = [];

    /** @var array<int, bool> 已丢失包号集合 */
    private array $lostPackets = [];

    private int $largestAcked = -1;

    private int $largestSent = -1;

    private float $timeOfLastSentAckEliciting = 0.0;

    private int $ackElicitingOutstanding = 0;

    /**
     * 记录发送的数据包
     */
    public function onPacketSent(
        int $packetNumber,
        Packet $packet,
        float $sentTime,
        bool $ackEliciting = true,
    ): void {
        if ($packetNumber < 0) {
            throw new InvalidPacketNumberException('包号不能为负数');
        }

        $this->sentPackets[$packetNumber] = new SentPacketInfo(
            $packetNumber,
            $packet,
            $sentTime,
            $ackEliciting
        );

        if ($packetNumber > $this->largestSent) {
            $this->largestSent = $packetNumber;
        }

        if ($ackEliciting) {
            ++$this->ackElicitingOutstanding;
            $this->timeOfLastSentAckEliciting = $sentTime;
        }
    }

    /**
     * 处理收到的ACK确认
     *
     * @param array<array{int, int}> $ackRanges ACK范围 [[start, end], ...]
     * @param float                  $ackTime   收到ACK的时间
     *
     * @return array{newly_acked: array<int>, ack_eliciting_acked: bool}
     */
    public function onAckReceived(array $ackRanges, float $ackTime): array
    {
        $newlyAcked = [];
        $ackElicitingAcked = false;

        foreach ($ackRanges as $range) {
            $result = $this->processAckRange($range);
            $newlyAcked = array_merge($newlyAcked, $result['newly_acked']);
            $ackElicitingAcked = $ackElicitingAcked || $result['ack_eliciting_acked'];
        }

        return [
            'newly_acked' => $newlyAcked,
            'ack_eliciting_acked' => $ackElicitingAcked,
        ];
    }

    /**
     * 处理单个ACK范围
     *
     * @param array{int, int} $range ACK范围 [start, end]
     *
     * @return array{newly_acked: array<int>, ack_eliciting_acked: bool}
     */
    private function processAckRange(array $range): array
    {
        [$start, $end] = $range;
        $newlyAcked = [];
        $ackElicitingAcked = false;

        for ($packetNumber = $start; $packetNumber <= $end; ++$packetNumber) {
            if ($this->shouldAckPacket($packetNumber)) {
                $this->ackedPackets[$packetNumber] = true;
                $newlyAcked[] = $packetNumber;

                $result = $this->updatePacketState($packetNumber);
                $ackElicitingAcked = $ackElicitingAcked || $result;
            }
        }

        return [
            'newly_acked' => $newlyAcked,
            'ack_eliciting_acked' => $ackElicitingAcked,
        ];
    }

    /**
     * 检查是否应该确认数据包
     */
    private function shouldAckPacket(int $packetNumber): bool
    {
        return isset($this->sentPackets[$packetNumber])
            && !isset($this->ackedPackets[$packetNumber]);
    }

    /**
     * 更新数据包状态并返回是否为ACK引发包
     */
    private function updatePacketState(int $packetNumber): bool
    {
        $sentInfo = $this->sentPackets[$packetNumber];
        $isAckEliciting = false;

        if ($sentInfo->isAckEliciting()) {
            $isAckEliciting = true;
            --$this->ackElicitingOutstanding;
        }

        // 更新最大确认包号
        if ($packetNumber > $this->largestAcked) {
            $this->largestAcked = $packetNumber;
        }

        return $isAckEliciting;
    }

    /**
     * 标记数据包为丢失
     */
    public function onPacketLost(int $packetNumber): void
    {
        if (!isset($this->sentPackets[$packetNumber])) {
            return;
        }

        if (!isset($this->ackedPackets[$packetNumber])
            && !isset($this->lostPackets[$packetNumber])) {
            $this->lostPackets[$packetNumber] = true;

            $sentInfo = $this->sentPackets[$packetNumber];
            if ($sentInfo->isAckEliciting()) {
                --$this->ackElicitingOutstanding;
            }
        }
    }

    /**
     * 检测丢失的数据包
     *
     * @param float $lossThreshold 丢包阈值
     * @param float $currentTime   当前时间
     *
     * @return array<int> 丢失的包号列表
     */
    public function detectLostPackets(float $lossThreshold, float $currentTime): array
    {
        $lostPackets = [];

        if ($this->largestAcked < 0) {
            return $lostPackets;
        }

        // 基于包号的丢包检测
        $lossThresholdPackets = 3; // RFC 9002推荐值

        foreach ($this->sentPackets as $packetNumber => $sentInfo) {
            // 跳过已确认或已标记为丢失的包
            if (isset($this->ackedPackets[$packetNumber])
                || isset($this->lostPackets[$packetNumber])) {
                continue;
            }

            // 包号差异检测
            if ($this->largestAcked - $packetNumber >= $lossThresholdPackets) {
                $lostPackets[] = $packetNumber;
                continue;
            }

            // 时间阈值检测
            $timeSinceSent = $currentTime - $sentInfo->getSentTime();
            if ($timeSinceSent >= $lossThreshold) {
                $lostPackets[] = $packetNumber;
            }
        }

        return $lostPackets;
    }

    /**
     * 获取需要重传的数据包
     *
     * @return array<SentPacketInfo>
     */
    public function getPacketsForRetransmission(): array
    {
        $packetsToRetransmit = [];

        foreach ($this->lostPackets as $packetNumber => $lost) {
            if (isset($this->sentPackets[$packetNumber])) {
                $packetsToRetransmit[] = $this->sentPackets[$packetNumber];
            }
        }

        return $packetsToRetransmit;
    }

    /**
     * 清理已确认的数据包信息
     */
    public function cleanupAckedPackets(): void
    {
        foreach ($this->ackedPackets as $packetNumber => $acked) {
            unset($this->sentPackets[$packetNumber]);
        }
    }

    /**
     * 获取未确认的ACK引发包数量
     */
    public function getAckElicitingOutstanding(): int
    {
        return $this->ackElicitingOutstanding;
    }

    /**
     * 获取最后发送ACK引发包的时间
     */
    public function getTimeOfLastSentAckEliciting(): float
    {
        return $this->timeOfLastSentAckEliciting;
    }

    /**
     * 获取最大已确认包号
     */
    public function getLargestAcked(): int
    {
        return $this->largestAcked;
    }

    /**
     * 获取最大已发送包号
     */
    public function getLargestSent(): int
    {
        return $this->largestSent;
    }

    /**
     * 检查是否有未确认的数据包
     */
    public function hasUnackedPackets(): bool
    {
        return count($this->sentPackets) > count($this->ackedPackets);
    }

    /**
     * 获取统计信息
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'sent_packets' => count($this->sentPackets),
            'acked_packets' => count($this->ackedPackets),
            'lost_packets' => count($this->lostPackets),
            'largest_acked' => $this->largestAcked,
            'largest_sent' => $this->largestSent,
            'ack_eliciting_outstanding' => $this->ackElicitingOutstanding,
        ];
    }

    /**
     * 获取所有已发送数据包
     *
     * @return array<int, SentPacketInfo>
     */
    public function getSentPackets(): array
    {
        return $this->sentPackets;
    }

    /**
     * 检查数据包是否已确认
     */
    public function isAcked(int $packetNumber): bool
    {
        return isset($this->ackedPackets[$packetNumber]);
    }

    /**
     * 检查数据包是否已丢失
     */
    public function isLost(int $packetNumber): bool
    {
        return isset($this->lostPackets[$packetNumber]);
    }

    /**
     * 获取未确认的数据包
     *
     * @return array<SentPacketInfo>
     */
    public function getUnackedPackets(): array
    {
        $unackedPackets = [];

        foreach ($this->sentPackets as $packetNumber => $sentInfo) {
            if (!isset($this->ackedPackets[$packetNumber])
                && !isset($this->lostPackets[$packetNumber])) {
                $unackedPackets[] = $sentInfo;
            }
        }

        return $unackedPackets;
    }
}
