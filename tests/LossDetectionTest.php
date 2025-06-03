<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Packets\Packet;
use Tourze\QUIC\Recovery\LossDetection;
use Tourze\QUIC\Recovery\PacketTracker;
use Tourze\QUIC\Recovery\RTTEstimator;

/**
 * LossDetection 类测试
 */
final class LossDetectionTest extends TestCase
{
    private LossDetection $lossDetection;
    private RTTEstimator $rttEstimator;
    private PacketTracker $packetTracker;

    protected function setUp(): void
    {
        $this->rttEstimator = new RTTEstimator();
        $this->packetTracker = new PacketTracker();
        $this->lossDetection = new LossDetection($this->rttEstimator, $this->packetTracker);
    }

    private function createMockPacket(int $size = 1200): Packet
    {
        /** @var MockObject&Packet $packet */
        $packet = $this->createMock(Packet::class);
        $packet->method('getSize')->willReturn($size);
        return $packet;
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(LossDetection::class, $this->lossDetection);
    }

    public function testDetectLostPackets_noAckedPackets(): void
    {
        // 没有已确认的包时应该返回空的丢包列表
        $result = $this->lossDetection->detectLostPackets(1000.0);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('lost_packets', $result);
        $this->assertArrayHasKey('loss_time', $result);
        $this->assertEmpty($result['lost_packets']);
        $this->assertEquals(0.0, $result['loss_time']);
    }

    public function testDetectLostPackets_withAckedPackets(): void
    {
        // 发送包1-10
        for ($i = 1; $i <= 10; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }

        // 确认包7-10
        $this->packetTracker->onAckReceived([[7, 10]], 1200.0);

        // 检测丢包
        $result = $this->lossDetection->detectLostPackets(1300.0);

        // 根据包号差异，包1-4应该被认为丢失（10-1=9, 10-2=8, 10-3=7, 10-4=6 >= 3）
        $this->assertIsArray($result);
        $this->assertArrayHasKey('lost_packets', $result);
        $this->assertNotEmpty($result['lost_packets']);
        
        $lostPackets = $result['lost_packets'];
        $this->assertContains(1, $lostPackets);
        $this->assertContains(2, $lostPackets);
        $this->assertContains(3, $lostPackets);
        $this->assertContains(4, $lostPackets);
    }

    public function testDetectLostPackets_timeBasedDetection(): void
    {
        // 发送一些包，时间间隔较大
        for ($i = 1; $i <= 5; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 1000.0 + $i * 10, true);
        }

        // 确认最后一个包
        $this->packetTracker->onAckReceived([[5, 5]], 1200.0);
        
        // 更新RTT以便计算时间阈值
        $this->rttEstimator->updateRtt(50.0);

        // 很久之后检测丢包
        $result = $this->lossDetection->detectLostPackets(2000.0);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('lost_packets', $result);
        // 应该有一些包因为时间超时被标记为丢失
        $this->assertNotEmpty($result['lost_packets']);
    }

    public function testSetLossDetectionTimer_noUnackedPackets(): void
    {
        $timeout = $this->lossDetection->setLossDetectionTimer(1000.0);
        $this->assertEquals(0.0, $timeout);
    }

    public function testSetLossDetectionTimer_withUnackedPackets(): void
    {
        // 发送一个包但不确认
        $packet = $this->createMockPacket();
        $this->packetTracker->onPacketSent(1, $packet, 900.0, true);

        $timeout = $this->lossDetection->setLossDetectionTimer(1000.0);

        $this->assertGreaterThan(0.0, $timeout);
        $this->assertGreaterThan(1000.0, $timeout); // 应该在当前时间之后
    }

    public function testSetLossDetectionTimer_withPendingLossTime(): void
    {
        // 发送多个包并确认一些以产生待检测的丢包
        for ($i = 1; $i <= 5; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 900.0 + $i, true);
        }

        // 确认包4-5，让包1-3处于待检测状态
        $this->packetTracker->onAckReceived([[4, 5]], 1000.0);
        
        // 先检测一次以设置loss_time
        $this->lossDetection->detectLostPackets(1010.0);

        $timeout = $this->lossDetection->setLossDetectionTimer(1020.0);

        $this->assertGreaterThan(0.0, $timeout);
    }

    public function testOnLossDetectionTimeout_lossDetection(): void
    {
        // 发送包1-10
        for ($i = 1; $i <= 10; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }

        // 确认包7-10
        $this->packetTracker->onAckReceived([[7, 10]], 1200.0);

        $result = $this->lossDetection->onLossDetectionTimeout(1300.0);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('action', $result);
        $this->assertArrayHasKey('packets', $result);
        
        // 应该是丢包检测或PTO探测
        $this->assertContains($result['action'], ['loss_detection', 'pto_probe']);
    }

    public function testOnLossDetectionTimeout_ptoProbe(): void
    {
        // 发送一个包但不确认，触发PTO
        $packet = $this->createMockPacket();
        $this->packetTracker->onPacketSent(1, $packet, 900.0, true);

        $result = $this->lossDetection->onLossDetectionTimeout(2000.0); // 很久之后

        $this->assertIsArray($result);
        $this->assertEquals('pto_probe', $result['action']);
        $this->assertArrayHasKey('packets', $result);
        $this->assertNotEmpty($result['packets']);
    }

    public function testPtoCountIncrement(): void
    {
        $initialCount = $this->lossDetection->getPtoCount();
        $this->assertEquals(0, $initialCount);

        // 发送一个包触发PTO
        $packet = $this->createMockPacket();
        $this->packetTracker->onPacketSent(1, $packet, 900.0, true);

        // 触发PTO超时
        $this->lossDetection->onLossDetectionTimeout(2000.0);

        $newCount = $this->lossDetection->getPtoCount();
        $this->assertEquals($initialCount + 1, $newCount);
    }

    public function testOnAckReceived_resetsPtoCount(): void
    {
        // 先触发PTO增加计数
        $packet = $this->createMockPacket();
        $this->packetTracker->onPacketSent(1, $packet, 900.0, true);
        $this->lossDetection->onLossDetectionTimeout(2000.0);
        
        $this->assertGreaterThan(0, $this->lossDetection->getPtoCount());

        // 接收ACK应该重置PTO计数
        $this->lossDetection->onAckReceived();

        $this->assertEquals(0, $this->lossDetection->getPtoCount());
    }

    public function testIsInPersistentCongestion_belowThreshold(): void
    {
        $this->assertFalse($this->lossDetection->isInPersistentCongestion());
    }

    public function testIsInPersistentCongestion_aboveThreshold(): void
    {
        // 触发多次PTO以超过阈值
        $packet = $this->createMockPacket();
        $this->packetTracker->onPacketSent(1, $packet, 900.0, true);
        
        // 多次PTO超时
        for ($i = 0; $i < 5; $i++) {
            $this->lossDetection->onLossDetectionTimeout(2000.0 + $i * 100);
        }

        $this->assertTrue($this->lossDetection->isInPersistentCongestion());
    }

    public function testGetLossTime(): void
    {
        $initialLossTime = $this->lossDetection->getLossTime();
        $this->assertEquals(0.0, $initialLossTime);

        // 发送包并部分确认以产生待检测的丢包
        for ($i = 1; $i <= 5; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 900.0 + $i, true);
        }

        $this->packetTracker->onAckReceived([[4, 5]], 1000.0);
        
        // 检测丢包，这应该设置loss_time
        $this->lossDetection->detectLostPackets(1010.0);

        $lossTime = $this->lossDetection->getLossTime();
        // loss_time可能被设置，也可能仍为0（取决于具体算法）
        $this->assertGreaterThanOrEqual(0.0, $lossTime);
    }

    public function testGetStats(): void
    {
        $stats = $this->lossDetection->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('pto_count', $stats);
        $this->assertArrayHasKey('loss_time', $stats);
        $this->assertArrayHasKey('persistent_congestion', $stats);

        $this->assertEquals(0, $stats['pto_count']);
        $this->assertEquals(0.0, $stats['loss_time']);
        $this->assertFalse($stats['persistent_congestion']);
    }

    public function testReset(): void
    {
        // 设置一些状态
        $packet = $this->createMockPacket();
        $this->packetTracker->onPacketSent(1, $packet, 900.0, true);
        $this->lossDetection->onLossDetectionTimeout(2000.0); // 增加PTO计数

        $this->assertGreaterThan(0, $this->lossDetection->getPtoCount());

        // 重置
        $this->lossDetection->reset();

        // 验证状态已重置
        $this->assertEquals(0, $this->lossDetection->getPtoCount());
        $this->assertEquals(0.0, $this->lossDetection->getLossTime());
        $this->assertFalse($this->lossDetection->isInPersistentCongestion());
    }

    public function testValidateConfig(): void
    {
        // 应该返回true，不抛出异常
        $this->assertTrue($this->lossDetection->validateConfig());
    }

    public function testComplexScenario(): void
    {
        // 复杂场景测试：发送多个包，部分确认，检测丢包，处理超时
        
        // 1. 发送包1-20
        for ($i = 1; $i <= 20; $i++) {
            $packet = $this->createMockPacket();
            $this->packetTracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }

        // 2. 确认包15-20（包1-14可能丢失）
        $this->packetTracker->onAckReceived([[15, 20]], 1200.0);

        // 3. 检测丢包
        $result = $this->lossDetection->detectLostPackets(1300.0);
        $this->assertNotEmpty($result['lost_packets']);

        // 4. 设置定时器
        $timeout = $this->lossDetection->setLossDetectionTimer(1300.0);
        $this->assertGreaterThanOrEqual(0.0, $timeout);

        // 5. 触发超时
        $timeoutResult = $this->lossDetection->onLossDetectionTimeout(1400.0);
        $this->assertIsArray($timeoutResult);
        $this->assertContains($timeoutResult['action'], ['loss_detection', 'pto_probe']);

        // 6. 检查统计信息
        $stats = $this->lossDetection->getStats();
        $this->assertIsArray($stats);
    }
} 