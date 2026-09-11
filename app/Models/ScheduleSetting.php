<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Single-row settings for the automated monitoring loop.
 *
 * Laravel's scheduler is defined once at boot, so it cannot read a changing
 * interval directly. Instead both commands are scheduled every minute and each
 * asks here whether enough time has passed -- which lets the cadence change from
 * the UI without touching code or restarting anything.
 */
class ScheduleSetting extends Model
{
    protected $guarded = [];

    /**
     * Defaults declared here rather than relying on the column defaults.
     *
     * firstOrCreate() inserts the row and lets Postgres fill the blanks, but the
     * returned model is not re-read, so every setting came back null on a fresh
     * install -- which then reached the detector as a null cooldown.
     */
    protected $attributes = [
        'monitoring_enabled' => true,
        'ping_interval_seconds' => 10,
        'detect_interval_minutes' => 1,
        'auto_investigate' => true,
        'incident_cooldown_minutes' => 15,
        'max_investigations_per_hour' => 6,
    ];

    protected function casts(): array
    {
        return [
            'monitoring_enabled' => 'boolean',
            'auto_investigate' => 'boolean',
            'ping_interval_seconds' => 'integer',
            'detect_interval_minutes' => 'integer',
            'incident_cooldown_minutes' => 'integer',
            'max_investigations_per_hour' => 'integer',
            'quiet_hours_start' => 'integer',
            'quiet_hours_end' => 'integer',
            'last_ping_at' => 'datetime',
            'last_detect_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate([], []);
    }

    public function shouldPingNow(?Carbon $now = null): bool
    {
        if (! $this->monitoring_enabled) {
            return false;
        }

        $now ??= now();

        return $this->last_ping_at === null
            || $this->last_ping_at->addSeconds($this->ping_interval_seconds)->lessThanOrEqualTo($now);
    }

    public function shouldDetectNow(?Carbon $now = null): bool
    {
        if (! $this->monitoring_enabled) {
            return false;
        }

        $now ??= now();

        return $this->last_detect_at === null
            || $this->last_detect_at->addMinutes($this->detect_interval_minutes)->lessThanOrEqualTo($now);
    }

    /**
     * Whether an automated investigation may start right now.
     *
     * @return array{0:bool,1:?string} allowed, and why not when refused
     */
    public function mayInvestigate(?Carbon $now = null): array
    {
        $now ??= now();

        if (! $this->auto_investigate) {
            return [false, 'auto-investigate is off'];
        }

        if ($this->inQuietHours($now)) {
            return [false, "quiet hours ({$this->quiet_hours_start}:00–{$this->quiet_hours_end}:00 UTC)"];
        }

        $recent = Investigation::where('created_at', '>=', $now->copy()->subHour())->count();

        if ($recent >= $this->max_investigations_per_hour) {
            return [false, "hourly cap reached ({$recent}/{$this->max_investigations_per_hour})"];
        }

        return [true, null];
    }

    public function inQuietHours(?Carbon $now = null): bool
    {
        if ($this->quiet_hours_start === null || $this->quiet_hours_end === null) {
            return false;
        }

        $hour = ($now ?? now())->hour;

        // A window that wraps midnight (22 → 6) is two ranges, not one.
        return $this->quiet_hours_start <= $this->quiet_hours_end
            ? $hour >= $this->quiet_hours_start && $hour < $this->quiet_hours_end
            : $hour >= $this->quiet_hours_start || $hour < $this->quiet_hours_end;
    }

    public function pingsPerHour(): float
    {
        return $this->ping_interval_seconds > 0
            ? round(3600 / $this->ping_interval_seconds, 1)
            : 0;
    }
}
