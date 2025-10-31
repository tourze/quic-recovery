<?php

declare(strict_types=1);

namespace Tourze\QUIC\Recovery\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Recovery\RTTEstimator;

/**
 * @internal
 */
#[CoversClass(RTTEstimator::class)]
final class RTTEstimatorTest extends TestCase
{
    private RTTEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->estimator = new RTTEstimator();
    }

    public function testConstructorWithDefaultInitialRtt(): void
    {
        $estimator = new RTTEstimator();

        $this->assertEquals(333.0, $estimator->getSmoothedRtt());
        $this->assertEquals(166.5, $estimator->getRttVariation()); // 333/2
        $this->assertEquals(333.0, $estimator->getMinRtt());
        $this->assertEquals(333.0, $estimator->getLatestRtt());
    }

    public function testConstructorWithCustomInitialRtt(): void
    {
        $customRtt = 500.0;
        $estimator = new RTTEstimator($customRtt);

        $this->assertEquals($customRtt, $estimator->getSmoothedRtt());
        $this->assertEquals($customRtt / 2, $estimator->getRttVariation());
        $this->assertEquals($customRtt, $estimator->getMinRtt());
        $this->assertEquals($customRtt, $estimator->getLatestRtt());
    }

    public function testUpdateRttWithValidSample(): void
    {
        $rttSample = 200.0;
        $this->estimator->updateRtt($rttSample);

        $this->assertEquals($rttSample, $this->estimator->getLatestRtt());
        $this->assertEquals($rttSample, $this->estimator->getMinRtt());
        // 首次更新：smoothed_rtt = 200, rtt_var = 100
        $this->assertEquals(200.0, $this->estimator->getSmoothedRtt());
        $this->assertEquals(100.0, $this->estimator->getRttVariation());
    }

    public function testUpdateRttWithAckDelay(): void
    {
        $rttSample = 300.0;
        $ackDelay = 50.0;

        $this->estimator->updateRtt($rttSample, $ackDelay);

        $this->assertEquals($rttSample, $this->estimator->getLatestRtt());
        // 由于ackDelay(50) > MAX_ACK_DELAY(25)，所以延迟被忽略
        // 首次更新时，smoothed_rtt = adjustedRtt = 300.0
        $this->assertEquals(300.0, $this->estimator->getSmoothedRtt());
    }

    public function testUpdateRttMultipleUpdates(): void
    {
        // 第一次更新
        $this->estimator->updateRtt(200.0);
        $smoothedRtt1 = $this->estimator->getSmoothedRtt();
        $rttVar1 = $this->estimator->getRttVariation();

        // 第二次更新
        $this->estimator->updateRtt(400.0);
        $smoothedRtt2 = $this->estimator->getSmoothedRtt();
        $rttVar2 = $this->estimator->getRttVariation();

        // 验证RTT是通过指数移动平均更新的
        $this->assertNotEquals($smoothedRtt1, $smoothedRtt2);
        $this->assertGreaterThan($smoothedRtt1, $smoothedRtt2);
    }

    public function testUpdateRttUpdatesMinRtt(): void
    {
        $this->estimator->updateRtt(100.0); // 比默认值小
        $this->assertEquals(100.0, $this->estimator->getMinRtt());

        $this->estimator->updateRtt(500.0); // 比当前min大
        $this->assertEquals(100.0, $this->estimator->getMinRtt()); // 应该保持不变
    }

    public function testUpdateRttWithZeroSampleThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RTT样本必须大于0');

        $this->estimator->updateRtt(0.0);
    }

    public function testUpdateRttWithNegativeSampleThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RTT样本必须大于0');

        $this->estimator->updateRtt(-10.0);
    }

    public function testCalculatePtoWithDefaultPtoCount(): void
    {
        $pto = $this->estimator->calculatePto();

        // PTO = smoothed_rtt + max(4*rtt_var, 1.0) + max_ack_delay
        // = 333 + max(4*166.5, 1.0) + 25 = 333 + 666 + 25 = 1024
        $expectedPto = 333.0 + 666.0 + 25.0;
        $this->assertEquals($expectedPto, $pto);
    }

    public function testCalculatePtoWithPtoCount(): void
    {
        $ptoCount = 2;
        $pto = $this->estimator->calculatePto($ptoCount);

        $basePto = 333.0 + 666.0 + 25.0; // 1024
        $expectedPto = $basePto * (1 << $ptoCount); // 1024 * 4 = 4096
        $this->assertEquals($expectedPto, $pto);
    }

    public function testCalculatePtoWithNegativePtoCountThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PTO计数不能为负数');

        $this->estimator->calculatePto(-1);
    }

    public function testGetMinRttEnforcesMinimum(): void
    {
        // 创建一个极小初始RTT的估算器
        $estimator = new RTTEstimator(0.5);

        // 即使实际最小RTT很小，getMinRtt应该强制不小于1.0
        $this->assertEquals(1.0, $estimator->getMinRtt());
    }

    public function testResetResetsAllValues(): void
    {
        // 先更新一些值
        $this->estimator->updateRtt(100.0);
        $this->estimator->updateRtt(200.0);

        // 重置
        $this->estimator->reset();

        // 验证所有值都重置为默认值
        $this->assertEquals(333.0, $this->estimator->getSmoothedRtt());
        $this->assertEquals(166.5, $this->estimator->getRttVariation());
        $this->assertEquals(333.0, $this->estimator->getLatestRtt());
    }

    public function testGetStatsReturnsCompleteInformation(): void
    {
        $this->estimator->updateRtt(250.0);

        $stats = $this->estimator->getStats();

        $this->assertArrayHasKey('smoothed_rtt', $stats);
        $this->assertArrayHasKey('rtt_variation', $stats);
        $this->assertArrayHasKey('min_rtt', $stats);
        $this->assertArrayHasKey('latest_rtt', $stats);
        $this->assertArrayHasKey('sample_count', $stats);

        $this->assertEquals(250.0, $stats['smoothed_rtt']);
        $this->assertEquals(125.0, $stats['rtt_variation']);
        $this->assertEquals(250.0, $stats['latest_rtt']);
        $this->assertEquals(1, $stats['sample_count']);
    }

    public function testGetStatsTracksampleCount(): void
    {
        $stats1 = $this->estimator->getStats();
        $this->assertEquals(0, $stats1['sample_count']);

        $this->estimator->updateRtt(100.0);
        $stats2 = $this->estimator->getStats();
        $this->assertEquals(1, $stats2['sample_count']);

        $this->estimator->updateRtt(200.0);
        $stats3 = $this->estimator->getStats();
        $this->assertEquals(2, $stats3['sample_count']);
    }

    public function testAckDelayHandlingWithLargeDelay(): void
    {
        $rttSample = 100.0;
        $ackDelay = 50.0; // 大于MAX_ACK_DELAY(25)的延迟应该被忽略

        $this->estimator->updateRtt($rttSample, $ackDelay);

        // 由于ackDelay > MAX_ACK_DELAY，应该不调整RTT样本
        $this->assertEquals($rttSample, $this->estimator->getLatestRtt());
    }

    public function testAckDelayHandlingPreventsBelowMinRtt(): void
    {
        // 先设置一个较小的minRtt
        $this->estimator->updateRtt(50.0);

        $rttSample = 70.0;
        $ackDelay = 25.0;

        $this->estimator->updateRtt($rttSample, $ackDelay);

        // 调整后的RTT应该是 max(70-25, 50) = 50
        $expectedAdjustedRtt = 50.0;

        // 第二次更新的smoothed_rtt计算：0.875 * 50 + 0.125 * 50 = 50
        $this->assertEquals(50.0, $this->estimator->getSmoothedRtt());
    }
}
