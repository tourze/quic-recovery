# QUIC Recovery Package

QUIC协议丢包检测和恢复机制的完整实现，遵循RFC 9002规范。

## 功能特性

- **RTT估算**：实现指数移动平均算法进行往返时间估算
- **丢包检测**：支持基于时间和包号的丢包检测算法
- **包追踪**：跟踪已发送数据包的状态和确认情况
- **ACK管理**：自动生成和处理ACK帧
- **重传管理**：智能重传策略，包括PTO探测和快速重传

## 安装

```bash
composer require tourze/quic-recovery
```

## 基本使用

### 创建恢复机制实例

```php
use Tourze\QUIC\Recovery\Recovery;

// 创建恢复机制，设置初始RTT为333ms
$recovery = new Recovery(333.0);
```

### 发送数据包时

```php
use Tourze\QUIC\Packets\Packet;

// 记录发送的数据包
$recovery->onPacketSent(
    packetNumber: 1,
    packet: $packet,
    sentTime: microtime(true) * 1000,
    ackEliciting: true
);
```

### 接收数据包时

```php
// 记录接收到的数据包
$recovery->onPacketReceived(
    packetNumber: 1,
    receiveTime: microtime(true) * 1000,
    ackEliciting: true
);

// 检查是否需要立即发送ACK
if ($recovery->shouldSendAckImmediately(microtime(true) * 1000)) {
    $ackFrame = $recovery->generateAckFrame(microtime(true) * 1000);
    // 发送ACK帧...
}
```

### 处理收到的ACK

```php
use Tourze\QUIC\Frames\AckFrame;

// 当收到ACK帧时
$recovery->onAckReceived($ackFrame, microtime(true) * 1000);
```

### 处理超时事件

```php
$currentTime = microtime(true) * 1000;
$actions = $recovery->onTimeout($currentTime);

foreach ($actions as $action) {
    switch ($action['type']) {
        case 'retransmit_lost':
            // 重传丢失的数据包
            foreach ($action['packets'] as $packetNumber) {
                // 重传逻辑...
            }
            break;
            
        case 'pto_probe':
            // 发送PTO探测包
            foreach ($action['packets'] as $probeInfo) {
                // 探测逻辑...
            }
            break;
            
        case 'send_ack':
            // 发送ACK帧
            $ackFrame = $action['frame'];
            // 发送逻辑...
            break;
    }
}
```

## 高级使用

### 获取统计信息

```php
$stats = $recovery->getStats();

echo "当前RTT: " . $recovery->getCurrentRtt() . "ms\n";
echo "重传率: " . ($recovery->getRetransmissionRate() * 100) . "%\n";
echo "连接健康: " . ($recovery->isConnectionHealthy() ? '是' : '否') . "\n";
echo "拥塞建议: " . $recovery->getCongestionAdvice() . "\n";
```

### 访问单独组件

```php
// 获取RTT估算器
$rttEstimator = $recovery->getRttEstimator();
$smoothedRtt = $rttEstimator->getSmoothedRtt();

// 获取包追踪器
$packetTracker = $recovery->getPacketTracker();
$unackedCount = $packetTracker->getAckElicitingOutstanding();

// 获取丢包检测器
$lossDetection = $recovery->getLossDetection();
$ptoCount = $lossDetection->getPtoCount();
```

### 定期清理

```php
// 定期清理过期记录（建议每分钟执行一次）
$recovery->cleanup(microtime(true) * 1000);
```

## 组件说明

### RTTEstimator
- 实现RFC 9002的RTT估算算法
- 支持平滑RTT和RTT变异值计算
- 提供PTO超时计算

### PacketTracker
- 跟踪已发送数据包状态
- 管理ACK确认和丢包标记
- 支持包重排序检测

### LossDetection
- 基于包号差异的丢包检测
- 基于时间阈值的丢包检测
- PTO超时管理

### AckManager
- 自动ACK帧生成
- ACK延迟控制
- 缺失包检测

### RetransmissionManager
- 智能重传策略
- 指数退避算法
- 重传统计分析

## 配置选项

```php
// 自定义初始RTT
$recovery = new Recovery(500.0); // 500ms初始RTT

// 重置恢复机制状态
$recovery->reset();
```

## 错误处理

所有方法都包含适当的参数验证，会在参数无效时抛出 `InvalidArgumentException`。

## 性能考虑

- 定期调用 `cleanup()` 方法清理过期记录
- 监控重传率，避免重传风暴
- 根据网络条件调整初始RTT值

## RFC兼容性

本实现严格遵循以下RFC规范：
- [RFC 9000](https://tools.ietf.org/html/rfc9000) - QUIC: A UDP-Based Multiplexed and Secure Transport
- [RFC 9002](https://tools.ietf.org/html/rfc9002) - QUIC Loss Detection and Congestion Control

## 许可证

MIT License
