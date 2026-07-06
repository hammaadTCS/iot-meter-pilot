<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's notification delivery preferences, with the routing rules that decide
 * which channels an alert reaches. Absent rows resolve to sensible defaults so
 * delivery always has an answer without every user needing a row.
 */
class NotificationPreference extends Model
{
    /** Severity ordering so a min_severity floor can be compared. */
    private const SEVERITY_RANK = ['warning' => 1, 'critical' => 2];

    protected $fillable = [
        'user_id',
        'mail_enabled',
        'database_enabled',
        'broadcast_enabled',
        'min_severity',
        'quiet_hours_start',
        'quiet_hours_end',
        'fleet_scope',
    ];

    protected $casts = [
        'mail_enabled'      => 'boolean',
        'database_enabled'  => 'boolean',
        'broadcast_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The persisted row for a user, or an unsaved defaults object. */
    public static function forUser(User $user): self
    {
        return static::firstWhere('user_id', $user->id) ?? static::defaultsFor($user);
    }

    /** Default preferences (not persisted) for a user without a row yet. */
    public static function defaultsFor(User $user): self
    {
        return new self([
            'user_id'           => $user->id,
            'mail_enabled'      => true,
            'database_enabled'  => true,
            'broadcast_enabled' => true,
            'min_severity'      => 'warning',
            'fleet_scope'       => 'own',
        ]);
    }

    /**
     * Channels an alert of $severity should reach at time $at. In-app channels
     * are always on (the bell must reflect reality); mail is gated by the
     * severity floor and quiet hours so it stays low-noise.
     *
     * @return list<string>
     */
    public function channelsFor(string $severity, Carbon $at): array
    {
        $channels = [];

        if ($this->database_enabled) {
            $channels[] = 'database';
        }
        if ($this->broadcast_enabled) {
            $channels[] = 'broadcast';
        }
        if ($this->mail_enabled && $this->allowsSeverity($severity) && ! $this->inQuietHours($at)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /** True when $severity is at or above the user's mail floor. */
    public function allowsSeverity(string $severity): bool
    {
        return (self::SEVERITY_RANK[$severity] ?? 1) >= (self::SEVERITY_RANK[$this->min_severity] ?? 1);
    }

    /** True when $at falls inside the (optionally midnight-crossing) quiet window. */
    public function inQuietHours(Carbon $at): bool
    {
        if (! $this->quiet_hours_start || ! $this->quiet_hours_end) {
            return false;
        }

        $now   = $at->format('H:i');
        $start = Carbon::parse($this->quiet_hours_start)->format('H:i');
        $end   = Carbon::parse($this->quiet_hours_end)->format('H:i');

        return $start <= $end
            ? ($now >= $start && $now < $end)        // same-day window
            : ($now >= $start || $now < $end);       // window crosses midnight
    }
}
