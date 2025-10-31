<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery\Exception;

/**
 * 包号无效异常
 *
 * 当包号为负数或其他无效值时抛出
 */
final class InvalidPacketNumberException extends \InvalidArgumentException
{
}
