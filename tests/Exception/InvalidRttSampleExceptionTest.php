<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\QUIC\Recovery\Exception\InvalidRttSampleException;

/**
 * InvalidRttSampleException 类测试
 *
 * @internal
 */
#[CoversClass(InvalidRttSampleException::class)]
final class InvalidRttSampleExceptionTest extends AbstractExceptionTestCase
{
    public function testExceptionIsInstanceOfInvalidArgumentException(): void
    {
        $exception = new InvalidRttSampleException('Test message');

        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
    }

    public function testExceptionMessage(): void
    {
        $message = 'RTT sample is invalid';
        $exception = new InvalidRttSampleException($message);

        $this->assertEquals($message, $exception->getMessage());
    }

    public function testExceptionCode(): void
    {
        $code = 789;
        $exception = new InvalidRttSampleException('Test message', $code);

        $this->assertEquals($code, $exception->getCode());
    }

    public function testExceptionWithPreviousException(): void
    {
        $previous = new \RuntimeException('Previous exception');
        $exception = new InvalidRttSampleException('Test message', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
