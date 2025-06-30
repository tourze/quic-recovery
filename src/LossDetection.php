<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery;

/**
 * 丢包检测器
 *
 * 根据RFC 9002实现丢包检测算法
 * 支持基于时间和包号的检测机制
 */
final class LossDetection
{
    // 包重排序阈值（包号差值）
    private const PACKET_THRESHOLD = 3;
    
    // 时间重排序阈值倍数
    private const TIME_THRESHOLD = 9.0 / 8.0;
    
    // 最小时间阈值（毫秒）
    private const MIN_TIME_THRESHOLD = 1.0;

    private RTTEstimator $rttEstimator;
    private PacketTracker $packetTracker;
    private float $lossTime = 0.0;
    private int $ptoCount = 0;

    public function __construct(
        RTTEstimator $rttEstimator,
        PacketTracker $packetTracker
    ) {
        $this->rttEstimator = $rttEstimator;
        $this->packetTracker = $packetTracker;
    }

    /**
     * 检测丢失的数据包
     *
     * @param float $currentTime 当前时间
     * @return array{lost_packets: array<int>, loss_time: float}
     */
    public function detectLostPackets(float $currentTime): array
    {
        $lostPackets = [];
        $lossTime = 0.0;

        $largestAcked = $this->packetTracker->getLargestAcked();
        
        if ($largestAcked < 0) {
            return ['lost_packets' => $lostPackets, 'loss_time' => $lossTime];
        }

        // 计算丢包时间阈值
        $lossDelay = $this->calculateLossDelay();

        foreach ($this->packetTracker->getSentPackets() as $packetNumber => $sentInfo) {
            // 跳过已确认或已丢失的包
            if ($this->packetTracker->isAcked($packetNumber) || 
                $this->packetTracker->isLost($packetNumber)) {
                continue;
            }

            // 基于包号的丢包检测
            if ($largestAcked - $packetNumber >= self::PACKET_THRESHOLD) {
                $lostPackets[] = $packetNumber;
                $this->packetTracker->onPacketLost($packetNumber);
                continue;
            }

            // 基于时间的丢包检测
            $timeSinceSent = $currentTime - $sentInfo->getSentTime();
            if ($timeSinceSent >= $lossDelay) {
                $lostPackets[] = $packetNumber;
                $this->packetTracker->onPacketLost($packetNumber);
            } else {
                // 计算预期丢包时间
                $expectedLossTime = $sentInfo->getSentTime() + $lossDelay;
                if ($lossTime === 0.0 || $expectedLossTime < $lossTime) {
                    $lossTime = $expectedLossTime;
                }
            }
        }

        $this->lossTime = $lossTime;
        
        return [
            'lost_packets' => $lostPackets,
            'loss_time' => $lossTime,
        ];
    }

    /**
     * 计算丢包延迟阈值
     */
    private function calculateLossDelay(): float
    {
        $smoothedRtt = $this->rttEstimator->getSmoothedRtt();
        $rttVar = $this->rttEstimator->getRttVariation();
        
        // loss_delay = time_threshold * max(latest_rtt, smoothed_rtt)
        $latestRtt = $this->rttEstimator->getLatestRtt();
        $maxRtt = max($latestRtt, $smoothedRtt);
        
        $lossDelay = self::TIME_THRESHOLD * $maxRtt;
        
        // 确保不小于最小阈值
        return max($lossDelay, self::MIN_TIME_THRESHOLD);
    }

    /**
     * 设置丢包定时器
     *
     * @param float $currentTime 当前时间
     * @return float 下次超时时间，0表示无需设置定时器
     */
    public function setLossDetectionTimer(float $currentTime): float
    {
        $timeout = $this->getEarliestLossTime($currentTime);
        
        if ($timeout > 0.0) {
            return $timeout;
        }

        // 如果没有ACK引发的数据包在外，不需要设置定时器
        if ($this->packetTracker->getAckElicitingOutstanding() === 0) {
            return 0.0;
        }

        // 设置PTO定时器
        $ptoTimeout = $this->calculatePtoTimeout($currentTime);
        
        return $ptoTimeout;
    }

    /**
     * 获取最早的丢包时间
     */
    private function getEarliestLossTime(float $currentTime): float
    {
        if ($this->lossTime > 0.0 && $this->lossTime > $currentTime) {
            return $this->lossTime;
        }
        
        return 0.0;
    }

    /**
     * 计算PTO超时时间
     */
    private function calculatePtoTimeout(float $currentTime): float
    {
        $pto = $this->rttEstimator->calculatePto($this->ptoCount);
        $lastSentTime = $this->packetTracker->getTimeOfLastSentAckEliciting();
        
        if ($lastSentTime === 0.0) {
            return $currentTime + $pto;
        }
        
        return $lastSentTime + $pto;
    }

    /**
     * 处理丢包定时器超时
     *
     * @param float $currentTime 当前时间
     * @return array{action: string, packets: array<int>}
     */
    public function onLossDetectionTimeout(float $currentTime): array
    {
        $earliestLossTime = $this->getEarliestLossTime($currentTime);
        
        if ($earliestLossTime > 0.0 && $currentTime >= $earliestLossTime) {
            // 丢包定时器超时
            $result = $this->detectLostPackets($currentTime);
            return [
                'action' => 'loss_detection',
                'packets' => $result['lost_packets'],
            ];
        }

        // PTO超时
        return $this->onPtoTimeout($currentTime);
    }

    /**
     * 处理PTO超时
     */
    private function onPtoTimeout(float $currentTime): array
    {
        $this->ptoCount++;
        
        // 发送PTO探测包
        return [
            'action' => 'pto_probe',
            'packets' => $this->selectProbePackets(),
        ];
    }

    /**
     * 选择需要探测的数据包
     */
    private function selectProbePackets(): array
    {
        // 选择最旧的未确认包进行重传
        $unackedPackets = $this->packetTracker->getUnackedPackets();
        
        if (empty($unackedPackets)) {
            return [];
        }

        // 按发送时间排序，选择最旧的包
        usort($unackedPackets, function ($a, $b) {
            return $a->getSentTime() <=> $b->getSentTime();
        });

        // 最多选择2个包进行探测
        return array_slice($unackedPackets, 0, 2);
    }

    /**
     * 确认收到ACK时重置PTO计数
     */
    public function onAckReceived(): void
    {
        $this->ptoCount = 0;
    }

    /**
     * 获取当前PTO计数
     */
    public function getPtoCount(): int
    {
        return $this->ptoCount;
    }

    /**
     * 获取下次丢包检测时间
     */
    public function getLossTime(): float
    {
        return $this->lossTime;
    }

    /**
     * 检查是否处于持续丢包状态
     */
    public function isInPersistentCongestion(): bool
    {
        // 简化的持续拥塞检测
        return $this->ptoCount >= 3;
    }

    /**
     * 重置丢包检测状态
     */
    public function reset(): void
    {
        $this->lossTime = 0.0;
        $this->ptoCount = 0;
    }

    /**
     * 获取统计信息
     */
    public function getStats(): array
    {
        return [
            'pto_count' => $this->ptoCount,
            'loss_time' => $this->lossTime,
            'persistent_congestion' => $this->isInPersistentCongestion(),
            'loss_delay' => $this->calculateLossDelay(),
        ];
    }

    /**
     * 验证丢包检测配置
     */
    public function validateConfig(): bool
    {
        // 这些常量已经硬编码为满足条件的值，所以总是返回true
        // PACKET_THRESHOLD = 3 > 0
        // TIME_THRESHOLD = 9.0 / 8.0 = 1.125 > 1.0  
        // MIN_TIME_THRESHOLD = 1.0 > 0
        return true;
    }
} 