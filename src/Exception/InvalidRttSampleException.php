<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery\Exception;

/**
 * RTT样本无效异常
 *
 * 当RTT样本小于等于0或其他无效值时抛出
 */
final class InvalidRttSampleException extends \InvalidArgumentException
{
}
