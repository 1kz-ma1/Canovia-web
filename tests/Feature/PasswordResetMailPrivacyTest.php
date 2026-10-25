<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Monolog\Handler\NullHandler;
use Tests\TestCase;

final class PasswordResetMailPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.env' => 'production', 'release_levels.preparation_only' => true,
            'logging.channels.synthetic_mail' => ['driver' => 'monolog', 'handler' => NullHandler::class],
            'mail.mailers.log.channel' => 'synthetic_mail']);
    }

    public function test_production_log_mailer_does_not_create_token_or_log_reset_link(): void
    {
        $user = User::factory()->create();
        config(['mail.default' => 'log']);
        $resetLogged = false;
        Log::listen(function (MessageLogged $event) use (&$resetLogged): void {
            $resetLogged = $resetLogged || str_contains((string) $event->message, '/reset-password/');
        });
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
        $this->assertFalse($resetLogged, 'RESET_LINK_ENTERED_APPLICATION_LOG');
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_nested_logging_url_unknown_and_cyclic_mailers_are_rejected_before_broker(): void
    {
        $user = User::factory()->create();
        Notification::fake();
        $mailers = [
            'array' => ['transport' => 'array'],
            'nested' => ['transport' => 'roundrobin', 'mailers' => ['smtp', 'failover']],
            'url_log' => ['transport' => 'smtp', 'url' => 'log://synthetic'],
            'unknown' => ['transport' => 'unreviewed-custom'],
            'url_override' => ['transport' => 'smtp', 'url' => 'smtp://synthetic.invalid?driver=log'],
            'cyclic' => ['transport' => 'failover', 'mailers' => ['cyclic']],
            'empty' => ['transport' => 'roundrobin', 'mailers' => []],
        ];
        foreach ($mailers as $name => $config) {
            config(['mail.default' => $name, 'mail.mailers.'.$name => $config]);
            $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
                ->assertSessionHasErrors('email');
            $this->assertDatabaseCount('password_reset_tokens', 0);
        }
        Notification::assertNothingSent();
    }

    public function test_known_delivery_transport_can_reach_notification_without_network_in_test(): void
    {
        $user = User::factory()->create();
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors()->assertRedirect('/forgot-password');
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_nonproduction_log_mailer_retains_development_behavior(): void
    {
        $user = User::factory()->create();
        config(['app.env' => 'testing', 'mail.default' => 'log']);
        Notification::fake();
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPassword::class);
    }
}
