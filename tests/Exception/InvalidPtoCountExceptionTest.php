<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery\Tests\Exception;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Recovery\Exception\InvalidPtoCountException;

/**
 * InvalidPtoCountException 类测试
 */
final class InvalidPtoCountExceptionTest extends TestCase
{
    public function testExceptionIsInstanceOfInvalidArgumentException(): void
    {
        $exception = new InvalidPtoCountException('Test message');
        
        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
    }

    public function testExceptionMessage(): void
    {
        $message = 'PTO count is invalid';
        $exception = new InvalidPtoCountException($message);
        
        $this->assertEquals($message, $exception->getMessage());
    }

    public function testExceptionCode(): void
    {
        $code = 456;
        $exception = new InvalidPtoCountException('Test message', $code);
        
        $this->assertEquals($code, $exception->getCode());
    }

    public function testExceptionWithPreviousException(): void
    {
        $previous = new \RuntimeException('Previous exception');
        $exception = new InvalidPtoCountException('Test message', 0, $previous);
        
        $this->assertSame($previous, $exception->getPrevious());
    }
}