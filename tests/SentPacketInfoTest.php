<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery\Tests;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Packets\Packet;
use Tourze\QUIC\Recovery\SentPacketInfo;

/**
 * SentPacketInfo测试
 */
final class SentPacketInfoTest extends TestCase
{
    private Packet $packet;

    protected function setUp(): void
    {
        $this->packet = $this->createMock(Packet::class);
        $this->packet->method('getSize')->willReturn(1200);
    }

    public function testConstructorAndGetters(): void
    {
        $packetNumber = 10;
        $sentTime = 1.5;
        $ackEliciting = true;

        $sentPacketInfo = new SentPacketInfo(
            $packetNumber,
            $this->packet,
            $sentTime,
            $ackEliciting
        );

        $this->assertSame($packetNumber, $sentPacketInfo->getPacketNumber());
        $this->assertSame($this->packet, $sentPacketInfo->getPacket());
        $this->assertSame($sentTime, $sentPacketInfo->getSentTime());
        $this->assertTrue($sentPacketInfo->isAckEliciting());
        $this->assertSame(1200, $sentPacketInfo->getSize());
    }

    public function testNonAckElicitingPacket(): void
    {
        $sentPacketInfo = new SentPacketInfo(
            5,
            $this->packet,
            2.0,
            false
        );

        $this->assertSame(5, $sentPacketInfo->getPacketNumber());
        $this->assertSame(2.0, $sentPacketInfo->getSentTime());
        $this->assertFalse($sentPacketInfo->isAckEliciting());
    }

    public function testDefaultAckElicitingValue(): void
    {
        $sentPacketInfo = new SentPacketInfo(
            7,
            $this->packet,
            3.5
        );

        $this->assertTrue($sentPacketInfo->isAckEliciting());
    }

    public function testDifferentPacketSizes(): void
    {
        $packet = $this->createMock(Packet::class);
        $packet->method('getSize')->willReturn(500);

        $sentPacketInfo = new SentPacketInfo(
            1,
            $packet,
            1.0
        );

        $this->assertSame(500, $sentPacketInfo->getSize());
    }
} 