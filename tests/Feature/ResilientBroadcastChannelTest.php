<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AlertDigestNotification;
use App\Notifications\Channels\ResilientBroadcastChannel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use RuntimeException;
use Tests\TestCase;

/**
 * 454 queued notification jobs failed over three days because Reverb was down
 * and the broadcast leg threw, taking the whole notification job with it — even
 * though the bell (`database` channel) had already been written.
 *
 * These tests pin the rule: a websocket outage degrades live push and nothing
 * else. See ResilientBroadcastChannel for the full reasoning.
 */
class ResilientBroadcastChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_container_resolves_the_resilient_channel(): void
    {
        // ChannelManager::createBroadcastDriver() resolves this exact binding,
        // so if it regresses, broadcast failures become fatal again.
        $this->assertInstanceOf(
            ResilientBroadcastChannel::class,
            app(BroadcastChannel::class)
        );
    }

    public function test_a_broadcast_failure_does_not_propagate(): void
    {
        $user = User::factory()->create();

        // Stand in for "Reverb is unreachable": the underlying dispatch throws.
        $dispatcher = $this->mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andThrow(new RuntimeException('cURL error 7: connection refused'));

        $channel = new ResilientBroadcastChannel($dispatcher);

        $this->assertNull(
            $channel->send($user, new AlertDigestNotification([], 'critical'))
        );
    }

    public function test_the_failure_is_logged_rather_than_silenced(): void
    {
        // Degrading must not mean hiding — error tracking still needs to see a
        // Reverb outage, or we trade one blind spot for another.
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'Broadcast notification channel failed')
                && $context['notification'] === AlertDigestNotification::class
                && str_contains($context['exception'], 'connection refused'));

        $dispatcher = $this->mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andThrow(new RuntimeException('cURL error 7: connection refused'));

        (new ResilientBroadcastChannel($dispatcher))
            ->send(User::factory()->create(), new AlertDigestNotification([], 'critical'));
    }

    public function test_a_healthy_broadcast_still_dispatches_normally(): void
    {
        $dispatcher = $this->mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturn('dispatched');

        $this->assertSame(
            'dispatched',
            (new ResilientBroadcastChannel($dispatcher))
                ->send(User::factory()->create(), new AlertDigestNotification([], 'critical'))
        );
    }

    /**
     * The behaviour that actually matters: with broadcasting broken, the bell
     * row must still be written. This is what failed 454 times.
     */
    public function test_the_bell_is_still_written_when_broadcasting_is_broken(): void
    {
        $user = User::factory()->consumer()->create();

        // Force the broadcast leg to blow up the way an unreachable Reverb does.
        $this->app->bind(BroadcastChannel::class, function () {
            $dispatcher = \Mockery::mock(Dispatcher::class);
            $dispatcher->shouldReceive('dispatch')
                ->andThrow(new RuntimeException('cURL error 7: connection refused'));

            return new ResilientBroadcastChannel($dispatcher);
        });

        NotificationFacade::send($user, new AlertDigestNotification([[
            'device_id' => 1,
            'device_name' => 'Main Feeder',
            'device_type' => 'meter',
            'alert_type' => 'telemetry_down',
            'severity' => 'critical',
            'message' => 'No telemetry for 12 minutes',
            'transition' => 'opened',
            'at' => null,
        ]], 'critical'));

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $user->unreadNotifications()->count());
    }
}
