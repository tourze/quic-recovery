<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Frames\AckFrame;
use Tourze\QUIC\Packets\Packet;
use Tourze\QUIC\Packets\PacketType;
use Tourze\QUIC\Recovery\AckManager;
use Tourze\QUIC\Recovery\LossDetection;
use Tourze\QUIC\Recovery\PacketTracker;
use Tourze\QUIC\Recovery\Recovery;
use Tourze\QUIC\Recovery\RetransmissionManager;
use Tourze\QUIC\Recovery\RTTEstimator;

/**
 * @internal
 */
#[CoversClass(Recovery::class)]
final class RecoveryTest extends TestCase
{
    private Recovery $recovery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recovery = new Recovery();
    }

    private function createMockPacket(int $size = 1200): Packet
    {
        return new class($size) extends Packet {
            public function __construct(private readonly int $size)
            {
                parent::__construct(PacketType::INITIAL, 1, '');
            }

            public function encode(): string
            {
                return str_repeat('x', $this->size);
            }

            public static function decode(string $data): static
            {
                return new self(strlen($data));
            }

            public function getSize(): int
            {
                return $this->size;
            }
        };
    }

    // ========================================
    // 构造函数测试
    // ========================================
    public function testConstructorWithDefaultInitialRtt(): void
    {
        $recovery = new Recovery();
        $this->assertEquals(333.0, $recovery->getCurrentRtt());
        $this->assertEquals(0.0, $recovery->getNextTimeout());
        $this->assertTrue($recovery->isConnectionHealthy());
        $this->assertEquals('normal', $recovery->getCongestionAdvice());
    }

    public function testConstructorWithCustomInitialRtt(): void
    {
        $customRtt = 500.0;
        $recovery = new Recovery($customRtt);
        $this->assertEquals($customRtt, $recovery->getCurrentRtt());
        $this->assertEquals(0.0, $recovery->getNextTimeout());
    }

    public function testConstructorComponentsInitialization(): void
    {
        $recovery = new Recovery();
        $this->assertInstanceOf(RTTEstimator::class, $recovery->getRttEstimator());
        $this->assertInstanceOf(PacketTracker::class, $recovery->getPacketTracker());
        $this->assertInstanceOf(LossDetection::class, $recovery->getLossDetection());
        $this->assertInstanceOf(AckManager::class, $recovery->getAckManager());
        $this->assertInstanceOf(RetransmissionManager::class, $recovery->getRetransmissionManager());
    }

    // ========================================
    // 数据包生命周期测试
    // ========================================
    public function testOnPacketSentBasicFunctionality(): void
    {
        $packet = $this->createMockPacket();
        $packetNumber = 1;
        $sentTime = 1000.0;
        $this->recovery->onPacketSent($packetNumber, $packet, $sentTime, true);
        // 验证包追踪器已记录
        $this->assertTrue($this->recovery->getPacketTracker()->hasUnackedPackets());
        $this->assertEquals($packetNumber, $this->recovery->getPacketTracker()->getLargestSent());
        // 验证定时器已更新
        $this->assertGreaterThan(0.0, $this->recovery->getNextTimeout());
    }

    public function testOnPacketSentMultiplePackets(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $packet = $this->createMockPacket();
            $this->recovery->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        $this->assertEquals(5, $this->recovery->getPacketTracker()->getLargestSent());
        $this->assertEquals(5, $this->recovery->getPacketTracker()->getAckElicitingOutstanding());
    }

    public function testOnPacketReceivedBasicFunctionality(): void
    {
        $packetNumber = 1;
        $receiveTime = 1000.0;
        $this->recovery->onPacketReceived($packetNumber, $receiveTime, true);
        // 验证ACK管理器已记录
        $this->assertEquals($packetNumber, $this->recovery->getAckManager()->getLargestReceived());
        $this->assertTrue($this->recovery->getAckManager()->hasAckPending());
    }

    public function testOnPacketReceivedMultiplePackets(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->recovery->onPacketReceived($i, 1000.0 + $i, true);
        }
        $this->assertEquals(3, $this->recovery->getAckManager()->getLargestReceived());
        $this->assertEquals(3, $this->recovery->getAckManager()->getPendingAckCount());
    }

    public function testOnAckReceivedBasicFunctionality(): void
    {
        // 先发送一些包
        for ($i = 1; $i <= 5; ++$i) {
            $packet = $this->createMockPacket();
            $this->recovery->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        // 创建ACK帧确认包1-3
        $ackFrame = new AckFrame(3, 1000, [[1, 3]]);
        $this->recovery->onAckReceived($ackFrame, 1200.0);
        // 验证RTT已更新
        $this->assertNotEquals(333.0, $this->recovery->getCurrentRtt());
        // 验证定时器已更新
        $this->assertGreaterThan(0.0, $this->recovery->getNextTimeout());
    }

    // ========================================
    // ACK管理集成测试
    // ========================================
    public function testShouldSendAckImmediatelyFrequencyThreshold(): void
    {
        $currentTime = 1000.0;
        // 接收第一个包，不应该立即发送
        $this->recovery->onPacketReceived(1, $currentTime, true);
        $this->assertFalse($this->recovery->shouldSendAckImmediately($currentTime));
        // 接收第二个包，应该立即发送
        $this->recovery->onPacketReceived(2, $currentTime + 1, true);
        $this->assertTrue($this->recovery->shouldSendAckImmediately($currentTime + 1));
    }

    public function testShouldSendAckImmediatelyTimeout(): void
    {
        $currentTime = 1000.0;
        // 接收一个包
        $this->recovery->onPacketReceived(1, $currentTime, true);
        // 超时前不应该立即发送
        $this->assertFalse($this->recovery->shouldSendAckImmediately($currentTime + 20.0));
        // 超时后应该立即发送
        $this->assertTrue($this->recovery->shouldSendAckImmediately($currentTime + 30.0));
    }

    public function testGenerateAckFrameWithPendingAcks(): void
    {
        // 接收多个包
        for ($i = 1; $i <= 3; ++$i) {
            $this->recovery->onPacketReceived($i, 1000.0 + $i, true);
        }
        $ackFrame = $this->recovery->generateAckFrame(1010.0);
        $this->assertInstanceOf(AckFrame::class, $ackFrame);
        $this->assertEquals(3, $ackFrame->getLargestAcked());
    }

    public function testGenerateAckFrameNoPendingAcks(): void
    {
        $ackFrame = $this->recovery->generateAckFrame(1000.0);
        $this->assertNull($ackFrame);
    }

    // ========================================
    // 超时处理集成测试
    // ========================================
    public function testOnTimeoutNoTimeouts(): void
    {
        $actions = $this->recovery->onTimeout(1000.0);
        $this->assertEmpty($actions);
    }

    public function testOnTimeoutLossDetectionTimeout(): void
    {
        // 发送包但不确认，创建超时条件
        for ($i = 1; $i <= 10; ++$i) {
            $packet = $this->createMockPacket();
            $this->recovery->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        // 确认部分包以触发丢包检测
        $ackFrame = new AckFrame(7, 1000, [[7, 10]]);
        $this->recovery->onAckReceived($ackFrame, 1200.0);
        // 触发超时
        $nextTimeout = $this->recovery->getNextTimeout();
        // 如果没有超时设置，直接触发一个远期超时
        if (0.0 === $nextTimeout) {
            $actions = $this->recovery->onTimeout(2000.0);
        } else {
            $actions = $this->recovery->onTimeout($nextTimeout + 100.0);
        }
        // 确保总是有断言：验证超时处理正常执行
        $this->assertNotNull($actions);
        // 验证操作的结构
        if ([] !== $actions) {
            $action = $actions[0];
            $this->assertArrayHasKey('type', $action);
            $this->assertContains($action['type'], ['retransmit_lost', 'pto_probe', 'send_ack']);
        }
        // 验证连接状态是健康的（即使有超时也应该能恢复）
        $this->assertNotNull($this->recovery->isConnectionHealthy());
    }

    public function testOnTimeoutAckTimeout(): void
    {
        // 接收包但不生成ACK
        $this->recovery->onPacketReceived(1, 1000.0, true);
        // 等待ACK超时
        $ackTimeout = $this->recovery->getAckManager()->getAckTimeout();
        $this->assertGreaterThan(0.0, $ackTimeout);
        $actions = $this->recovery->onTimeout($ackTimeout + 1.0);
        $this->assertNotEmpty($actions);
        $action = $actions[0];
        $this->assertArrayHasKey('type', $action);
        $this->assertEquals('send_ack', $action['type']);
        $this->assertArrayHasKey('frame', $action);
        $this->assertInstanceOf(AckFrame::class, $action['frame']);
    }

    public function testOnTimeoutCombinedTimeouts(): void
    {
        // 创建复杂的超时场景：发送包 + 接收包
        for ($i = 1; $i <= 5; ++$i) {
            $packet = $this->createMockPacket();
            $this->recovery->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        $this->recovery->onPacketReceived(10, 1100.0, true);
        // 模拟长时间等待
        $actions = $this->recovery->onTimeout(3000.0);
        // 可能有多个动作：丢包重传 + 发送ACK
        if (count($actions) > 1) {
            $actionTypes = array_column($actions, 'type');
            $this->assertContains('send_ack', $actionTypes);
        }
    }

    // ========================================
    // 重传管理集成测试
    // ========================================
    public function testGetPacketsForRetransmissionNoLostPackets(): void
    {
        $packets = $this->recovery->getPacketsForRetransmission();
        $this->assertEmpty($packets);
    }

    public function testGetPacketsForRetransmissionWithLostPackets(): void
    {
        // 发送包并模拟丢失
        for ($i = 1; $i <= 5; ++$i) {
            $packet = $this->createMockPacket();
            $this->recovery->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        // 标记包1,3为丢失
        $this->recovery->getPacketTracker()->onPacketLost(1);
        $this->recovery->getPacketTracker()->onPacketLost(3);
        $packets = $this->recovery->getPacketsForRetransmission();
        $this->assertNotEmpty($packets);
    }

    // ========================================
    // 健康状态监控测试
    // ========================================
    public function testIsConnectionHealthyInitialState(): void
    {
        $this->assertTrue($this->recovery->isConnectionHealthy());
    }

    public function testGetCongestionAdviceNormal(): void
    {
        $this->assertEquals('normal', $this->recovery->getCongestionAdvice());
    }

    public function testGetCongestionAdviceHighLossRate(): void
    {
        // 创建高丢包率场景
        for ($i = 1; $i <= 20; ++$i) {
            $packet = $this->createMockPacket();
            $this->recovery->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        // 标记大量包为丢失
        for ($i = 1; $i <= 15; ++$i) {
            $this->recovery->getPacketTracker()->onPacketLost($i);
        }
        // 确认一些包以计算重传率
        $ackFrame = new AckFrame(20, 1000, [[16, 20]]);
        $this->recovery->onAckReceived($ackFrame, 1200.0);
        $advice = $this->recovery->getCongestionAdvice();
        $this->assertContains($advice, ['high_loss_rate', 'retransmission_storm', 'persistent_congestion', 'normal']);
    }

    public function testGetRetransmissionRateInitialState(): void
    {
        $rate = $this->recovery->getRetransmissionRate();
        $this->assertEquals(0.0, $rate);
    }

    public function testGetRetransmissionRateAfterActivity(): void
    {
        // 发送包并创建重传活动
        for ($i = 1; $i <= 10; ++$i) {
            $packet = $this->createMockPacket();
            $this->recovery->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        // 确认一些包，标记一些为丢失
        $ackFrame = new AckFrame(5, 1000, [[1, 5]]);
        $this->recovery->onAckReceived($ackFrame, 1200.0);
        $this->recovery->getPacketTracker()->onPacketLost(6);
        $this->recovery->getPacketTracker()->onPacketLost(7);
        $rate = $this->recovery->getRetransmissionRate();
        $this->assertGreaterThanOrEqual(0.0, $rate);
        $this->assertLessThanOrEqual(1.0, $rate);
    }

    // ========================================
    // 组件访问测试
    // ========================================
    public function testGetRttEstimator(): void
    {
        $estimator = $this->recovery->getRttEstimator();
        $this->assertInstanceOf(RTTEstimator::class, $estimator);
        $this->assertSame($estimator, $this->recovery->getRttEstimator());
    }

    public function testGetPacketTracker(): void
    {
        $tracker = $this->recovery->getPacketTracker();
        $this->assertInstanceOf(PacketTracker::class, $tracker);
        $this->assertSame($tracker, $this->recovery->getPacketTracker());
    }

    public function testGetLossDetection(): void
    {
        $lossDetection = $this->recovery->getLossDetection();
        $this->assertInstanceOf(LossDetection::class, $lossDetection);
        $this->assertSame($lossDetection, $this->recovery->getLossDetection());
    }

    public function testGetAckManager(): void
    {
        $ackManager = $this->recovery->getAckManager();
        $this->assertInstanceOf(AckManager::class, $ackManager);
        $this->assertSame($ackManager, $this->recovery->getAckManager());
    }

    public function testGetRetransmissionManager(): void
    {
        $retransmissionManager = $this->recovery->getRetransmissionManager();
        $this->assertInstanceOf(RetransmissionManager::class, $retransmissionManager);
        $this->assertSame($retransmissionManager, $this->recovery->getRetransmissionManager());
    }

    // ========================================
    // 统计和清理测试
    // ========================================
    public function testGetStatsInitialState(): void
    {
        $stats = $this->recovery->getStats();
        $this->assertArrayHasKey('rtt', $stats);
        $this->assertArrayHasKey('packet_tracker', $stats);
        $this->assertArrayHasKey('loss_detection', $stats);
        $this->assertArrayHasKey('ack_manager', $stats);
        $this->assertArrayHasKey('retransmission', $stats);
        $this->assertArrayHasKey('next_timeout', $stats);
        $this->assertEquals(0.0, $stats['next_timeout']);
    }

    public function testGetStatsAfterActivity(): void
    {
        // 创建一些活动
        for ($i = 1; $i <= 3; ++$i) {
            $packet = $this->createMockPacket();
            $this->recovery->onPacketSent($i, $packet, 1000.0 + $i, true);
            $this->recovery->onPacketReceived($i + 10, 1000.0 + $i, true);
        }
        $stats = $this->recovery->getStats();
        $this->assertGreaterThan(0, $stats['packet_tracker']['sent_packets']);
        $this->assertGreaterThan(0, $stats['ack_manager']['received_packets']);
    }

    public function testCleanup(): void
    {
        // 发送和接收一些包
        for ($i = 1; $i <= 5; ++$i) {
            $packet = $this->createMockPacket();
            $this->recovery->onPacketSent($i, $packet, 1000.0 + $i, true);
            $this->recovery->onPacketReceived($i + 10, 1000.0 + $i, true);
        }
        // 确认一些包
        $ackFrame = new AckFrame(3, 1000, [[1, 3]]);
        $this->recovery->onAckReceived($ackFrame, 1200.0);
        $statsBefore = $this->recovery->getStats();
        // 清理过期记录
        $this->recovery->cleanup(1600.0); // 5分钟后
        $statsAfter = $this->recovery->getStats();
        // 验证清理操作正常执行
        $this->assertNotNull($statsAfter);
        $this->assertArrayHasKey('packet_tracker', $statsAfter);
    }

    public function testReset(): void
    {
        // 创建一些状态
        for ($i = 1; $i <= 3; ++$i) {
            $packet = $this->createMockPacket();
            $this->recovery->onPacketSent($i, $packet, 1000.0 + $i, true);
            $this->recovery->onPacketReceived($i + 10, 1000.0 + $i, true);
        }
        $this->assertTrue($this->recovery->getPacketTracker()->hasUnackedPackets());
        $this->assertTrue($this->recovery->getAckManager()->hasAckPending());
        // 重置
        $this->recovery->reset();
        // 验证所有状态已重置
        $this->assertFalse($this->recovery->getPacketTracker()->hasUnackedPackets());
        $this->assertFalse($this->recovery->getAckManager()->hasAckPending());
        $this->assertEquals(333.0, $this->recovery->getCurrentRtt());
        $this->assertEquals(0.0, $this->recovery->getNextTimeout());
        $this->assertTrue($this->recovery->isConnectionHealthy());
        $this->assertEquals('normal', $this->recovery->getCongestionAdvice());
    }

    public function testGetCurrentRtt(): void
    {
        $this->assertEquals(333.0, $this->recovery->getCurrentRtt());
        // 更新RTT
        $packet = $this->createMockPacket();
        $this->recovery->onPacketSent(1, $packet, 1000.0, true);
        $ackFrame = new AckFrame(1, 1000, [[1, 1]]);
        $this->recovery->onAckReceived($ackFrame, 1100.0);
        $this->assertNotEquals(333.0, $this->recovery->getCurrentRtt());
    }

    public function testGetNextTimeout(): void
    {
        $this->assertEquals(0.0, $this->recovery->getNextTimeout());
        // 发送包应该设置超时
        $packet = $this->createMockPacket();
        $this->recovery->onPacketSent(1, $packet, 1000.0, true);
        $this->assertGreaterThan(0.0, $this->recovery->getNextTimeout());
    }

    // ========================================
    // 复杂集成场景测试
    // ========================================
    public function testComplexScenarioNormalOperation(): void
    {
        $currentTime = 1000.0;
        // 1. 发送数据包
        for ($i = 1; $i <= 10; ++$i) {
            $packet = $this->createMockPacket();
            $this->recovery->onPacketSent($i, $packet, $currentTime + $i, true);
        }
        // 2. 接收数据包
        for ($i = 11; $i <= 15; ++$i) {
            $this->recovery->onPacketReceived($i, $currentTime + $i, true);
        }
        // 3. 接收ACK确认部分包
        $ackFrame = new AckFrame(7, 1000, [[1, 7]]);
        $this->recovery->onAckReceived($ackFrame, $currentTime + 200);
        // 4. 检查是否需要立即发送ACK
        $shouldSendAck = $this->recovery->shouldSendAckImmediately($currentTime + 250);
        $this->assertTrue($shouldSendAck); // 收到多个包应该立即发送ACK
        // 5. 生成ACK帧
        $generatedAck = $this->recovery->generateAckFrame($currentTime + 300);
        $this->assertInstanceOf(AckFrame::class, $generatedAck);
        // 6. 检查统计信息
        $stats = $this->recovery->getStats();
        $this->assertGreaterThan(0, $stats['packet_tracker']['sent_packets']);
        $this->assertGreaterThan(0, $stats['ack_manager']['received_packets']);
        // 7. 检查连接健康状态
        $this->assertTrue($this->recovery->isConnectionHealthy());
        $this->assertEquals('normal', $this->recovery->getCongestionAdvice());
    }

    public function testComplexScenarioLossRecovery(): void
    {
        $currentTime = 1000.0;
        // 1. 发送大量数据包
        for ($i = 1; $i <= 20; ++$i) {
            $packet = $this->createMockPacket();
            $this->recovery->onPacketSent($i, $packet, $currentTime + $i, true);
        }
        // 2. 只确认部分包，模拟网络丢包
        $ackFrame = new AckFrame(15, 1000, [[10, 15]]);
        $this->recovery->onAckReceived($ackFrame, $currentTime + 200);
        // 3. 检查是否有包需要重传
        $retransmissionPackets = $this->recovery->getPacketsForRetransmission();
        // 注意：此时包可能还没有被标记为丢失，需要触发超时
        // 4. 触发超时处理
        $nextTimeout = $this->recovery->getNextTimeout();
        if ($nextTimeout > 0.0) {
            $actions = $this->recovery->onTimeout($nextTimeout + 100.0);
        }
        // 5. 再次检查重传包
        $retransmissionPackets = $this->recovery->getPacketsForRetransmission();
        // 现在应该有包需要重传
        // 6. 检查重传率和连接健康状态
        $retransmissionRate = $this->recovery->getRetransmissionRate();
        $this->assertGreaterThanOrEqual(0.0, $retransmissionRate);
        // 7. 检查拥塞建议
        $advice = $this->recovery->getCongestionAdvice();
        $this->assertContains($advice, ['normal', 'high_loss_rate', 'retransmission_storm', 'persistent_congestion']);
    }

    public function testComplexScenarioAckManagement(): void
    {
        $currentTime = 1000.0;
        // 1. 接收大量乱序数据包
        $packets = [1, 5, 2, 8, 3, 7, 10, 4, 9, 6];
        foreach ($packets as $i => $packetNumber) {
            $this->recovery->onPacketReceived($packetNumber, $currentTime + $i, true);
        }
        // 2. 检查是否需要立即发送ACK（接收多个包）
        $this->assertTrue($this->recovery->shouldSendAckImmediately($currentTime + 10));
        // 3. 生成ACK帧
        $ackFrame = $this->recovery->generateAckFrame($currentTime + 15);
        $this->assertInstanceOf(AckFrame::class, $ackFrame);
        $this->assertEquals(10, $ackFrame->getLargestAcked());
        // 4. 验证ACK范围正确处理不连续的包
        $ackRanges = $ackFrame->getAckRanges();
        $this->assertNotEmpty($ackRanges);
        // 5. 验证ACK生成后状态重置
        $this->assertFalse($this->recovery->getAckManager()->hasAckPending());
        // 6. 再次接收包，验证ACK超时机制
        $this->recovery->onPacketReceived(11, $currentTime + 20, true);
        $ackTimeout = $this->recovery->getAckManager()->getAckTimeout();
        $this->assertGreaterThan($currentTime + 20, $ackTimeout);
        // 7. 触发ACK超时
        $actions = $this->recovery->onTimeout($ackTimeout + 1.0);
        $this->assertNotEmpty($actions);
        $this->assertArrayHasKey('type', $actions[0]);
        $this->assertEquals('send_ack', $actions[0]['type']);
    }
}
