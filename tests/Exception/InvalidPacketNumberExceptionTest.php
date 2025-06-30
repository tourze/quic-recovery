<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery\Tests\Exception;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Recovery\Exception\InvalidPacketNumberException;

/**
 * InvalidPacketNumberException 类测试
 */
final class InvalidPacketNumberExceptionTest extends TestCase
{
    public function testExceptionIsInstanceOfInvalidArgumentException(): void
    {
        $exception = new InvalidPacketNumberException('Test message');
        
        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
    }

    public function testExceptionMessage(): void
    {
        $message = 'Packet number is invalid';
        $exception = new InvalidPacketNumberException($message);
        
        $this->assertEquals($message, $exception->getMessage());
    }

    public function testExceptionCode(): void
    {
        $code = 123;
        $exception = new InvalidPacketNumberException('Test message', $code);
        
        $this->assertEquals($code, $exception->getCode());
    }

    public function testExceptionWithPreviousException(): void
    {
        $previous = new \RuntimeException('Previous exception');
        $exception = new InvalidPacketNumberException('Test message', 0, $previous);
        
        $this->assertSame($previous, $exception->getPrevious());
    }
}