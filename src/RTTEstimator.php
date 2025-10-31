<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery;

use Tourze\QUIC\Recovery\Exception\InvalidPtoCountException;
use Tourze\QUIC\Recovery\Exception\InvalidRttSampleException;

/**
 * RTT估算器
 *
 * 根据RFC 9002实现往返时间估算
 * 用于丢包检测和拥塞控制
 */
final class RTTEstimator
{
    // 默认初始RTT值（毫秒）
    private const DEFAULT_INITIAL_RTT = 333;

    // 最小RTT值（毫秒）
    private const MIN_RTT = 1;

    // 最大ACK延迟（毫秒）
    private const MAX_ACK_DELAY = 25;

    private float $smoothedRtt;

    private float $rttVariation;

    private float $minRtt;

    private float $latestRtt;

    private int $sampleCount = 0;

    public function __construct(float $initialRtt = self::DEFAULT_INITIAL_RTT)
    {
        $this->smoothedRtt = $initialRtt;
        $this->rttVariation = $initialRtt / 2;
        $this->minRtt = $initialRtt;
        $this->latestRtt = $initialRtt;
    }

    /**
     * 更新RTT测量值
     *
     * @param float $rttSample RTT样本（毫秒）
     * @param float $ackDelay  ACK延迟（毫秒）
     */
    public function updateRtt(float $rttSample, float $ackDelay = 0.0): void
    {
        if ($rttSample <= 0) {
            throw new InvalidRttSampleException('RTT样本必须大于0');
        }

        $this->latestRtt = $rttSample;

        // 更新最小RTT
        if ($rttSample < $this->minRtt) {
            $this->minRtt = $rttSample;
        }

        // 调整RTT样本，减去ACK延迟（但不小于minRtt）
        $adjustedRtt = $rttSample;
        if ($ackDelay > 0 && $ackDelay <= self::MAX_ACK_DELAY) {
            $adjustedRtt = max($rttSample - $ackDelay, $this->minRtt);
        }

        // 首次RTT测量
        if (0 === $this->sampleCount) {
            $this->smoothedRtt = $adjustedRtt;
            $this->rttVariation = $adjustedRtt / 2;
        } else {
            // 使用指数移动平均更新smoothed_rtt和rtt_var
            $rttDiff = abs($this->smoothedRtt - $adjustedRtt);
            $this->rttVariation = 0.75 * $this->rttVariation + 0.25 * $rttDiff;
            $this->smoothedRtt = 0.875 * $this->smoothedRtt + 0.125 * $adjustedRtt;
        }

        ++$this->sampleCount;
    }

    /**
     * 获取当前平滑RTT
     */
    public function getSmoothedRtt(): float
    {
        return $this->smoothedRtt;
    }

    /**
     * 获取RTT变异值
     */
    public function getRttVariation(): float
    {
        return $this->rttVariation;
    }

    /**
     * 获取最小RTT
     */
    public function getMinRtt(): float
    {
        return max($this->minRtt, self::MIN_RTT);
    }

    /**
     * 获取最新RTT样本
     */
    public function getLatestRtt(): float
    {
        return $this->latestRtt;
    }

    /**
     * 计算PTO (Probe Timeout) 超时值
     *
     * @param int $ptoCount PTO重传次数
     */
    public function calculatePto(int $ptoCount = 0): float
    {
        if ($ptoCount < 0) {
            throw new InvalidPtoCountException('PTO计数不能为负数');
        }

        // PTO = smoothed_rtt + max(4*rtt_var, kGranularity) + max_ack_delay
        $pto = $this->smoothedRtt +
               max(4 * $this->rttVariation, 1.0) +
               self::MAX_ACK_DELAY;

        // 指数退避
        if ($ptoCount > 0) {
            $pto *= (1 << $ptoCount);
        }

        return $pto;
    }

    /**
     * 重置RTT状态（连接迁移时使用）
     */
    public function reset(): void
    {
        $this->smoothedRtt = self::DEFAULT_INITIAL_RTT;
        $this->rttVariation = self::DEFAULT_INITIAL_RTT / 2;
        $this->minRtt = self::DEFAULT_INITIAL_RTT;
        $this->latestRtt = self::DEFAULT_INITIAL_RTT;
        $this->sampleCount = 0;
    }

    /**
     * 获取RTT统计信息
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'smoothed_rtt' => $this->smoothedRtt,
            'rtt_variation' => $this->rttVariation,
            'min_rtt' => $this->getMinRtt(),
            'latest_rtt' => $this->latestRtt,
            'sample_count' => $this->sampleCount,
        ];
    }
}
