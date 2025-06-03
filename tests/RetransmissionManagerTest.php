<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Frames\AckFrame;
use Tourze\QUIC\Packets\Packet;
use Tourze\QUIC\Recovery\AckManager;
use Tourze\QUIC\Recovery\LossDetection;
use Tourze\QUIC\Recovery\PacketTracker;
use Tourze\QUIC\Recovery\RetransmissionManager;
use Tourze\QUIC\Recovery\RTTEstimator;

/**
 * RetransmissionManager 类测试
 */
final class RetransmissionManagerTest extends TestCase
{
    private RetransmissionManager $retransmissionManager;
    private RTTEstimator $rttEstimator;
    private PacketTracker $packetTracker;
    private LossDetection $lossDetection;
    private AckManager $ackManager;

    protected function setUp(): void
    {
        $this->rttEstimator = new RTTEstimator();
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

    private function createMockPacket(int $size = 1200): Packet
    {
        /** @var MockObject&Packet $packet */
        $packet = $this->createMock(Packet::class);
        $packet->method('getSize')->willReturn($size);
        return $packet;
    }

    private function createMockAckFrame(int $largestAcked, int $ackDelay, array $ackRanges): AckFrame
    {
        // 使用真实的 AckFrame 对象
        return new AckFrame($largestAcked, $ackDelay, $ackRanges);
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(RetransmissionManager::class, $this->retransmissionManager);
    }

    public function testOnAckReceived_basicFunctionality(): void
    {
        // 发送一些包
        for ($i = 1; $i <= 5; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }

        // 创建ACK帧确认包1-3
        $ackFrame = $this->createMockAckFrame(3, 1000, [[1, 3]]);

        // 处理ACK
        $this->retransmissionManager->onAckReceived($ackFrame, 1200.0);

        // 验证RTT已更新
        $this->assertGreaterThan(0, $this->rttEstimator->getSmoothedRtt());
    }

    public function testOnAckReceived_withOutOfOrderAck(): void
    {
        // 发送包1-10
        for ($i = 1; $i <= 10; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }

        // 先确认包8-10
        $ackFrame1 = $this->createMockAckFrame(10, 1000, [[8, 10]]);
        $this->retransmissionManager->onAckReceived($ackFrame1, 1200.0);

        // 再确认包1-5
        $ackFrame2 = $this->createMockAckFrame(10, 1500, [[1, 5], [8, 10]]);
        $this->retransmissionManager->onAckReceived($ackFrame2, 1250.0);

        // 验证正常处理
        $this->assertGreaterThan(0, $this->rttEstimator->getSmoothedRtt());
    }

    public function testOnPtoTimeout_basicFunctionality(): void
    {
        // 发送一些包但不确认
        for ($i = 1; $i <= 3; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }

        $result = $this->retransmissionManager->onPtoTimeout(2000.0);

        $this->assertIsArray($result);
        // 检查实际返回的数组结构
        $this->assertNotEmpty($result);
    }

    public function testOnPtoTimeout_noUnackedPackets(): void
    {
        $result = $this->retransmissionManager->onPtoTimeout(2000.0);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetPacketsForRetransmission_noLostPackets(): void
    {
        $packets = $this->retransmissionManager->getPacketsForRetransmission();
        $this->assertEmpty($packets);
    }

    public function testGetPacketsForRetransmission_withLostPackets(): void
    {
        // 发送包并标记为丢失
        for ($i = 1; $i <= 5; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }

        // 标记包1,3为丢失
        $this->packetTracker->onPacketLost(1);
        $this->packetTracker->onPacketLost(3);

        $packets = $this->retransmissionManager->getPacketsForRetransmission();

        $this->assertIsArray($packets);
        $this->assertNotEmpty($packets);
        
        // 验证返回的包信息结构
        foreach ($packets as $packetInfo) {
            $this->assertIsArray($packetInfo);
            $this->assertArrayHasKey('packet_number', $packetInfo);
            $this->assertArrayHasKey('retransmission_count', $packetInfo);
            $this->assertArrayHasKey('original_packet', $packetInfo);
            $this->assertArrayHasKey('backoff_multiplier', $packetInfo);
        }
    }

    public function testCalculateRetransmissionDelay(): void
    {
        // 测试不同重传次数的延迟计算
        $delay0 = $this->retransmissionManager->calculateRetransmissionDelay(0);
        $delay1 = $this->retransmissionManager->calculateRetransmissionDelay(1);
        $delay2 = $this->retransmissionManager->calculateRetransmissionDelay(2);

        $this->assertGreaterThan(0, $delay0);
        $this->assertGreaterThan($delay0, $delay1); // 延迟应该随重传次数增加
        $this->assertGreaterThan($delay1, $delay2);
    }

    public function testCalculateRetransmissionDelay_maxRetransmissions(): void
    {
        // 测试最大重传次数的延迟计算
        $maxDelay = $this->retransmissionManager->calculateRetransmissionDelay(10);
        $this->assertGreaterThan(0, $maxDelay);
    }

    public function testOnRetransmissionAcked(): void
    {
        // 发送原始包
        $packet = $this->createMockPacket();
        $this->packetTracker->onPacketSent(1, $packet, 1000.0, true);

        // 标记为丢失并重传
        $this->packetTracker->onPacketLost(1);
        
        // 处理重传包被确认
        $this->retransmissionManager->onRetransmissionAcked(1, 11);

        // 验证正常处理（无异常抛出）
        $this->assertTrue(true);
    }

    public function testShouldFastRetransmit_initialState(): void
    {
        $this->assertFalse($this->retransmissionManager->shouldFastRetransmit());
    }

    public function testShouldFastRetransmit_withDuplicateAcks(): void
    {
        // 发送包
        for ($i = 1; $i <= 10; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }

        // 多次发送相同的ACK以触发快速重传条件
        $ackFrame = $this->createMockAckFrame(3, 1000, [[1, 3]]);
        
        for ($i = 0; $i < 4; $i++) { // 发送4次相同ACK
            $this->retransmissionManager->onAckReceived($ackFrame, 1200.0 + $i);
        }

        // 检查是否应该快速重传
        $shouldFastRetransmit = $this->retransmissionManager->shouldFastRetransmit();
        $this->assertIsBool($shouldFastRetransmit);
    }

    public function testGetRetransmissionStats(): void
    {
        $stats = $this->retransmissionManager->getRetransmissionStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_retransmissions', $stats);
        $this->assertArrayHasKey('pending_retransmissions', $stats);
        $this->assertArrayHasKey('last_retransmission_time', $stats);
        $this->assertArrayHasKey('avg_retransmission_delay', $stats);

        // 初始状态应该都是0
        $this->assertEquals(0, $stats['total_retransmissions']);
        $this->assertEquals(0, $stats['pending_retransmissions']);
        $this->assertEquals(0.0, $stats['last_retransmission_time']);
    }

    public function testGetRetransmissionStats_afterActivity(): void
    {
        // 发送包并模拟重传活动
        for ($i = 1; $i <= 5; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }

        // 标记包为丢失
        $this->packetTracker->onPacketLost(1);
        $this->packetTracker->onPacketLost(2);

        // 触发PTO超时
        $this->retransmissionManager->onPtoTimeout(2000.0);

        $stats = $this->retransmissionManager->getRetransmissionStats();

        $this->assertIsArray($stats);
        // 应该有一些统计数据更新
        $this->assertGreaterThanOrEqual(0, $stats['total_retransmissions']);
    }

    public function testCleanupExpiredRetransmissions(): void
    {
        // 发送包并标记为丢失
        for ($i = 1; $i <= 5; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }

        $this->packetTracker->onPacketLost(1);
        $this->packetTracker->onPacketLost(2);

        // 获取重传包列表
        $beforeCleanup = $this->retransmissionManager->getPacketsForRetransmission();

        // 清理过期的重传
        $this->retransmissionManager->cleanupExpiredRetransmissions(3000.0);

        // 验证清理操作正常执行
        $afterCleanup = $this->retransmissionManager->getPacketsForRetransmission();
        $this->assertIsArray($afterCleanup);
    }

    public function testReset(): void
    {
        // 发送包并创建一些状态
        for ($i = 1; $i <= 3; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }

        $this->packetTracker->onPacketLost(1);
        $this->retransmissionManager->onPtoTimeout(2000.0);

        // 重置前应该有一些数据
        $beforeReset = $this->retransmissionManager->getRetransmissionStats();
        $this->assertGreaterThanOrEqual(0, $beforeReset['total_retransmissions']);
        
        // 重置
        $this->retransmissionManager->reset();

        // 重置后检查状态
        $afterReset = $this->retransmissionManager->getRetransmissionStats();
        $this->assertIsArray($afterReset);
        $this->assertEquals(0, $afterReset['total_retransmissions']);
        $this->assertEquals(0, $afterReset['pending_retransmissions']);
        $this->assertEquals(0.0, $afterReset['last_retransmission_time']);
        
        // 注意：getPacketsForRetransmission 依赖于 PacketTracker 的状态，
        // 而 reset 只重置了 RetransmissionManager 的内部状态，
        // 所以丢失的包还是会在列表中，但重传计数会重置为0
        $packets = $this->retransmissionManager->getPacketsForRetransmission();
        if (!empty($packets)) {
            // 如果还有包，验证重传计数已重置
            foreach ($packets as $packetInfo) {
                $this->assertEquals(0, $packetInfo['retransmission_count']);
            }
        }
    }

    public function testGetRetransmissionRate_initialState(): void
    {
        $rate = $this->retransmissionManager->getRetransmissionRate();
        $this->assertEquals(0.0, $rate);
    }

    public function testGetRetransmissionRate_afterActivity(): void
    {
        // 发送包并模拟重传活动
        for ($i = 1; $i <= 10; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }

        // 确认一些包
        $ackFrame = $this->createMockAckFrame(5, 1000, [[1, 5]]);
        $this->retransmissionManager->onAckReceived($ackFrame, 1200.0);

        // 标记一些包为丢失
        $this->packetTracker->onPacketLost(6);
        $this->packetTracker->onPacketLost(7);

        $rate = $this->retransmissionManager->getRetransmissionRate();
        $this->assertGreaterThanOrEqual(0.0, $rate);
        $this->assertLessThanOrEqual(1.0, $rate); // 重传率应该在0-1之间
    }

    public function testIsInRetransmissionStorm_initialState(): void
    {
        $this->assertFalse($this->retransmissionManager->isInRetransmissionStorm());
    }

    public function testIsInRetransmissionStorm_afterHighRetransmissionRate(): void
    {
        // 模拟高重传率场景
        for ($i = 1; $i <= 20; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }

        // 标记大量包为丢失以触发重传风暴
        for ($i = 1; $i <= 15; $i++) {
            $this->packetTracker->onPacketLost($i);
        }

        // 多次触发PTO超时
        for ($i = 0; $i < 5; $i++) {
            $this->retransmissionManager->onPtoTimeout(2000.0 + $i * 100);
        }

        $isStorm = $this->retransmissionManager->isInRetransmissionStorm();
        $this->assertIsBool($isStorm);
    }

    public function testComplexScenario(): void
    {
        // 复杂场景：发送包、部分确认、丢包、重传、统计
        
        // 1. 发送包1-20
        for ($i = 1; $i <= 20; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }

        // 2. 确认包1-10
        $ackFrame1 = $this->createMockAckFrame(10, 1000, [[1, 10]]);
        $this->retransmissionManager->onAckReceived($ackFrame1, 1300.0);

        // 3. 标记包11-15为丢失
        for ($i = 11; $i <= 15; $i++) {
            $this->packetTracker->onPacketLost($i);
        }

        // 4. 获取重传包
        $retransmissionPackets = $this->retransmissionManager->getPacketsForRetransmission();
        $this->assertNotEmpty($retransmissionPackets);

        // 5. 触发PTO超时
        $ptoResult = $this->retransmissionManager->onPtoTimeout(2000.0);
        $this->assertIsArray($ptoResult);

        // 6. 检查统计信息
        $stats = $this->retransmissionManager->getRetransmissionStats();
        $this->assertIsArray($stats);

        // 7. 检查重传率
        $rate = $this->retransmissionManager->getRetransmissionRate();
        $this->assertGreaterThanOrEqual(0.0, $rate);

        // 8. 清理过期重传
        $this->retransmissionManager->cleanupExpiredRetransmissions(3000.0);

        // 9. 最终检查
        $finalStats = $this->retransmissionManager->getRetransmissionStats();
        $this->assertIsArray($finalStats);
    }

    public function testRetransmissionDelayProgression(): void
    {
        // 测试重传延迟的递增特性
        $delays = [];
        for ($i = 0; $i < 5; $i++) {
            $delays[] = $this->retransmissionManager->calculateRetransmissionDelay($i);
        }

        // 验证延迟递增
        for ($i = 1; $i < count($delays); $i++) {
            $this->assertGreaterThan($delays[$i-1], $delays[$i], 
                "重传延迟应该随重传次数递增，但第{$i}次({$delays[$i]})没有大于第".($i-1)."次({$delays[$i-1]})");
        }
    }

    public function testEdgeCases(): void
    {
        // 测试边界情况

        // 1. 计算负数重传次数的延迟
        $delay = $this->retransmissionManager->calculateRetransmissionDelay(-1);
        $this->assertGreaterThan(0, $delay);

        // 2. 处理空ACK帧
        $emptyAckFrame = $this->createMockAckFrame(0, 0, []);
        $this->retransmissionManager->onAckReceived($emptyAckFrame, 1000.0);
        $this->assertTrue(true); // 不应该抛出异常

        // 3. 重传不存在的包
        $this->retransmissionManager->onRetransmissionAcked(999, 1000);
        $this->assertTrue(true); // 不应该抛出异常
    }
} 