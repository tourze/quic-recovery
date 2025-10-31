<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Frames\AckFrame;
use Tourze\QUIC\Recovery\AckManager;

/**
 * AckManager 类测试
 *
 * @internal
 */
#[CoversClass(AckManager::class)]
final class AckManagerTest extends TestCase
{
    private AckManager $ackManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ackManager = new AckManager();
    }

    public function testInitialState(): void
    {
        $this->assertEquals(-1, $this->ackManager->getLargestReceived());
        $this->assertEquals(0, $this->ackManager->getPendingAckCount());
        $this->assertFalse($this->ackManager->hasAckPending());
        $this->assertEquals(0.0, $this->ackManager->getAckTimeout());
    }

    public function testOnPacketReceivedBasicFunctionality(): void
    {
        $packetNumber = 1;
        $receiveTime = 1000.0;

        $this->ackManager->onPacketReceived($packetNumber, $receiveTime, true);

        $this->assertEquals($packetNumber, $this->ackManager->getLargestReceived());
        $this->assertEquals(1, $this->ackManager->getPendingAckCount());
        $this->assertTrue($this->ackManager->hasAckPending());
        $this->assertEquals($receiveTime + 25.0, $this->ackManager->getAckTimeout()); // 25ms MAX_ACK_DELAY
    }

    public function testOnPacketReceivedNonAckEliciting(): void
    {
        $packetNumber = 1;
        $receiveTime = 1000.0;

        $this->ackManager->onPacketReceived($packetNumber, $receiveTime, false);

        $this->assertEquals($packetNumber, $this->ackManager->getLargestReceived());
        $this->assertEquals(1, $this->ackManager->getPendingAckCount());
        $this->assertTrue($this->ackManager->hasAckPending()); // 因为有packetsToAck，hasAckPending为true
        $this->assertEquals(0.0, $this->ackManager->getAckTimeout());
    }

    public function testOnPacketReceivedMultiplePackets(): void
    {
        // 接收多个包
        for ($i = 1; $i <= 5; ++$i) {
            $this->ackManager->onPacketReceived($i, 1000.0 + $i, true);
        }

        $this->assertEquals(5, $this->ackManager->getLargestReceived());
        $this->assertEquals(5, $this->ackManager->getPendingAckCount());
        $this->assertTrue($this->ackManager->hasAckPending());
    }

    public function testOnPacketReceivedOutOfOrder(): void
    {
        // 先接收包5
        $this->ackManager->onPacketReceived(5, 1000.0, true);
        $this->assertEquals(5, $this->ackManager->getLargestReceived());

        // 再接收包1-3
        $this->ackManager->onPacketReceived(1, 1001.0, true);
        $this->ackManager->onPacketReceived(2, 1002.0, true);
        $this->ackManager->onPacketReceived(3, 1003.0, true);

        // 最大接收包号应该保持为5
        $this->assertEquals(5, $this->ackManager->getLargestReceived());
        $this->assertEquals(4, $this->ackManager->getPendingAckCount());
    }

    public function testOnPacketReceivedDuplicatePacket(): void
    {
        $packetNumber = 1;
        $receiveTime = 1000.0;

        // 第一次接收
        $this->ackManager->onPacketReceived($packetNumber, $receiveTime, true);
        $pendingCount1 = $this->ackManager->getPendingAckCount();

        // 重复接收（应该被忽略）
        $this->ackManager->onPacketReceived($packetNumber, $receiveTime + 100, true);
        $pendingCount2 = $this->ackManager->getPendingAckCount();

        $this->assertEquals($pendingCount1, $pendingCount2);
    }

    public function testOnPacketReceivedWithNegativePacketNumberThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('包号不能为负数');

        $this->ackManager->onPacketReceived(-1, 1000.0, true);
    }

    public function testShouldSendAckImmediatelyFrequencyThreshold(): void
    {
        $currentTime = 1000.0;

        // 接收1个ACK引发包，不应该立即发送
        $this->ackManager->onPacketReceived(1, $currentTime, true);
        $this->assertFalse($this->ackManager->shouldSendAckImmediately($currentTime));

        // 接收第2个ACK引发包，应该立即发送
        $this->ackManager->onPacketReceived(2, $currentTime + 1, true);
        $this->assertTrue($this->ackManager->shouldSendAckImmediately($currentTime + 1));
    }

    public function testShouldSendAckImmediatelyTimeout(): void
    {
        $currentTime = 1000.0;

        // 接收一个ACK引发包
        $this->ackManager->onPacketReceived(1, $currentTime, true);

        // 超时前不应该立即发送
        $this->assertFalse($this->ackManager->shouldSendAckImmediately($currentTime + 20.0));

        // 超时后应该立即发送
        $this->assertTrue($this->ackManager->shouldSendAckImmediately($currentTime + 30.0));
    }

    public function testGenerateAckFrameSinglePacket(): void
    {
        $this->ackManager->onPacketReceived(1, 1000.0, true);

        $ackFrame = $this->ackManager->generateAckFrame(1002.0);

        $this->assertInstanceOf(AckFrame::class, $ackFrame);
        $this->assertEquals(1, $ackFrame->getLargestAcked());
        $this->assertEquals(2000, $ackFrame->getAckDelay()); // 2ms * 1000 = 2000微秒

        // 生成ACK后应该重置状态
        $this->assertEquals(0, $this->ackManager->getPendingAckCount());
        $this->assertFalse($this->ackManager->hasAckPending());
    }

    public function testGenerateAckFrameMultiplePackets(): void
    {
        // 接收连续的包1-5
        for ($i = 1; $i <= 5; ++$i) {
            $this->ackManager->onPacketReceived($i, 1000.0 + $i, true);
        }

        $ackFrame = $this->ackManager->generateAckFrame(1010.0);

        $this->assertInstanceOf(AckFrame::class, $ackFrame);
        $this->assertEquals(5, $ackFrame->getLargestAcked());

        $ackRanges = $ackFrame->getAckRanges();
        $this->assertCount(1, $ackRanges); // 应该是一个连续范围
        $this->assertEquals([1, 5], $ackRanges[0]); // 格式是 [start, end]
    }

    public function testGenerateAckFrameWithGaps(): void
    {
        // 接收包1,2,3,7,8,9
        $this->ackManager->onPacketReceived(1, 1000.0, true);
        $this->ackManager->onPacketReceived(2, 1001.0, true);
        $this->ackManager->onPacketReceived(3, 1002.0, true);
        $this->ackManager->onPacketReceived(7, 1003.0, true);
        $this->ackManager->onPacketReceived(8, 1004.0, true);
        $this->ackManager->onPacketReceived(9, 1005.0, true);

        $ackFrame = $this->ackManager->generateAckFrame(1010.0);

        $this->assertInstanceOf(AckFrame::class, $ackFrame);
        $this->assertEquals(9, $ackFrame->getLargestAcked());

        $ackRanges = $ackFrame->getAckRanges();
        $this->assertCount(2, $ackRanges); // 应该有两个范围

        // 实际格式应该是 [start, end]，降序排列范围
        $this->assertEquals([7, 9], $ackRanges[0]); // 范围7-9
        $this->assertEquals([1, 3], $ackRanges[1]); // 范围1-3
    }

    public function testGenerateAckFrameEmptyPackets(): void
    {
        $ackFrame = $this->ackManager->generateAckFrame(1000.0);

        $this->assertNull($ackFrame);
    }

    public function testOnAckSent(): void
    {
        // 接收包1-5
        for ($i = 1; $i <= 5; ++$i) {
            $this->ackManager->onPacketReceived($i, 1000.0 + $i, true);
        }

        $this->assertEquals(5, $this->ackManager->getPendingAckCount());

        // 发送ACK确认包1-3
        $this->ackManager->onAckSent([[1, 3]]);

        $this->assertEquals(2, $this->ackManager->getPendingAckCount()); // 还剩包4,5
    }

    public function testDetectMissingPackets(): void
    {
        // 接收包1,2,4,5（缺失包3）
        $this->ackManager->onPacketReceived(1, 1000.0, true);
        $this->ackManager->onPacketReceived(2, 1001.0, true);
        $this->ackManager->onPacketReceived(4, 1002.0, true);
        $this->ackManager->onPacketReceived(5, 1003.0, true);

        $missingPackets = $this->ackManager->detectMissingPackets();

        $this->assertContains(0, $missingPackets); // 包0缺失
        $this->assertContains(3, $missingPackets); // 包3缺失
    }

    public function testDetectMissingPacketsNoLargestReceived(): void
    {
        $missingPackets = $this->ackManager->detectMissingPackets();

        $this->assertEmpty($missingPackets);
    }

    public function testCleanupOldRecords(): void
    {
        // 接收多个包，时间不同
        $this->ackManager->onPacketReceived(1, 1000.0, true);
        $this->ackManager->onPacketReceived(2, 1100.0, true);
        $this->ackManager->onPacketReceived(3, 1200.0, true);

        $this->assertEquals(3, $this->ackManager->getPendingAckCount());

        // 清理1150.0之前的记录
        $this->ackManager->cleanupOldRecords(1150.0);

        // 应该还剩2个包（时间1100.0和1200.0），但1100.0也会被清理，所以只剩1个
        $this->assertEquals(1, $this->ackManager->getPendingAckCount());
    }

    public function testReset(): void
    {
        // 接收一些包
        $this->ackManager->onPacketReceived(1, 1000.0, true);
        $this->ackManager->onPacketReceived(2, 1001.0, true);

        $this->assertTrue($this->ackManager->hasAckPending());
        $this->assertGreaterThan(0, $this->ackManager->getPendingAckCount());

        // 重置
        $this->ackManager->reset();

        // 验证状态已重置
        $this->assertEquals(-1, $this->ackManager->getLargestReceived());
        $this->assertEquals(0, $this->ackManager->getPendingAckCount());
        $this->assertFalse($this->ackManager->hasAckPending());
        $this->assertEquals(0.0, $this->ackManager->getAckTimeout());
    }

    public function testGetStats(): void
    {
        // 接收一些包
        $this->ackManager->onPacketReceived(1, 1000.0, true);
        $this->ackManager->onPacketReceived(2, 1001.0, true);
        $this->ackManager->onPacketReceived(4, 1002.0, true); // 缺失包3

        $stats = $this->ackManager->getStats();

        $this->assertEquals(3, $stats['received_packets']);
        $this->assertEquals(3, $stats['pending_acks']);
        $this->assertEquals(4, $stats['largest_received']);
        $this->assertEquals(3, $stats['ack_eliciting_received']); // 都是ACK引发包，应该是3
        $this->assertTrue($stats['ack_pending']);
        $this->assertEquals(2, $stats['missing_packets']); // 包0和包3缺失
    }

    public function testAckDelayCalculation(): void
    {
        $receiveTime = 1000.0;
        $generateTime = 1005.5;

        $this->ackManager->onPacketReceived(1, $receiveTime, true);

        $ackFrame = $this->ackManager->generateAckFrame($generateTime);

        $this->assertInstanceOf(AckFrame::class, $ackFrame);

        // ACK延迟应该是 5.5ms * 1000 = 5500微秒
        $this->assertEquals(5500, $ackFrame->getAckDelay());
    }

    public function testComplexScenario(): void
    {
        $currentTime = 1000.0;

        // 接收乱序的数据包
        $packets = [1, 5, 2, 8, 3, 7, 10];
        foreach ($packets as $i => $packetNumber) {
            $this->ackManager->onPacketReceived($packetNumber, $currentTime + $i, true);
        }

        $this->assertEquals(10, $this->ackManager->getLargestReceived());
        $this->assertEquals(7, $this->ackManager->getPendingAckCount());

        // 由于接收了多个ACK引发包，应该立即发送ACK
        $this->assertTrue($this->ackManager->shouldSendAckImmediately($currentTime + 10));

        // 生成ACK帧
        $ackFrame = $this->ackManager->generateAckFrame($currentTime + 10);
        $this->assertInstanceOf(AckFrame::class, $ackFrame);

        // 检查ACK范围
        $ackRanges = $ackFrame->getAckRanges();
        $this->assertGreaterThan(1, count($ackRanges)); // 应该有多个范围因为不连续

        // 检查缺失的包
        $missingPackets = $this->ackManager->detectMissingPackets();
        $this->assertContains(0, $missingPackets);
        $this->assertContains(4, $missingPackets);
        $this->assertContains(6, $missingPackets);
        $this->assertContains(9, $missingPackets);
    }
}
