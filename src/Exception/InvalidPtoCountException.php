<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery\Exception;

/**
 * PTO计数无效异常
 * 
 * 当PTO计数为负数时抛出
 */
final class InvalidPtoCountException extends \InvalidArgumentException
{
} 