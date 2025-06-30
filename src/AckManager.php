<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery;

use Tourze\QUIC\Frames\AckFrame;
use Tourze\QUIC\Recovery\Exception\InvalidPacketNumberException;

/**
 * ACK管理器
 *
 * 负责生成和处理ACK帧，管理接收到的包的确认状态
 */
final class AckManager
{
    // 最大ACK延迟（毫秒）
    private const MAX_ACK_DELAY = 25;
    
    // ACK频率阈值
    private const ACK_FREQUENCY_THRESHOLD = 2;

    /** @var array<int, float> 接收到的包号及其接收时间 */
    private array $receivedPackets = [];
    
    /** @var array<int, bool> 需要确认的包号 */
    private array $packetsToAck = [];
    
    private int $largestReceived = -1;
    private float $largestReceivedTime = 0.0;
    private int $ackElicitingReceived = 0;
    private bool $ackPending = false;
    private float $ackTimeout = 0.0;

    /**
     * 处理接收到的数据包
     */
    public function onPacketReceived(
        int $packetNumber,
        float $receiveTime,
        bool $ackEliciting = true
    ): void {
        if ($packetNumber < 0) {
            throw new InvalidPacketNumberException('包号不能为负数');
        }

        // 检查是否为重复包
        if (isset($this->receivedPackets[$packetNumber])) {
            return;
        }

        $this->receivedPackets[$packetNumber] = $receiveTime;
        $this->packetsToAck[$packetNumber] = true;

        // 更新最大接收包号
        if ($packetNumber > $this->largestReceived) {
            $this->largestReceived = $packetNumber;
            $this->largestReceivedTime = $receiveTime;
        }

        if ($ackEliciting) {
            $this->ackElicitingReceived++;
            $this->ackPending = true;
            
            // 设置ACK超时时间
            $this->ackTimeout = $receiveTime + self::MAX_ACK_DELAY;
        }
    }

    /**
     * 检查是否需要立即发送ACK
     */
    public function shouldSendAckImmediately(float $currentTime): bool
    {
        // 接收到一定数量的ACK引发包时立即发送
        if ($this->ackElicitingReceived >= self::ACK_FREQUENCY_THRESHOLD) {
            return true;
        }

        // ACK超时时立即发送
        if ($this->ackPending && $currentTime >= $this->ackTimeout) {
            return true;
        }

        return false;
    }

    /**
     * 生成ACK帧
     */
    public function generateAckFrame(float $currentTime): ?AckFrame
    {
        if (empty($this->packetsToAck)) {
            return null;
        }

        // 计算ACK延迟
        $ackDelay = 0;
        if ($this->largestReceivedTime > 0) {
            $ackDelay = max(0, $currentTime - $this->largestReceivedTime);
        }

        // 构建ACK范围
        $ackRanges = $this->buildAckRanges();
        
        if (empty($ackRanges)) {
            return null;
        }

        // 创建ACK帧
        $ackFrame = new AckFrame(
            $this->largestReceived,
            (int) ($ackDelay * 1000), // 转换为微秒
            $ackRanges
        );

        // 重置状态
        $this->resetAckState();

        return $ackFrame;
    }

    /**
     * 构建ACK范围
     *
     * @return array<array{int, int}> ACK范围数组
     */
    private function buildAckRanges(): array
    {
        if (empty($this->packetsToAck)) {
            return [];
        }

        $packetNumbers = array_keys($this->packetsToAck);
        sort($packetNumbers);

        $ranges = [];
        $start = $packetNumbers[0];
        $end = $start;

        for ($i = 1; $i < count($packetNumbers); $i++) {
            $current = $packetNumbers[$i];
            
            // 如果连续，扩展当前范围
            if ($current === $end + 1) {
                $end = $current;
            } else {
                // 否则保存当前范围，开始新范围
                $ranges[] = [$start, $end];
                $start = $current;
                $end = $current;
            }
        }

        // 添加最后一个范围
        $ranges[] = [$start, $end];

        // QUIC要求ACK范围按降序排列
        return array_reverse($ranges);
    }

    /**
     * 重置ACK状态
     */
    private function resetAckState(): void
    {
        $this->packetsToAck = [];
        $this->ackElicitingReceived = 0;
        $this->ackPending = false;
        $this->ackTimeout = 0.0;
    }

    /**
     * 处理发送的ACK帧
     *
     * @param array<array{int, int}> $ackRanges 已确认的范围
     */
    public function onAckSent(array $ackRanges): void
    {
        foreach ($ackRanges as $range) {
            [$start, $end] = $range;
            
            for ($packetNumber = $start; $packetNumber <= $end; $packetNumber++) {
                unset($this->packetsToAck[$packetNumber]);
            }
        }
    }

    /**
     * 检测丢失的包（基于接收到的包序列）
     *
     * @return array<int> 可能丢失的包号
     */
    public function detectMissingPackets(): array
    {
        if ($this->largestReceived <= 0) {
            return [];
        }

        $missingPackets = [];
        
        // 检查0到最大接收包号之间的缺失包
        for ($i = 0; $i <= $this->largestReceived; $i++) {
            if (!isset($this->receivedPackets[$i])) {
                $missingPackets[] = $i;
            }
        }

        return $missingPackets;
    }

    /**
     * 获取需要确认的包数量
     */
    public function getPendingAckCount(): int
    {
        return count($this->packetsToAck);
    }

    /**
     * 获取最大接收包号
     */
    public function getLargestReceived(): int
    {
        return $this->largestReceived;
    }

    /**
     * 检查是否有待发送的ACK
     */
    public function hasAckPending(): bool
    {
        return $this->ackPending || !empty($this->packetsToAck);
    }

    /**
     * 获取ACK超时时间
     */
    public function getAckTimeout(): float
    {
        return $this->ackTimeout;
    }

    /**
     * 清理旧的接收包记录
     *
     * @param float $cutoffTime 截止时间，早于此时间的记录将被清理
     */
    public function cleanupOldRecords(float $cutoffTime): void
    {
        foreach ($this->receivedPackets as $packetNumber => $receiveTime) {
            if ($receiveTime < $cutoffTime) {
                unset($this->receivedPackets[$packetNumber]);
                unset($this->packetsToAck[$packetNumber]);
            }
        }
    }

    /**
     * 获取统计信息
     */
    public function getStats(): array
    {
        return [
            'received_packets' => count($this->receivedPackets),
            'pending_acks' => count($this->packetsToAck),
            'largest_received' => $this->largestReceived,
            'ack_eliciting_received' => $this->ackElicitingReceived,
            'ack_pending' => $this->ackPending,
            'missing_packets' => count($this->detectMissingPackets()),
        ];
    }

    /**
     * 重置管理器状态
     */
    public function reset(): void
    {
        $this->receivedPackets = [];
        $this->packetsToAck = [];
        $this->largestReceived = -1;
        $this->largestReceivedTime = 0.0;
        $this->ackElicitingReceived = 0;
        $this->ackPending = false;
        $this->ackTimeout = 0.0;
    }
} 