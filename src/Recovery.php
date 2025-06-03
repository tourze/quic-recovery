<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery;

use Tourze\QUIC\Frames\AckFrame;
use Tourze\QUIC\Packets\Packet;

/**
 * QUIC恢复机制主类
 * 
 * 整合RTT估算、包追踪、丢包检测、ACK管理和重传逻辑
 */
final class Recovery
{
    private RTTEstimator $rttEstimator;
    private PacketTracker $packetTracker;
    private LossDetection $lossDetection;
    private AckManager $ackManager;
    private RetransmissionManager $retransmissionManager;
    
    private float $nextTimeout = 0.0;

    public function __construct(float $initialRtt = 333.0)
    {
        $this->rttEstimator = new RTTEstimator($initialRtt);
        $this->packetTracker = new PacketTracker();
        $this->lossDetection = new LossDetection($this->rttEstimator, $this->packetTracker);
        $this->ackManager = new AckManager();
        $this->retransmissionManager = new RetransmissionManager(
            $this->rttEstimator,
            $this->packetTracker,
            $this->lossDetection,
            $this->ackManager
        );
    }

    /**
     * 记录发送的数据包
     */
    public function onPacketSent(
        int $packetNumber,
        Packet $packet,
        float $sentTime,
        bool $ackEliciting = true
    ): void {
        $this->packetTracker->onPacketSent($packetNumber, $packet, $sentTime, $ackEliciting);
        $this->updateLossDetectionTimer($sentTime);
    }

    /**
     * 处理接收到的数据包
     */
    public function onPacketReceived(
        int $packetNumber,
        float $receiveTime,
        bool $ackEliciting = true
    ): void {
        $this->ackManager->onPacketReceived($packetNumber, $receiveTime, $ackEliciting);
    }

    /**
     * 处理收到的ACK帧
     */
    public function onAckReceived(AckFrame $ackFrame, float $ackTime): void
    {
        $this->retransmissionManager->onAckReceived($ackFrame, $ackTime);
        $this->updateLossDetectionTimer($ackTime);
    }

    /**
     * 检查是否需要立即发送ACK
     */
    public function shouldSendAckImmediately(float $currentTime): bool
    {
        return $this->ackManager->shouldSendAckImmediately($currentTime);
    }

    /**
     * 生成ACK帧
     */
    public function generateAckFrame(float $currentTime): ?AckFrame
    {
        return $this->ackManager->generateAckFrame($currentTime);
    }

    /**
     * 处理超时事件
     */
    public function onTimeout(float $currentTime): array
    {
        $actions = [];

        // 检查丢包检测超时
        if ($this->nextTimeout > 0.0 && $currentTime >= $this->nextTimeout) {
            $result = $this->lossDetection->onLossDetectionTimeout($currentTime);
            
            if ($result['action'] === 'loss_detection') {
                $actions[] = [
                    'type' => 'retransmit_lost',
                    'packets' => $result['packets'],
                ];
            } elseif ($result['action'] === 'pto_probe') {
                $probePackets = $this->retransmissionManager->onPtoTimeout($currentTime);
                $actions[] = [
                    'type' => 'pto_probe',
                    'packets' => $probePackets,
                ];
            }
            
            $this->updateLossDetectionTimer($currentTime);
        }

        // 检查ACK超时
        if ($this->ackManager->hasAckPending()) {
            $ackTimeout = $this->ackManager->getAckTimeout();
            if ($ackTimeout > 0.0 && $currentTime >= $ackTimeout) {
                $actions[] = [
                    'type' => 'send_ack',
                    'frame' => $this->generateAckFrame($currentTime),
                ];
            }
        }

        return $actions;
    }

    /**
     * 获取需要重传的数据包
     */
    public function getPacketsForRetransmission(): array
    {
        return $this->retransmissionManager->getPacketsForRetransmission();
    }

    /**
     * 更新丢包检测定时器
     */
    private function updateLossDetectionTimer(float $currentTime): void
    {
        $this->nextTimeout = $this->lossDetection->setLossDetectionTimer($currentTime);
    }

    /**
     * 获取下次超时时间
     */
    public function getNextTimeout(): float
    {
        return $this->nextTimeout;
    }

    /**
     * 清理过期记录
     */
    public function cleanup(float $currentTime): void
    {
        $cutoffTime = $currentTime - 300.0; // 5分钟前的记录
        
        $this->packetTracker->cleanupAckedPackets();
        $this->ackManager->cleanupOldRecords($cutoffTime);
        $this->retransmissionManager->cleanupExpiredRetransmissions($cutoffTime);
    }

    /**
     * 获取恢复机制的综合统计信息
     */
    public function getStats(): array
    {
        return [
            'rtt' => $this->rttEstimator->getStats(),
            'packet_tracker' => $this->packetTracker->getStats(),
            'loss_detection' => $this->lossDetection->getStats(),
            'ack_manager' => $this->ackManager->getStats(),
            'retransmission' => $this->retransmissionManager->getRetransmissionStats(),
            'next_timeout' => $this->nextTimeout,
        ];
    }

    /**
     * 重置所有恢复机制状态
     */
    public function reset(): void
    {
        $this->rttEstimator->reset();
        $this->packetTracker = new PacketTracker();
        $this->lossDetection->reset();
        $this->ackManager->reset();
        $this->retransmissionManager->reset();
        $this->nextTimeout = 0.0;
    }

    /**
     * 获取当前RTT估算值
     */
    public function getCurrentRtt(): float
    {
        return $this->rttEstimator->getSmoothedRtt();
    }

    /**
     * 获取重传率
     */
    public function getRetransmissionRate(): float
    {
        return $this->retransmissionManager->getRetransmissionRate();
    }

    /**
     * 检查连接健康状态
     */
    public function isConnectionHealthy(): bool
    {
        return !$this->lossDetection->isInPersistentCongestion() &&
               !$this->retransmissionManager->isInRetransmissionStorm();
    }

    /**
     * 获取拥塞状态建议
     */
    public function getCongestionAdvice(): string
    {
        if ($this->lossDetection->isInPersistentCongestion()) {
            return 'persistent_congestion';
        }
        
        if ($this->retransmissionManager->isInRetransmissionStorm()) {
            return 'retransmission_storm';
        }
        
        if ($this->getRetransmissionRate() > 0.1) {
            return 'high_loss_rate';
        }
        
        return 'normal';
    }

    /**
     * 获取各组件实例（用于高级控制）
     */
    public function getRttEstimator(): RTTEstimator
    {
        return $this->rttEstimator;
    }

    public function getPacketTracker(): PacketTracker
    {
        return $this->packetTracker;
    }

    public function getLossDetection(): LossDetection
    {
        return $this->lossDetection;
    }

    public function getAckManager(): AckManager
    {
        return $this->ackManager;
    }

    public function getRetransmissionManager(): RetransmissionManager
    {
        return $this->retransmissionManager;
    }
} 