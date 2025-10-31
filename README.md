# QUIC Recovery Package

[English](README.md) | [中文](README.zh-CN.md)

[![Latest Version](https://img.shields.io/packagist/v/tourze/quic-recovery.svg?style=flat-square)](https://packagist.org/packages/tourze/quic-recovery)
[![PHP Version](https://img.shields.io/packagist/php-v/tourze/quic-recovery.svg?style=flat-square)](https://packagist.org/packages/tourze/quic-recovery)
[![Build Status](https://github.com/tourze/php-monorepo/actions/workflows/phpunit.yml/badge.svg)](https://github.com/tourze/php-monorepo/actions/workflows/phpunit.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/tourze/quic-recovery.svg?style=flat-square)](https://packagist.org/packages/tourze/quic-recovery)
[![License](https://img.shields.io/packagist/l/tourze/quic-recovery.svg?style=flat-square)](https://packagist.org/packages/tourze/quic-recovery)
[![Code Coverage](https://img.shields.io/codecov/c/github/tourze/php-monorepo?style=flat-square)](https://codecov.io/gh/tourze/php-monorepo)

A complete implementation of QUIC protocol packet loss detection and recovery 
mechanisms, following RFC 9002 specifications.

## Table of Contents

- [Features](#features)
- [Installation](#installation) 
- [Usage](#usage)
  - [Quick Start](#quick-start)
  - [Creating Recovery Instance](#creating-recovery-instance)
  - [When Sending Packets](#when-sending-packets)
  - [When Receiving Packets](#when-receiving-packets)
  - [Processing Received ACK](#processing-received-ack)
  - [Handling Timeout Events](#handling-timeout-events)
- [Dependencies](#dependencies)
- [Advanced Usage](#advanced-usage)
- [Components](#components)
- [Configuration](#configuration)
- [Error Handling](#error-handling)
- [Performance Considerations](#performance-considerations)
- [RFC Compliance](#rfc-compliance)
- [Contributing](#contributing)
- [License](#license)

## Features

- **RTT Estimation**: Implements exponential moving average algorithm for round-trip time estimation
- **Loss Detection**: Supports time-based and packet-number-based loss detection algorithms
- **Packet Tracking**: Tracks sent packet status and acknowledgment information
- **ACK Management**: Automatic ACK frame generation and processing
- **Retransmission Management**: Smart retransmission strategy including PTO probing and fast retransmission

## Installation

```bash
composer require tourze/quic-recovery
```

## Usage

### Quick Start

```php
<?php

use Tourze\QUIC\Recovery\Recovery;

// Create recovery instance with initial RTT of 333ms
$recovery = new Recovery(333.0);

// When sending a packet
$recovery->onPacketSent(
    packetNumber: 1,
    packet: $packet,
    sentTime: microtime(true) * 1000,
    ackEliciting: true
);

// When receiving a packet
$recovery->onPacketReceived(
    packetNumber: 1,
    receiveTime: microtime(true) * 1000,
    ackEliciting: true
);

// Check if should send ACK immediately
if ($recovery->shouldSendAckImmediately(microtime(true) * 1000)) {
    $ackFrame = $recovery->generateAckFrame(microtime(true) * 1000);
    // Send ACK frame...
}
```

### Creating Recovery Instance

```php
use Tourze\QUIC\Recovery\Recovery;

// Create recovery mechanism with initial RTT of 333ms
$recovery = new Recovery(333.0);
```

### When Sending Packets

```php
use Tourze\QUIC\Packets\Packet;

// Record sent packet
$recovery->onPacketSent(
    packetNumber: 1,
    packet: $packet,
    sentTime: microtime(true) * 1000,
    ackEliciting: true
);
```

### When Receiving Packets

```php
// Record received packet
$recovery->onPacketReceived(
    packetNumber: 1,
    receiveTime: microtime(true) * 1000,
    ackEliciting: true
);

// Check if should send ACK immediately
if ($recovery->shouldSendAckImmediately(microtime(true) * 1000)) {
    $ackFrame = $recovery->generateAckFrame(microtime(true) * 1000);
    // Send ACK frame...
}
```

### Processing Received ACK

```php
use Tourze\QUIC\Frames\AckFrame;

// When receiving ACK frame
$recovery->onAckReceived($ackFrame, microtime(true) * 1000);
```

### Handling Timeout Events

```php
$currentTime = microtime(true) * 1000;
$actions = $recovery->onTimeout($currentTime);

foreach ($actions as $action) {
    switch ($action['type']) {
        case 'retransmit_lost':
            // Retransmit lost packets
            foreach ($action['packets'] as $packetNumber) {
                // Retransmission logic...
            }
            break;
            
        case 'pto_probe':
            // Send PTO probe packets
            foreach ($action['packets'] as $probeInfo) {
                // Probe logic...
            }
            break;
            
        case 'send_ack':
            // Send ACK frame
            $ackFrame = $action['frame'];
            // Send logic...
            break;
    }
}
```

## Dependencies

- PHP 8.1 or higher
- `tourze/quic-core` - Core QUIC protocol components
- `tourze/quic-packets` - QUIC packet structures
- `tourze/quic-frames` - QUIC frame structures

## Advanced Usage

### Getting Statistics

```php
$stats = $recovery->getStats();

echo "Current RTT: " . $recovery->getCurrentRtt() . "ms\n";
echo "Retransmission Rate: " . ($recovery->getRetransmissionRate() * 100) . "%\n";
echo "Connection Healthy: " . ($recovery->isConnectionHealthy() ? 'Yes' : 'No') . "\n";
echo "Congestion Advice: " . $recovery->getCongestionAdvice() . "\n";
```

### Accessing Individual Components

```php
// Get RTT estimator
$rttEstimator = $recovery->getRttEstimator();
$smoothedRtt = $rttEstimator->getSmoothedRtt();

// Get packet tracker
$packetTracker = $recovery->getPacketTracker();
$unackedCount = $packetTracker->getAckElicitingOutstanding();

// Get loss detection
$lossDetection = $recovery->getLossDetection();
$ptoCount = $lossDetection->getPtoCount();
```

### Periodic Cleanup

```php
// Periodic cleanup of expired records (recommended every minute)
$recovery->cleanup(microtime(true) * 1000);
```

## Components

### RTTEstimator
- Implements RFC 9002 RTT estimation algorithm
- Supports smoothed RTT and RTT variance calculation
- Provides PTO timeout calculation

### PacketTracker
- Tracks sent packet status
- Manages ACK acknowledgments and loss marking
- Supports packet reordering detection

### LossDetection
- Packet number gap-based loss detection
- Time threshold-based loss detection
- PTO timeout management

### AckManager
- Automatic ACK frame generation
- ACK delay control
- Missing packet detection

### RetransmissionManager
- Smart retransmission strategy
- Exponential backoff algorithm
- Retransmission statistics analysis

## Configuration

```php
// Custom initial RTT
$recovery = new Recovery(500.0); // 500ms initial RTT

// Reset recovery state
$recovery->reset();
```

## Error Handling

All methods include proper parameter validation and will throw `InvalidArgumentException` for invalid parameters.

## Performance Considerations

- Periodically call `cleanup()` method to clean expired records
- Monitor retransmission rate to avoid retransmission storms
- Adjust initial RTT value based on network conditions

## RFC Compliance

This implementation strictly follows the following RFC specifications:
- [RFC 9000](https://tools.ietf.org/html/rfc9000) - QUIC: A UDP-Based Multiplexed and Secure Transport
- [RFC 9002](https://tools.ietf.org/html/rfc9002) - QUIC Loss Detection and Congestion Control

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
