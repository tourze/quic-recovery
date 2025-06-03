<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery;

use Tourze\QUIC\Frames\AckFrame;

/**
 * 重传管理器
 * 
 * 负责管理数据包的重传逻辑，包括常规重传和PTO探测
 */
final class RetransmissionManager
{
    // 最大重传次数
    private const MAX_RETRANSMISSIONS = 5;
    
    // 重传指数退避基数
    private const RETRANSMISSION_BACKOFF = 2.0;

    private RTTEstimator $rttEstimator;
    private PacketTracker $packetTracker;
    private LossDetection $lossDetection;
    private AckManager $ackManager;
    
    /** @var array<int, int> 包重传次数统计 */
    private array $retransmissionCounts = [];
    
    /** @var array<int, float> 包重传时间记录 */
    private array $retransmissionTimes = [];
    
    private int $totalRetransmissions = 0;
    private float $lastRetransmissionTime = 0.0;

    public function __construct(
        RTTEstimator $rttEstimator,
        PacketTracker $packetTracker,
        LossDetection $lossDetection,
        AckManager $ackManager
    ) {
        $this->rttEstimator = $rttEstimator;
        $this->packetTracker = $packetTracker;
        $this->lossDetection = $lossDetection;
        $this->ackManager = $ackManager;
    }

    /**
     * 处理收到的ACK帧
     */
    public function onAckReceived(AckFrame $ackFrame, float $ackTime): void
    {
        $ackRanges = $ackFrame->getAckRanges();
        $result = $this->packetTracker->onAckReceived($ackRanges, $ackTime);
        
        // 更新RTT估算
        foreach ($result['newly_acked'] as $packetNumber) {
            $this->updateRttFromAck($packetNumber, $ackFrame, $ackTime);
        }

        // 检测丢包
        if (!empty($result['newly_acked'])) {
            $this->lossDetection->onAckReceived();
            $lossResult = $this->lossDetection->detectLostPackets($ackTime);
            
            foreach ($lossResult['lost_packets'] as $lostPacket) {
                $this->scheduleLostPacketRetransmission($lostPacket);
            }
        }
    }

    /**
     * 从ACK更新RTT估算
     */
    private function updateRttFromAck(int $packetNumber, AckFrame $ackFrame, float $ackTime): void
    {
        $sentPackets = $this->packetTracker->getSentPackets();
        
        if (!isset($sentPackets[$packetNumber])) {
            return;
        }

        $sentInfo = $sentPackets[$packetNumber];
        $rttSample = $ackTime - $sentInfo->getSentTime();
        
        // 只有最大确认包号才用于RTT计算
        if ($packetNumber === $ackFrame->getLargestAcked()) {
            $ackDelay = $ackFrame->getAckDelay() / 1000.0; // 转换为毫秒
            $this->rttEstimator->updateRtt($rttSample, $ackDelay);
        }
    }

    /**
     * 调度丢失包的重传
     */
    private function scheduleLostPacketRetransmission(int $packetNumber): void
    {
        // 检查重传次数限制
        $retransmissionCount = $this->retransmissionCounts[$packetNumber] ?? 0;
        
        if ($retransmissionCount >= self::MAX_RETRANSMISSIONS) {
            // 达到最大重传次数，放弃重传
            return;
        }

        $this->retransmissionCounts[$packetNumber] = $retransmissionCount + 1;
        $this->totalRetransmissions++;
    }

    /**
     * 处理PTO超时
     */
    public function onPtoTimeout(float $currentTime): array
    {
        $result = $this->lossDetection->onLossDetectionTimeout($currentTime);
        
        if ($result['action'] === 'pto_probe') {
            return $this->scheduleProbeRetransmissions($result['packets'], $currentTime);
        }

        return [];
    }

    /**
     * 调度探测重传
     */
    private function scheduleProbeRetransmissions(array $packets, float $currentTime): array
    {
        $retransmissionPackets = [];
        
        foreach ($packets as $sentInfo) {
            $packetNumber = $sentInfo->getPacketNumber();
            
            // 记录重传时间
            $this->retransmissionTimes[$packetNumber] = $currentTime;
            $this->lastRetransmissionTime = $currentTime;
            
            $retransmissionPackets[] = [
                'packet_number' => $packetNumber,
                'original_packet' => $sentInfo->getPacket(),
                'retransmission_count' => $this->retransmissionCounts[$packetNumber] ?? 0,
            ];
        }
        
        return $retransmissionPackets;
    }

    /**
     * 获取需要重传的数据包
     */
    public function getPacketsForRetransmission(): array
    {
        $retransmissionPackets = [];
        $lostPackets = $this->packetTracker->getPacketsForRetransmission();
        
        foreach ($lostPackets as $sentInfo) {
            $packetNumber = $sentInfo->getPacketNumber();
            $retransmissionCount = $this->retransmissionCounts[$packetNumber] ?? 0;
            
            if ($retransmissionCount < self::MAX_RETRANSMISSIONS) {
                $retransmissionPackets[] = [
                    'packet_number' => $packetNumber,
                    'original_packet' => $sentInfo->getPacket(),
                    'retransmission_count' => $retransmissionCount,
                    'backoff_multiplier' => pow(self::RETRANSMISSION_BACKOFF, $retransmissionCount),
                ];
            }
        }
        
        return $retransmissionPackets;
    }

    /**
     * 计算重传延迟
     */
    public function calculateRetransmissionDelay(int $retransmissionCount): float
    {
        $baseDelay = $this->rttEstimator->getSmoothedRtt();
        $backoffMultiplier = pow(self::RETRANSMISSION_BACKOFF, $retransmissionCount);
        
        return $baseDelay * $backoffMultiplier;
    }

    /**
     * 处理数据包重传确认
     */
    public function onRetransmissionAcked(int $originalPacketNumber, int $newPacketNumber): void
    {
        // 清理原包的重传计数
        unset($this->retransmissionCounts[$originalPacketNumber]);
        unset($this->retransmissionTimes[$originalPacketNumber]);
    }

    /**
     * 检查是否需要快速重传
     */
    public function shouldFastRetransmit(): bool
    {
        // 基于丢包检测器的判断
        return $this->lossDetection->getPtoCount() > 0;
    }

    /**
     * 获取重传统计信息
     */
    public function getRetransmissionStats(): array
    {
        return [
            'total_retransmissions' => $this->totalRetransmissions,
            'pending_retransmissions' => count($this->retransmissionCounts),
            'last_retransmission_time' => $this->lastRetransmissionTime,
            'avg_retransmission_delay' => $this->calculateAverageRetransmissionDelay(),
        ];
    }

    /**
     * 计算平均重传延迟
     */
    private function calculateAverageRetransmissionDelay(): float
    {
        if (empty($this->retransmissionCounts)) {
            return 0.0;
        }

        $totalDelay = 0.0;
        $count = 0;
        
        foreach ($this->retransmissionCounts as $packetNumber => $retransmissionCount) {
            $totalDelay += $this->calculateRetransmissionDelay($retransmissionCount);
            $count++;
        }
        
        return $count > 0 ? $totalDelay / $count : 0.0;
    }

    /**
     * 清理过期的重传记录
     */
    public function cleanupExpiredRetransmissions(float $cutoffTime): void
    {
        foreach ($this->retransmissionTimes as $packetNumber => $retransmissionTime) {
            if ($retransmissionTime < $cutoffTime) {
                unset($this->retransmissionCounts[$packetNumber]);
                unset($this->retransmissionTimes[$packetNumber]);
            }
        }
    }

    /**
     * 重置重传管理器状态
     */
    public function reset(): void
    {
        $this->retransmissionCounts = [];
        $this->retransmissionTimes = [];
        $this->totalRetransmissions = 0;
        $this->lastRetransmissionTime = 0.0;
    }

    /**
     * 获取重传率
     */
    public function getRetransmissionRate(): float
    {
        $totalSent = $this->packetTracker->getLargestSent() + 1;
        
        if ($totalSent <= 0) {
            return 0.0;
        }
        
        return $this->totalRetransmissions / $totalSent;
    }

    /**
     * 检查是否处于重传风暴状态
     */
    public function isInRetransmissionStorm(): bool
    {
        return $this->getRetransmissionRate() > 0.5; // 重传率超过50%
    }
} 