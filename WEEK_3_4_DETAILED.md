# WEEK 3: ALERTING SYSTEM (Detailed)

## Goal
Users define alert rules. System evaluates readings against rules. Fires alerts. Sends email notifications. Auto-resolves with snooze capability.

---

## Task 3.1: Create `alert_rules` Table
```bash
php artisan make:migration create_alert_rules_table
```

```php
// database/migrations/2026_05_05_create_alert_rules_table.php
return new class extends Migration {
    public function up(): void {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->foreignId('device_id')
                ->constrained('devices')
                ->onDelete('cascade');
            $table->string('name');                      // 'High Voltage', 'Device Offline'
            $table->string('condition_type');             // 'threshold', 'offline', 'custom'
            $table->json('config');                       // {field: 'voltage', operator: '>', value: 250}
            $table->json('notification_channels');        // ['email', 'sms']
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['device_id', 'name']);
            $table->index(['user_id', 'device_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('alert_rules');
    }
};
```

## Task 3.2: Create `alerts` Table
```bash
php artisan make:migration create_alerts_table
```

```php
// database/migrations/2026_05_05_create_alerts_table.php
return new class extends Migration {
    public function up(): void {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')
                ->constrained('alert_rules')
                ->onDelete('cascade');
            $table->foreignId('device_id')
                ->constrained('devices')
                ->onDelete('cascade');
            $table->string('severity');                  // 'critical', 'warning', 'info'
            $table->text('trigger_reason')->nullable();  // What caused it
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable(); // NULL = still open
            $table->timestamp('snoozed_until')->nullable(); // Snooze expiry
            $table->timestamps();

            $table->index(['device_id', 'resolved_at']);
            $table->index(['rule_id', 'resolved_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('alerts');
    }
};
```

## Task 3.3: Create `notifications` Table
```bash
php artisan make:migration create_notifications_table
```

```php
// database/migrations/2026_05_05_create_notifications_table.php
return new class extends Migration {
    public function up(): void {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->foreignId('alert_id')
                ->constrained('alerts')
                ->onDelete('cascade');
            $table->string('channel');                   // 'email', 'sms', 'push'
            $table->string('status')->default('pending'); // 'pending', 'sent', 'failed'
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['alert_id', 'status']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('notifications');
    }
};
```

Run migrations:
```bash
php artisan migrate
```

---

## Task 3.4: Create Models

```php
// app/Models/AlertRule.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertRule extends Model {
    protected $fillable = [
        'user_id', 'device_id', 'name', 'condition_type',
        'config', 'notification_channels', 'is_active'
    ];
    protected $casts = [
        'config' => 'array',
        'notification_channels' => 'array',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo {
        return $this->belongsTo(Device::class);
    }

    public function alerts(): HasMany {
        return $this->hasMany(Alert::class, 'rule_id');
    }

    // Get currently open/active alert
    public function activeAlert() {
        return $this->alerts()
            ->whereNull('resolved_at')
            ->whereNull('snoozed_until')
            ->orWhere('snoozed_until', '<', now())
            ->first();
    }

    // Evaluate if this rule is triggered by a reading
    public function evaluate(DeviceReading $reading): bool {
        if (!$this->is_active) return false;

        $payload = $reading->payload;
        $config = $this->config;

        if ($this->condition_type === 'threshold') {
            $field = $config['field'] ?? null;
            $operator = $config['operator'] ?? null;
            $threshold = $config['value'] ?? null;
            $actual = $payload[$field] ?? null;

            if ($actual === null) return false;

            return match($operator) {
                '>' => $actual > $threshold,
                '<' => $actual < $threshold,
                '==' => $actual == $threshold,
                '>=' => $actual >= $threshold,
                '<=' => $actual <= $threshold,
                default => false,
            };
        }

        if ($this->condition_type === 'offline') {
            return $this->device->getHealthStatus() === 'down';
        }

        return false;
    }
}

// app/Models/Alert.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Alert extends Model {
    protected $fillable = [
        'rule_id', 'device_id', 'severity', 'trigger_reason',
        'triggered_at', 'resolved_at', 'snoozed_until'
    ];
    protected $casts = [
        'triggered_at' => 'datetime',
        'resolved_at' => 'datetime',
        'snoozed_until' => 'datetime',
    ];

    public function rule(): BelongsTo {
        return $this->belongsTo(AlertRule::class);
    }

    public function device(): BelongsTo {
        return $this->belongsTo(Device::class);
    }

    public function notifications(): HasMany {
        return $this->hasMany(Notification::class);
    }

    // Is this alert currently active (not resolved, not snoozed)?
    public function isActive(): bool {
        return $this->resolved_at === null && 
               ($this->snoozed_until === null || $this->snoozed_until < now());
    }

    // Resolve this alert
    public function resolve(): void {
        $this->update(['resolved_at' => now(), 'snoozed_until' => null]);
    }

    // Snooze for X minutes
    public function snooze(int $minutes): void {
        $this->update(['snoozed_until' => now()->addMinutes($minutes)]);
    }
}

// app/Models/Notification.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model {
    protected $fillable = [
        'user_id', 'alert_id', 'channel', 'status', 'error_message', 'sent_at'
    ];
    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function alert(): BelongsTo {
        return $this->belongsTo(Alert::class);
    }
}
```

---

## Task 3.5: Create Alert Evaluation Job

```bash
php artisan make:job EvaluateDeviceAlerts
```

```php
// app/Jobs/EvaluateDeviceAlerts.php
namespace App\Jobs;

use App\Models\Device;
use App\Models\DeviceReading;
use App\Models\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class EvaluateDeviceAlerts implements ShouldQueue {
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Device $device,
        public DeviceReading $reading
    ) {}

    public function handle(): void {
        // Get all active rules for this device
        $rules = $this->device->alertRules()
            ->where('is_active', true)
            ->get();

        foreach ($rules as $rule) {
            try {
                // Evaluate if rule is triggered
                if ($rule->evaluate($this->reading)) {
                    // Get or create alert
                    $alert = Alert::firstOrCreate(
                        [
                            'rule_id' => $rule->id,
                            'device_id' => $this->device->id,
                            'resolved_at' => null, // Only unresolved
                        ],
                        [
                            'severity' => $rule->config['severity'] ?? 'warning',
                            'trigger_reason' => $this->buildTriggerReason($rule, $this->reading),
                            'triggered_at' => now(),
                        ]
                    );

                    // Send notifications (async job)
                    dispatch(new SendAlertNotifications($alert, $rule));

                    Log::info('Alert triggered', [
                        'rule_id' => $rule->id,
                        'device_id' => $this->device->id,
                        'alert_id' => $alert->id,
                    ]);
                } else {
                    // Rule not triggered, auto-resolve if active
                    Alert::where('rule_id', $rule->id)
                        ->where('device_id', $this->device->id)
                        ->whereNull('resolved_at')
                        ->update(['resolved_at' => now(), 'snoozed_until' => null]);
                }
            } catch (\Exception $e) {
                Log::error('Alert evaluation error', [
                    'rule_id' => $rule->id,
                    'device_id' => $this->device->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function buildTriggerReason(AlertRule $rule, DeviceReading $reading): string {
        if ($rule->condition_type === 'offline') {
            return 'Device offline for more than ' . $rule->config['minutes'] ?? '5' . ' minutes';
        }

        $config = $rule->config;
        $field = $config['field'] ?? 'unknown';
        $operator = $config['operator'] ?? '?';
        $threshold = $config['value'] ?? 'N/A';
        $actual = $reading->payload[$field] ?? 'N/A';

        return "{$field} {$operator} {$threshold} (actual: {$actual})";
    }
}
```

---

## Task 3.6: Create Notification Jobs

```bash
php artisan make:job SendAlertNotifications
```

```php
// app/Jobs/SendAlertNotifications.php
namespace App\Jobs;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendAlertNotifications implements ShouldQueue {
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Alert $alert,
        public AlertRule $rule
    ) {}

    public function handle(): void {
        $user = $this->rule->user;
        $device = $this->rule->device;
        $channels = $this->rule->notification_channels;

        foreach ($channels as $channel) {
            try {
                match($channel) {
                    'email' => $this->sendEmail($user, $device),
                    'sms' => $this->sendSms($user, $device),
                    'push' => $this->sendPush($user, $device),
                    default => null,
                };

                // Record notification
                Notification::create([
                    'user_id' => $user->id,
                    'alert_id' => $this->alert->id,
                    'channel' => $channel,
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                Log::info('Notification sent', [
                    'user_id' => $user->id,
                    'alert_id' => $this->alert->id,
                    'channel' => $channel,
                ]);
            } catch (\Exception $e) {
                Notification::create([
                    'user_id' => $user->id,
                    'alert_id' => $this->alert->id,
                    'channel' => $channel,
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);

                Log::error('Notification failed', [
                    'user_id' => $user->id,
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function sendEmail($user, $device): void {
        Mail::send('emails.alert-notification', [
            'user' => $user,
            'device' => $device,
            'alert' => $this->alert,
            'rule' => $this->rule,
        ], function ($message) use ($user) {
            $message->to($user->email)
                ->subject('Alert: ' . $this->alert->trigger_reason);
        });
    }

    private function sendSms($user, $device): void {
        // Integrate with Twilio or similar
        // $twilio = resolve('Twilio\Rest\Client');
        // $twilio->messages->create($user->phone, [...]);
    }

    private function sendPush($user, $device): void {
        // Integrate with Firebase Cloud Messaging or similar
        // send_firebase_notification($user->fcm_token, [...]);
    }
}
```

---

## Task 3.7: Create Email Template

```blade
{{-- resources/views/emails/alert-notification.blade.php --}}
<h2>Alert: {{ $rule->name }}</h2>

<p>
    Your device <strong>{{ $device->name }}</strong> has triggered an alert.
</p>

<p>
    <strong>Reason:</strong> {{ $alert->trigger_reason }}<br>
    <strong>Severity:</strong> {{ ucfirst($alert->severity) }}<br>
    <strong>Time:</strong> {{ $alert->triggered_at->format('Y-m-d H:i:s') }}
</p>

<p>
    <a href="{{ url("/devices/{$device->id}/dashboard") }}">View Device</a>
</p>
```

---

## Task 3.8: Update MQTT Consumer to Dispatch Alert Jobs

In `ConsumeDeviceTelemetry.php`, after storing reading:

```php
// Evaluate alert rules (async)
dispatch(new EvaluateDeviceAlerts($device, $reading));
```

---

## Task 3.9: Create Alert Management UI

```bash
php artisan make:controller AlertRuleController --resource
```

```php
// app/Http/Controllers/AlertRuleController.php
namespace App\Http\Controllers;

use App\Models\AlertRule;
use App\Models\Device;
use Illuminate\Http\Request;

class AlertRuleController extends Controller {
    public function index(Request $request, Device $device) {
        $this->authorize('view', $device);
        
        return view('devices.alerts', [
            'device' => $device,
            'rules' => $device->alertRules()->get(),
            'alerts' => $device->alerts()
                ->whereNull('resolved_at')
                ->orWhere('resolved_at', '>', now()->subDays(7))
                ->get(),
        ]);
    }

    public function store(Request $request, Device $device) {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'condition_type' => 'required|in:threshold,offline,custom',
            'config' => 'required|array',
            'notification_channels' => 'required|array',
        ]);

        $rule = $device->alertRules()->create([
            'user_id' => $request->user()->id,
            ...$validated,
        ]);

        return response()->json($rule, 201);
    }

    public function update(Request $request, AlertRule $rule) {
        $this->authorize('update', $rule->device);

        $rule->update($request->validate([
            'name' => 'string|max:255',
            'config' => 'array',
            'notification_channels' => 'array',
            'is_active' => 'boolean',
        ]));

        return response()->json($rule);
    }

    public function destroy(Request $request, AlertRule $rule) {
        $this->authorize('delete', $rule->device);
        $rule->delete();
        return response()->json(['message' => 'Rule deleted']);
    }
}
```

---

## Task 3.10: Queue Configuration

Update `.env`:
```env
QUEUE_CONNECTION=database
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-email
MAIL_PASSWORD=your-password
MAIL_FROM_ADDRESS=noreply@iotplatform.com
```

Run queue worker:
```bash
php artisan queue:work
```

Or for development:
```bash
php artisan queue:listen
```

---

### Deliverables (End of Week 3)
- ✅ Alert rules can be created/edited/deleted
- ✅ Alert evaluation job runs after each reading
- ✅ Alerts fire when thresholds exceeded
- ✅ Auto-resolve when condition clears
- ✅ Snooze functionality working
- ✅ Email notifications sent
- ✅ Alert history visible in UI
- ✅ Queue worker for async processing

---

# WEEK 4: BIDIRECTIONAL CONTROL + REPORTING

## Goal
Users send commands to devices. Commands are ACK'd. Users export data.

---

## Task 4.1: Create `device_commands` Table

```bash
php artisan make:migration create_device_commands_table
```

```php
// database/migrations/2026_05_05_create_device_commands_table.php
return new class extends Migration {
    public function up(): void {
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')
                ->constrained('devices')
                ->onDelete('cascade');
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->string('command_type');              // 'toggle', 'set_temperature'
            $table->json('payload');                     // Command parameters
            $table->string('status')->default('pending'); // pending, sent, acked, failed, timeout
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('device_commands');
    }
};
```

## Task 4.2: Create `DeviceCommand` Model

```php
// app/Models/DeviceCommand.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCommand extends Model {
    protected $fillable = [
        'device_id', 'user_id', 'command_type', 'payload',
        'status', 'sent_at', 'acked_at', 'error_message'
    ];
    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
        'acked_at' => 'datetime',
    ];

    public function device(): BelongsTo {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    // Send this command to device
    public function send(): bool {
        try {
            $mqtt = resolve('mqtt');
            $topic = $this->device->mqtt_topic_command;
            
            $payload = json_encode([
                'id' => $this->id,
                'type' => $this->command_type,
                'payload' => $this->payload,
                'sent_at' => now()->timestamp,
            ]);

            $mqtt->publish($topic, $payload, 1); // QoS 1
            $this->update(['status' => 'sent', 'sent_at' => now()]);
            
            // Set timeout job
            dispatch(new CheckCommandTimeout($this))
                ->delay(now()->addSeconds($this->device->type->command_timeout_seconds ?? 30));
            
            return true;
        } catch (\Exception $e) {
            $this->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // Device acknowledged the command
    public function acknowledge(): void {
        $this->update(['status' => 'acked', 'acked_at' => now()]);
    }

    // Check if timed out
    public function isTimedOut(): bool {
        if ($this->status !== 'sent') return false;
        
        $timeout = $this->device->type->command_timeout_seconds ?? 30;
        return $this->sent_at->addSeconds($timeout)->isPast();
    }
}
```

## Task 4.3: Create Command API Controller

```bash
php artisan make:controller Api/DeviceCommandController
```

```php
// app/Http/Controllers/Api/DeviceCommandController.php
namespace App\Http\Controllers\Api;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DeviceCommandController extends Controller {
    // Send command to device
    public function store(Request $request, Device $device) {
        $this->authorize('control', $device);

        // Device must be bidirectional
        if (!$device->isBidirectional()) {
            return response()->json([
                'error' => 'Device does not support commands'
            ], 422);
        }

        $validated = $request->validate([
            'command_type' => 'required|string',
            'payload' => 'nullable|array',
        ]);

        // Validate command is allowed for this device type
        if (!$device->type->isValidCommand($validated['command_type'])) {
            return response()->json([
                'error' => 'Invalid command for this device type'
            ], 422);
        }

        // Create command
        $command = $device->commands()->create([
            'user_id' => $request->user()->id,
            'command_type' => $validated['command_type'],
            'payload' => $validated['payload'] ?? [],
        ]);

        // Send to device
        if (!$command->send()) {
            return response()->json([
                'error' => 'Failed to send command to device'
            ], 500);
        }

        return response()->json($command, 201);
    }

    // Get command status
    public function show(Request $request, DeviceCommand $command) {
        $this->authorize('view', $command->device);
        return response()->json($command);
    }

    // Get command history
    public function history(Request $request, Device $device) {
        $this->authorize('view', $device);

        return response()->json(
            $device->commands()
                ->with('user')
                ->orderBy('created_at', 'desc')
                ->paginate(50)
        );
    }
}
```

## Task 4.4: Command Acknowledgment Handler

```bash
php artisan make:command ConsumeCommandAcknowledgments
```

```php
// app/Console/Commands/ConsumeCommandAcknowledgments.php
namespace App\Console\Commands;

use App\Models\DeviceCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;

class ConsumeCommandAcknowledgments extends Command {
    protected $signature = 'mqtt:consume-command-acks';
    protected $description = 'Listen for device command acknowledgments';

    public function handle(): int {
        $mqtt = MQTT::connection();

        $this->line('Listening for command acknowledgments...');
        
        $mqtt->subscribe('devices/+/command-ack', function($topic, $message) {
            $this->handleAck($topic, $message);
        });

        $mqtt->loop(true);
        return self::SUCCESS;
    }

    private function handleAck(string $topic, string $message): void {
        try {
            $payload = json_decode($message, true);
            if (!$payload) return;

            $commandId = $payload['command_id'] ?? null;
            if (!$commandId) return;

            $command = DeviceCommand::find($commandId);
            if (!$command) return;

            if ($payload['status'] === 'failed') {
                $command->update([
                    'status' => 'failed',
                    'error_message' => $payload['error'] ?? 'Unknown error',
                ]);
            } else {
                $command->acknowledge();
            }

            Log::info('Command ACK received', [
                'command_id' => $commandId,
                'status' => $payload['status'],
            ]);
        } catch (\Exception $e) {
            Log::error('ACK handling error', ['error' => $e->getMessage()]);
        }
    }
}
```

## Task 4.5: Reporting API

```bash
php artisan make:controller Api/ReportingController
```

```php
// app/Http/Controllers/Api/ReportingController.php
namespace App\Http\Controllers\Api;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportingController extends Controller {
    // Export device readings as CSV
    public function exportCsv(Request $request, Device $device) {
        $this->authorize('view', $device);

        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after:from',
            'format' => 'in:csv,json',
        ]);

        $from = strtotime($request->from);
        $to = strtotime($request->to);

        $readings = $device->readings()
            ->whereBetween('ts', [$from, $to])
            ->orderBy('ts')
            ->get();

        if ($request->format === 'json') {
            return response()->json([
                'device' => $device->only(['id', 'name', 'code']),
                'data_points' => $readings->count(),
                'readings' => $readings->map(fn($r) => [
                    'timestamp' => date('Y-m-d H:i:s', $r->ts),
                    ...$r->payload,
                ]),
            ]);
        }

        // CSV export
        return new StreamedResponse(function() use ($readings, $device) {
            $handle = fopen('php://output', 'w');
            
            // Header
            fputcsv($handle, array_merge(['Timestamp'], array_keys($readings->first()?->payload ?? [])));
            
            // Data rows
            foreach ($readings as $reading) {
                fputcsv($handle, array_merge(
                    [date('Y-m-d H:i:s', $reading->ts)],
                    array_values($reading->payload)
                ));
            }
            
            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $device->code . '_' . date('Y-m-d') . '.csv"',
        ]);
    }

    // Summary statistics
    public function summary(Request $request, Device $device) {
        $this->authorize('view', $device);

        $request->validate(['from' => 'required|date', 'to' => 'required|date']);

        $from = strtotime($request->from);
        $to = strtotime($request->to);

        $readings = $device->readings()
            ->whereBetween('ts', [$from, $to])
            ->get();

        if ($readings->isEmpty()) {
            return response()->json(['error' => 'No readings in date range'], 404);
        }

        $summary = [];
        $payload = $readings->first()->payload;

        foreach (array_keys($payload) as $field) {
            $values = $readings->pluck("payload.{$field}")->filter();
            if ($values->isEmpty()) continue;

            $summary[$field] = [
                'min' => $values->min(),
                'max' => $values->max(),
                'avg' => $values->avg(),
                'count' => $values->count(),
            ];
        }

        return response()->json($summary);
    }
}
```

## Task 4.6: Update Routes

```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    // Commands
    Route::post('/devices/{device}/commands', [DeviceCommandController::class, 'store']);
    Route::get('/devices/{device}/commands', [DeviceCommandController::class, 'history']);
    Route::get('/devices/{device}/commands/{command}', [DeviceCommandController::class, 'show']);

    // Reporting
    Route::get('/devices/{device}/export', [ReportingController::class, 'exportCsv']);
    Route::get('/devices/{device}/summary', [ReportingController::class, 'summary']);
});
```

## Task 4.7: Command Timeout Job

```bash
php artisan make:job CheckCommandTimeout
```

```php
// app/Jobs/CheckCommandTimeout.php
namespace App\Jobs;

use App\Models\DeviceCommand;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class CheckCommandTimeout implements ShouldQueue {
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public DeviceCommand $command) {}

    public function handle(): void {
        if ($this->command->isTimedOut()) {
            $this->command->update([
                'status' => 'timeout',
                'error_message' => 'Device did not acknowledge within timeout period',
            ]);

            Log::warning('Command timeout', [
                'command_id' => $this->command->id,
                'device_id' => $this->command->device_id,
            ]);
        }
    }
}
```

### Deliverables (End of Week 4)
- ✅ Users can send commands to bidirectional devices
- ✅ Commands are tracked (pending → sent → acked/failed/timeout)
- ✅ Timeout mechanism working
- ✅ CSV/JSON export working
- ✅ Summary statistics API ready
- ✅ Command history visible

---

## FINAL CHECKLIST

- [ ] All 4 weeks completed
- [ ] 200+ tests passing
- [ ] Auth system production-ready
- [ ] Multi-user isolation verified
- [ ] Device type framework tested
- [ ] Alerting system working
- [ ] Command/control working
- [ ] Reporting functional
- [ ] MQTT consumers (2) running under Supervisor
- [ ] Email notifications working
- [ ] Load tested (1000+ readings/sec)
- [ ] Security audit completed
- [ ] Responsive UI implemented
- [ ] Admin panel basic features
- [ ] Audit logs enabled
- [ ] Rate limiting enabled
- [ ] Backups automated
- [ ] Error tracking (Sentry) integrated

---

**Ready to start Week 1?** I can provide even more detailed code line-by-line if needed.

