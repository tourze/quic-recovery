<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery\Tests;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Packets\Packet;
use Tourze\QUIC\Recovery\PacketTracker;

/**
 * PacketTracker 类测试
 */
final class PacketTrackerTest extends TestCase
{
    private PacketTracker $tracker;

    protected function setUp(): void
    {
        $this->tracker = new PacketTracker();
    }

    private function createMockPacket(int $size = 1200): Packet
    {
        /** @var Packet $packet */
        $packet = $this->createMock(Packet::class);
        $packet->method('getSize')->willReturn($size);
        return $packet;
    }

    public function testInitialState(): void
    {
        $this->assertEquals(-1, $this->tracker->getLargestAcked());
        $this->assertEquals(-1, $this->tracker->getLargestSent());
        $this->assertEquals(0, $this->tracker->getAckElicitingOutstanding());
        $this->assertEquals(0.0, $this->tracker->getTimeOfLastSentAckEliciting());
        $this->assertFalse($this->tracker->hasUnackedPackets());
    }

    public function testOnPacketSent_basicFunctionality(): void
    {
        $packet = $this->createMockPacket();
        $packetNumber = 1;
        $sentTime = 1000.0;
        
        $this->tracker->onPacketSent($packetNumber, $packet, $sentTime, true);
        
        $this->assertEquals($packetNumber, $this->tracker->getLargestSent());
        $this->assertEquals(1, $this->tracker->getAckElicitingOutstanding());
        $this->assertEquals($sentTime, $this->tracker->getTimeOfLastSentAckEliciting());
        $this->assertTrue($this->tracker->hasUnackedPackets());
    }

    public function testOnPacketSent_nonAckEliciting(): void
    {
        $packet = $this->createMockPacket();
        $packetNumber = 1;
        $sentTime = 1000.0;
        
        $this->tracker->onPacketSent($packetNumber, $packet, $sentTime, false);
        
        $this->assertEquals($packetNumber, $this->tracker->getLargestSent());
        $this->assertEquals(0, $this->tracker->getAckElicitingOutstanding());
        $this->assertEquals(0.0, $this->tracker->getTimeOfLastSentAckEliciting());
    }

    public function testOnPacketSent_multiplePackets(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $packet = $this->createMockPacket();
            $this->tracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        
        $this->assertEquals(5, $this->tracker->getLargestSent());
        $this->assertEquals(5, $this->tracker->getAckElicitingOutstanding());
        $this->assertEquals(1005.0, $this->tracker->getTimeOfLastSentAckEliciting());
    }

    public function testOnPacketSent_withNegativePacketNumber_throwsException(): void
    {
        $packet = $this->createMockPacket();
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('包号不能为负数');
        
        $this->tracker->onPacketSent(-1, $packet, 1000.0, true);
    }

    public function testOnAckReceived_singlePacket(): void
    {
        // 先发送一个包
        $packet = $this->createMockPacket();
        $this->tracker->onPacketSent(1, $packet, 1000.0, true);
        
        // 接收ACK
        $result = $this->tracker->onAckReceived([[1, 1]], 1100.0);
        
        $this->assertEquals([1], $result['newly_acked']);
        $this->assertTrue($result['ack_eliciting_acked']);
        $this->assertEquals(1, $this->tracker->getLargestAcked());
        $this->assertEquals(0, $this->tracker->getAckElicitingOutstanding());
    }

    public function testOnAckReceived_multiplePackets(): void
    {
        // 发送多个包
        for ($i = 1; $i <= 5; $i++) {
            $packet = $this->createMockPacket();
            $this->tracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        
        // 确认包1-3
        $result = $this->tracker->onAckReceived([[1, 3]], 1100.0);
        
        $this->assertEquals([1, 2, 3], $result['newly_acked']);
        $this->assertTrue($result['ack_eliciting_acked']);
        $this->assertEquals(3, $this->tracker->getLargestAcked());
        $this->assertEquals(2, $this->tracker->getAckElicitingOutstanding()); // 包4,5未确认
    }

    public function testOnAckReceived_multipleRanges(): void
    {
        // 发送包1-10
        for ($i = 1; $i <= 10; $i++) {
            $packet = $this->createMockPacket();
            $this->tracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        
        // 确认包1-3和包7-9
        $result = $this->tracker->onAckReceived([[1, 3], [7, 9]], 1100.0);
        
        $this->assertEquals([1, 2, 3, 7, 8, 9], $result['newly_acked']);
        $this->assertTrue($result['ack_eliciting_acked']);
        $this->assertEquals(9, $this->tracker->getLargestAcked());
        $this->assertEquals(4, $this->tracker->getAckElicitingOutstanding()); // 包4,5,6,10未确认
    }

    public function testOnAckReceived_duplicateAck(): void
    {
        // 发送包
        $packet = $this->createMockPacket();
        $this->tracker->onPacketSent(1, $packet, 1000.0, true);
        
        // 第一次ACK
        $result1 = $this->tracker->onAckReceived([[1, 1]], 1100.0);
        $this->assertEquals([1], $result1['newly_acked']);
        
        // 重复ACK
        $result2 = $this->tracker->onAckReceived([[1, 1]], 1200.0);
        $this->assertEquals([], $result2['newly_acked']);
        $this->assertFalse($result2['ack_eliciting_acked']);
    }

    public function testOnAckReceived_outOfOrderAck(): void
    {
        // 发送包1-5
        for ($i = 1; $i <= 5; $i++) {
            $packet = $this->createMockPacket();
            $this->tracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        
        // 先确认包5
        $result1 = $this->tracker->onAckReceived([[5, 5]], 1100.0);
        $this->assertEquals([5], $result1['newly_acked']);
        $this->assertEquals(5, $this->tracker->getLargestAcked());
        
        // 再确认包1-3
        $result2 = $this->tracker->onAckReceived([[1, 3]], 1200.0);
        $this->assertEquals([1, 2, 3], $result2['newly_acked']);
        $this->assertEquals(5, $this->tracker->getLargestAcked()); // 最大确认包号不变
    }

    public function testOnPacketLost_basicFunctionality(): void
    {
        // 发送包
        $packet = $this->createMockPacket();
        $this->tracker->onPacketSent(1, $packet, 1000.0, true);
        
        // 标记丢失
        $this->tracker->onPacketLost(1);
        
        $this->assertTrue($this->tracker->isLost(1));
        $this->assertEquals(0, $this->tracker->getAckElicitingOutstanding());
    }

    public function testOnPacketLost_nonExistentPacket(): void
    {
        // 标记不存在的包为丢失，应该不产生错误
        $this->tracker->onPacketLost(999);
        
        $this->assertFalse($this->tracker->isLost(999));
    }

    public function testOnPacketLost_alreadyAckedPacket(): void
    {
        // 发送包
        $packet = $this->createMockPacket();
        $this->tracker->onPacketSent(1, $packet, 1000.0, true);
        
        // 确认包
        $this->tracker->onAckReceived([[1, 1]], 1100.0);
        
        // 尝试标记已确认的包为丢失
        $this->tracker->onPacketLost(1);
        
        $this->assertTrue($this->tracker->isAcked(1));
        $this->assertFalse($this->tracker->isLost(1));
    }

    public function testOnPacketLost_duplicateLoss(): void
    {
        // 发送包
        $packet = $this->createMockPacket();
        $this->tracker->onPacketSent(1, $packet, 1000.0, true);
        
        // 第一次标记丢失
        $this->tracker->onPacketLost(1);
        $outstanding1 = $this->tracker->getAckElicitingOutstanding();
        
        // 重复标记丢失
        $this->tracker->onPacketLost(1);
        $outstanding2 = $this->tracker->getAckElicitingOutstanding();
        
        $this->assertEquals($outstanding1, $outstanding2); // 计数不应该重复减少
    }

    public function testDetectLostPackets_packetNumberThreshold(): void
    {
        // 发送包1-10
        for ($i = 1; $i <= 10; $i++) {
            $packet = $this->createMockPacket();
            $this->tracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        
        // 确认包7-10
        $this->tracker->onAckReceived([[7, 10]], 1200.0);
        
        // 检测丢包（基于包号差异，阈值为3）
        $lostPackets = $this->tracker->detectLostPackets(1000.0, 1300.0);
        
        // 包1-4应该被认为丢失（10-1 >= 3, 10-2 >= 3, 10-3 >= 3, 10-4 >= 3）
        $this->assertContains(1, $lostPackets);
        $this->assertContains(2, $lostPackets);
        $this->assertContains(3, $lostPackets);
        $this->assertContains(4, $lostPackets);
    }

    public function testDetectLostPackets_timeThreshold(): void
    {
        // 发送包1-3
        for ($i = 1; $i <= 3; $i++) {
            $packet = $this->createMockPacket();
            $this->tracker->onPacketSent($i, $packet, 1000.0 + $i * 10, true);
        }
        
        // 确认包3
        $this->tracker->onAckReceived([[3, 3]], 1200.0);
        
        // 检测丢包（基于时间阈值）
        $currentTime = 2000.0; // 距离发送时间很久
        $lossThreshold = 500.0; // 丢包时间阈值
        $lostPackets = $this->tracker->detectLostPackets($lossThreshold, $currentTime);
        
        // 包1,2应该因为超时被认为丢失
        $this->assertContains(1, $lostPackets);
        $this->assertContains(2, $lostPackets);
    }

    public function testDetectLostPackets_noLargestAcked(): void
    {
        // 发送包但没有确认任何包
        $packet = $this->createMockPacket();
        $this->tracker->onPacketSent(1, $packet, 1000.0, true);
        
        $lostPackets = $this->tracker->detectLostPackets(500.0, 2000.0);
        
        $this->assertEmpty($lostPackets);
    }

    public function testGetPacketsForRetransmission(): void
    {
        // 发送包1-3
        for ($i = 1; $i <= 3; $i++) {
            $packet = $this->createMockPacket();
            $this->tracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        
        // 标记包1,3为丢失
        $this->tracker->onPacketLost(1);
        $this->tracker->onPacketLost(3);
        
        $retransmissionPackets = $this->tracker->getPacketsForRetransmission();
        
        $this->assertCount(2, $retransmissionPackets);
        $packetNumbers = array_map(fn($info) => $info->getPacketNumber(), $retransmissionPackets);
        $this->assertContains(1, $packetNumbers);
        $this->assertContains(3, $packetNumbers);
    }

    public function testCleanupAckedPackets(): void
    {
        // 发送包1-3
        for ($i = 1; $i <= 3; $i++) {
            $packet = $this->createMockPacket();
            $this->tracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        
        // 确认包1,2
        $this->tracker->onAckReceived([[1, 2]], 1200.0);
        
        $stats1 = $this->tracker->getStats();
        $sentCountBefore = $stats1['sent_packets'];
        
        // 清理已确认的包
        $this->tracker->cleanupAckedPackets();
        
        $stats2 = $this->tracker->getStats();
        $sentCountAfter = $stats2['sent_packets'];
        
        $this->assertEquals($sentCountBefore - 2, $sentCountAfter); // 应该减少2个包的记录
    }

    public function testGetSentPackets(): void
    {
        // 发送包1-3
        for ($i = 1; $i <= 3; $i++) {
            $packet = $this->createMockPacket($i * 100);
            $this->tracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        
        $sentPackets = $this->tracker->getSentPackets();
        
        $this->assertCount(3, $sentPackets);
        $this->assertArrayHasKey(1, $sentPackets);
        $this->assertArrayHasKey(2, $sentPackets);
        $this->assertArrayHasKey(3, $sentPackets);
        
        $this->assertEquals(100, $sentPackets[1]->getSize());
        $this->assertEquals(200, $sentPackets[2]->getSize());
        $this->assertEquals(300, $sentPackets[3]->getSize());
    }

    public function testGetUnackedPackets(): void
    {
        // 发送包1-5
        for ($i = 1; $i <= 5; $i++) {
            $packet = $this->createMockPacket();
            $this->tracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        
        // 确认包2,4
        $this->tracker->onAckReceived([[2, 2], [4, 4]], 1200.0);
        
        // 标记包5为丢失
        $this->tracker->onPacketLost(5);
        
        $unackedPackets = $this->tracker->getUnackedPackets();
        
        // 只有包1,3应该在未确认列表中（包2,4已确认，包5已丢失）
        $this->assertCount(2, $unackedPackets);
        $packetNumbers = array_map(fn($info) => $info->getPacketNumber(), $unackedPackets);
        $this->assertContains(1, $packetNumbers);
        $this->assertContains(3, $packetNumbers);
    }

    public function testIsAcked(): void
    {
        $packet = $this->createMockPacket();
        $this->tracker->onPacketSent(1, $packet, 1000.0, true);
        
        $this->assertFalse($this->tracker->isAcked(1));
        
        $this->tracker->onAckReceived([[1, 1]], 1100.0);
        
        $this->assertTrue($this->tracker->isAcked(1));
    }

    public function testIsLost(): void
    {
        $packet = $this->createMockPacket();
        $this->tracker->onPacketSent(1, $packet, 1000.0, true);
        
        $this->assertFalse($this->tracker->isLost(1));
        
        $this->tracker->onPacketLost(1);
        
        $this->assertTrue($this->tracker->isLost(1));
    }

    public function testGetStats(): void
    {
        // 发送3个包
        for ($i = 1; $i <= 3; $i++) {
            $packet = $this->createMockPacket();
            $this->tracker->onPacketSent($i, $packet, 1000.0 + $i, true);
        }
        
        // 确认1个包
        $this->tracker->onAckReceived([[1, 1]], 1200.0);
        
        // 标记1个包为丢失
        $this->tracker->onPacketLost(3);
        
        $stats = $this->tracker->getStats();
        
        $this->assertIsArray($stats);
        $this->assertEquals(3, $stats['sent_packets']);
        $this->assertEquals(1, $stats['acked_packets']);
        $this->assertEquals(1, $stats['lost_packets']);
        $this->assertEquals(1, $stats['largest_acked']);
        $this->assertEquals(3, $stats['largest_sent']);
        $this->assertEquals(1, $stats['ack_eliciting_outstanding']); // 只有包2未确认且未丢失
    }
} 