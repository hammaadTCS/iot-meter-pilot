<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Prove the mail configuration works, end to end, before a customer relies on it.
 *
 * Mail is the one dependency whose failure mode is silence: a misconfigured
 * mailer does not throw at boot, it throws (or worse, quietly logs) at 2am when
 * an outage alert needed to reach someone. And with MAIL_MAILER=failover the
 * fallback is the log driver, which means a broken SMTP config LOOKS successful
 * — the message just lands in storage/logs instead of an inbox.
 *
 * So this command deliberately reports which mailer actually accepted the
 * message, and warns when that was the log fallback rather than real delivery.
 *
 *   php artisan mail:test you@example.com          # uses the default mailer
 *   php artisan mail:test you@example.com --mailer=smtp   # force one, bypassing failover
 */
class SendTestMail extends Command
{
    protected $signature = 'mail:test
                            {recipient : Address to send the test message to}
                            {--mailer= : Force a specific mailer instead of the configured default}';

    protected $description = 'Send a test email to verify the mail configuration end to end';

    public function handle(): int
    {
        $recipient = (string) $this->argument('recipient');

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->components->error("\"{$recipient}\" is not a valid email address.");

            return self::FAILURE;
        }

        $mailer = (string) ($this->option('mailer') ?: config('mail.default'));
        $from = config('mail.from.address');

        $this->components->info("Sending via [{$mailer}] from [{$from}] to [{$recipient}]…");

        // A misconfigured MAIL_FROM_ADDRESS is the single most common cause of
        // silent spam-foldering, so surface it before we send rather than after.
        if (! $from || str_contains((string) $from, 'example.com')) {
            $this->components->warn(
                'MAIL_FROM_ADDRESS is unset or still a placeholder. Real providers reject or '.
                'spam-folder mail from an unverified sender domain — set it to an address on a '.
                'domain you control, with SPF and DKIM published.'
            );
        }

        try {
            Mail::mailer($mailer)->raw(
                $this->body($mailer),
                fn ($message) => $message
                    ->to($recipient)
                    ->subject('['.config('app.name').'] Mail configuration test')
            );
        } catch (Throwable $e) {
            $this->components->error('Send failed: '.$e->getMessage());
            $this->line('');
            $this->line('  Common causes: wrong port (587 STARTTLS vs 465 TLS), an unverified');
            $this->line('  sender domain, or credentials not yet activated by the provider.');

            return self::FAILURE;
        }

        // The failover transport swallows a broken SMTP config by falling back
        // to `log`. That is correct for production resilience and misleading for
        // a test, so say so plainly.
        $resolved = $mailer === 'failover'
            ? implode(' -> ', (array) config('mail.mailers.failover.mailers', []))
            : $mailer;

        $this->components->info("Accepted by [{$resolved}].");

        if ($mailer === 'log' || $resolved === 'log') {
            $this->components->warn(
                'That was the LOG driver — nothing was actually delivered. '.
                'The message is in storage/logs/laravel.log.'
            );

            return self::SUCCESS;
        }

        if ($mailer === 'failover') {
            $this->components->warn(
                'With failover, a broken SMTP config silently falls through to the log driver. '.
                'Confirm the message arrived in the inbox — and if it did not, re-run with '.
                '--mailer=smtp to see the real error.'
            );
        }

        $this->components->info('Now check the inbox (and the spam folder).');

        return self::SUCCESS;
    }

    private function body(string $mailer): string
    {
        return implode(PHP_EOL, [
            'This is a test message from '.config('app.name').'.',
            '',
            'If you are reading this in an inbox, transactional mail works:',
            'alert digests, email verification and password resets can all be delivered.',
            '',
            'mailer:      '.$mailer,
            'from:        '.config('mail.from.address'),
            'app url:     '.config('app.url'),
            'environment: '.app()->environment(),
            'sent at:     '.now()->toDateTimeString().' ('.config('app.timezone').')',
        ]);
    }
}
