<?php

namespace Tests\Unit;

use Tests\TestCase;

class MqttClientConfigTest extends TestCase
{
    public function test_mqtt_connection_uses_auth_block_and_reconnect_defaults(): void
    {
        $config = config('mqtt-client.connections.default');

        $this->assertArrayHasKey('auth', $config['connection_settings']);
        $this->assertArrayHasKey('username', $config['connection_settings']['auth']);
        $this->assertArrayHasKey('password', $config['connection_settings']['auth']);
        $this->assertArrayHasKey('auto_reconnect', $config['connection_settings']);
        $this->assertArrayHasKey('enabled', $config['connection_settings']['auto_reconnect']);
        $this->assertArrayHasKey('max_reconnect_attempts', $config['connection_settings']['auto_reconnect']);
        $this->assertArrayHasKey('delay_between_reconnect_attempts', $config['connection_settings']['auto_reconnect']);
        $this->assertArrayHasKey('socket_timeout', $config['connection_settings']);
        $this->assertArrayHasKey('resend_timeout', $config['connection_settings']);
        $this->assertArrayHasKey('subscribe_qos', $config);
        $this->assertArrayHasKey('retry', $config);
        $this->assertArrayHasKey('base_delay_seconds', $config['retry']);
        $this->assertArrayHasKey('max_delay_seconds', $config['retry']);
        $this->assertContains($config['subscribe_qos'], [0, 1, 2]);
        $this->assertGreaterThanOrEqual(1, $config['retry']['base_delay_seconds']);
        $this->assertGreaterThanOrEqual($config['retry']['base_delay_seconds'], $config['retry']['max_delay_seconds']);

        $this->assertFalse(
            $config['use_clean_session'] && $config['connection_settings']['auto_reconnect']['enabled'],
            'Automatic reconnect must not be combined with a clean session.'
        );
    }
}
