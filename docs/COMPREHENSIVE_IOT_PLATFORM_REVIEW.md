# Comprehensive IoT Platform Review & Architecture
**Date:** 2026-05-05  
**Scope:** B2C Consumer IoT Platform (10K+ heterogeneous devices)  
**Current Status:** Meter pilot with working MQTT ingestion, reliability hardening done, **NO authentication**

---

## EXECUTIVE SUMMARY

### What You Have (Strong)
✅ Meter MQTT ingestion working reliably  
✅ Real-time dashboard with WebSockets (Reverb)  
✅ Database design with smart layering (history + cached latest state)  
✅ MQTT consumer with reconnect backoff, QoS 1, structured logging  
✅ Out-of-order telemetry protection  
✅ Test coverage (39 tests)  
✅ Modern Laravel 12 with proper patterns  

### What's Missing (Blocking)
❌ **Authentication/Authorization** — Platform is completely open, no login  
❌ **Multi-user isolation** — No concept of "which devices belong to which user"  
❌ **Device type abstraction** — Only meters hardcoded, can't add AC/switches without code changes  
❌ **Bidirectional control** — Can't send commands back to devices  
❌ **Alerting system** — No thresholds, no notifications  
❌ **Reporting/exports** — No way to download or analyze data  

### Your Situation
- **B2C model:** Each user monitors their own devices at home
- **Device heterogeneity:** Meters, AC controllers, switches, water systems — each has different payloads
- **Bidirectional requirement:** Some devices are read-only (meters), some need command/control (AC, switches)
- **Meter payload (PZEM):** `{ts, voltage, current, power, energy_computed_wh, energy_pzem_wh, frequency, pf}`
- **MQTT topics:** Separate paths: `devices/{id}/data` (telemetry) and `devices/{id}/command` (commands)
- **Priority:** Complete meter system end-to-end with auth/alerts/reporting **FIRST**, other devices UI placeholders

**This is not a small pilot anymore. This is a real platform.**

---

## CRITICAL DECISION: BUILD FLEXIBLE FRAMEWORK NOW

You have two paths:

### Path A (Wrong): Hardcode meter system completely
```
- Build login for meters
- Build alerts for meters
- Build reporting for meters
- Then realize you can't add switches without duplicating everything
- Refactor everything (expensive, risky)
```

### Path B (Right): Build flexible device framework, implement meters first
```
- Build generic device abstraction (works for any device type)
- Implement meters fully on that abstraction
- Add AC/switches/water later using same framework
- No refactoring needed
```

**Recommendation: Path B** — It costs only 20% more upfront but saves 80% later when you add device types.

---

## DETAILED ARCHITECTURE RECOMMENDATIONS

### 1. DATABASE SCHEMA

Current state:
```
devices → meter_readings → latest_meter_states
```

Problems:
- `meter_readings` has hardcoded columns: voltage, current, power, energy_computed_wh, etc.
- AC controller doesn't have "voltage" — it has temperature, humidity, mode, fan_speed
- Water system doesn't have "power" — it has flow_rate, pressure, volume
- Every new device type requires new migration (not flexible)

**Solution: JSON payload + device schema registry**

```sql
-- 1. Device types registry
CREATE TABLE device_types (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(50) UNIQUE,              -- 'meter', 'ac_controller', 'switch', 'water_system'
    name VARCHAR(255),                     -- 'Electricity Meter', 'AC Controller', etc.
    category VARCHAR(50),                  -- 'energy', 'climate', 'control', 'water'
    schema_version INT DEFAULT 1,
    payload_schema JSON,                   -- Define expected fields
    is_bidirectional BOOLEAN DEFAULT false, -- Can this device receive commands?
    created_at TIMESTAMP
);

-- 2. User accounts (BLOCKING — must exist before anything)
CREATE TABLE users (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255),
    email VARCHAR(255) UNIQUE,
    email_verified_at TIMESTAMP NULL,
    password VARCHAR(255),
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- 3. Device ownership (user → device relationship)
CREATE TABLE device_user (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    device_id BIGINT NOT NULL,
    role VARCHAR(50) DEFAULT 'owner',       -- 'owner', 'editor', 'viewer'
    granted_at TIMESTAMP,
    UNIQUE(user_id, device_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE
);

-- 4. Devices table (refactored)
CREATE TABLE devices (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    device_type_id BIGINT NOT NULL,          -- Links to device_types.id
    code VARCHAR(255) UNIQUE,                -- 'METER-001', 'SWITCH-01', etc.
    name VARCHAR(255),                        -- User-friendly name
    mqtt_topic_data VARCHAR(255) UNIQUE,     -- 'devices/1/data'
    mqtt_topic_command VARCHAR(255),         -- 'devices/1/command' (nullable if read-only)
    metadata JSON,                            -- Custom config per device type
    is_active BOOLEAN DEFAULT true,
    last_seen_at TIMESTAMP NULL,
    last_data_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY(device_type_id) REFERENCES device_types(id)
);

-- 5. Device readings (generic, works for ANY device type)
CREATE TABLE device_readings (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    device_id BIGINT NOT NULL,
    ts BIGINT NOT NULL,                     -- Timestamp from device payload
    payload JSON NOT NULL,                  -- Raw payload: {voltage: 220.5, current: 0.248, ...}
    received_at TIMESTAMP,
    created_at TIMESTAMP,
    UNIQUE(device_id, ts),                  -- Prevent duplicates
    FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE,
    INDEX(device_id, ts),
    INDEX(device_id, created_at)
);

-- 6. Latest cached state (ONE per device, any payload structure)
CREATE TABLE latest_device_states (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    device_id BIGINT NOT NULL UNIQUE,
    ts BIGINT,
    payload JSON,                           -- Latest payload snapshot
    received_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE
);

-- 7. Device commands (for bidirectional devices)
CREATE TABLE device_commands (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    device_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    command_type VARCHAR(50),               -- 'toggle', 'set_temperature', etc.
    payload JSON,                           -- Command parameters
    status VARCHAR(50) DEFAULT 'pending',   -- 'pending', 'sent', 'acked', 'failed', 'timeout'
    sent_at TIMESTAMP NULL,
    acked_at TIMESTAMP NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP,
    FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 8. Alert rules (per user, per device, flexible conditions)
CREATE TABLE alert_rules (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    device_id BIGINT NOT NULL,
    name VARCHAR(255),                      -- 'High Voltage', 'Device Offline', etc.
    condition_type VARCHAR(50),             -- 'threshold', 'offline', 'custom'
    config JSON,                            -- {field: 'voltage', operator: '>', value: 250}
    notification_channels JSON,             -- ['email', 'sms'] or ['push']
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE
);

-- 9. Active alerts (record of triggered alerts)
CREATE TABLE alerts (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    rule_id BIGINT NOT NULL,
    device_id BIGINT NOT NULL,
    severity VARCHAR(50),                   -- 'critical', 'warning', 'info'
    trigger_reason TEXT,                    -- What caused the alert
    triggered_at TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    FOREIGN KEY(rule_id) REFERENCES alert_rules(id) ON DELETE CASCADE,
    FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE
);

-- 10. Notifications sent (audit trail)
CREATE TABLE notifications (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    alert_id BIGINT NOT NULL,
    channel VARCHAR(50),                    -- 'email', 'sms', 'push'
    status VARCHAR(50),                     -- 'pending', 'sent', 'failed'
    sent_at TIMESTAMP NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(alert_id) REFERENCES alerts(id) ON DELETE CASCADE
);

-- 11. Audit log (who did what)
CREATE TABLE audit_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    action VARCHAR(100),                    -- 'device.created', 'alert.triggered', 'command.sent'
    resource_type VARCHAR(50),              -- 'device', 'alert', 'command'
    resource_id BIGINT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    created_at TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

**Why this structure:**
- ✅ `device_types` defines device schema once, reusable
- ✅ `device_user` enables multi-user, shared access, roles
- ✅ `device_readings` uses JSON payload (works for meters, AC, switches, anything)
- ✅ `device_commands` tracks bidirectional communication
- ✅ `alert_rules` and `alerts` enable flexible alerting
- ✅ Scoped queries by `user_id` prevent data leakage

---

### 2. MODELS (Eloquent)

```php
// app/Models/User.php
class User extends Authenticatable {
    use HasFactory, Notifiable;
    
    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];
    
    // Relationships
    public function devices() {
        return $this->belongsToMany(Device::class, 'device_user')
            ->withPivot('role', 'granted_at')
            ->withTimestamps();
    }
    
    public function ownedDevices() {
        return $this->devices()->where('device_user.role', 'owner');
    }
    
    public function alertRules() {
        return $this->hasMany(AlertRule::class);
    }
    
    public function alerts() {
        return $this->hasManyThrough(Alert::class, AlertRule::class);
    }
    
    public function commands() {
        return $this->hasMany(DeviceCommand::class);
    }
    
    // Helper methods
    public function canAccessDevice(Device $device): bool {
        return $this->devices()->where('device_id', $device->id)->exists();
    }
    
    public function canControlDevice(Device $device): bool {
        $role = $this->devices()
            ->wherePivot('device_id', $device->id)
            ->first()?->pivot->role;
        return in_array($role, ['owner', 'editor']);
    }
}

// app/Models/DeviceType.php
class DeviceType extends Model {
    protected $fillable = ['code', 'name', 'category', 'payload_schema', 'is_bidirectional'];
    protected $casts = [
        'payload_schema' => 'array',
        'is_bidirectional' => 'boolean',
    ];
    
    public function devices() {
        return $this->hasMany(Device::class);
    }
    
    // Validate payload against schema
    public function validatePayload(array $payload): array {
        $schema = $this->payload_schema;
        $errors = [];
        
        foreach ($schema['required_fields'] ?? [] as $field) {
            if (!isset($payload[$field])) {
                $errors[$field] = "Required field missing";
            }
        }
        
        return $errors; // Empty = valid
    }
}

// app/Models/Device.php
class Device extends Model {
    protected $fillable = ['device_type_id', 'code', 'name', 'mqtt_topic_data', 'mqtt_topic_command', 'metadata', 'is_active'];
    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_data_at' => 'datetime',
    ];
    
    // Relationships
    public function type() {
        return $this->belongsTo(DeviceType::class, 'device_type_id');
    }
    
    public function users() {
        return $this->belongsToMany(User::class, 'device_user')
            ->withPivot('role', 'granted_at');
    }
    
    public function readings() {
        return $this->hasMany(DeviceReading::class);
    }
    
    public function latestState() {
        return $this->hasOne(LatestDeviceState::class);
    }
    
    public function commands() {
        return $this->hasMany(DeviceCommand::class);
    }
    
    public function alertRules() {
        return $this->hasMany(AlertRule::class);
    }
    
    public function alerts() {
        return $this->hasMany(Alert::class);
    }
    
    // Scopes
    public function scopeForUser($query, User $user) {
        return $query->whereHas('users', fn($q) => $q->where('user_id', $user->id));
    }
    
    public function scopeActive($query) {
        return $query->where('is_active', true);
    }
    
    // Helpers
    public function getHealthStatus(): string {
        if (!$this->last_data_at) return 'unknown';
        $secondsSinceLastData = now()->diffInSeconds($this->last_data_at);
        
        if ($secondsSinceLastData > 300) return 'down';      // 5 min
        if ($secondsSinceLastData > 60) return 'stale';      // 1 min
        return 'online';
    }
    
    public function isBidirectional(): bool {
        return $this->type->is_bidirectional;
    }
}

// app/Models/DeviceReading.php
class DeviceReading extends Model {
    public $timestamps = false;
    protected $fillable = ['device_id', 'ts', 'payload', 'received_at'];
    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
    ];
    
    public function device() {
        return $this->belongsTo(Device::class);
    }
    
    public function scopeInTimeRange($query, int $startTs, int $endTs) {
        return $query->whereBetween('ts', [$startTs, $endTs]);
    }
}

// app/Models/AlertRule.php
class AlertRule extends Model {
    protected $fillable = ['user_id', 'device_id', 'name', 'condition_type', 'config', 'notification_channels', 'is_active'];
    protected $casts = [
        'config' => 'array',
        'notification_channels' => 'array',
        'is_active' => 'boolean',
    ];
    
    public function user() {
        return $this->belongsTo(User::class);
    }
    
    public function device() {
        return $this->belongsTo(Device::class);
    }
    
    public function alerts() {
        return $this->hasMany(Alert::class);
    }
    
    // Evaluate if this rule is triggered by a reading
    public function evaluate(DeviceReading $reading): bool {
        $payload = $reading->payload;
        $config = $this->config;
        
        if ($this->condition_type === 'threshold') {
            $field = $config['field'];
            $operator = $config['operator'];
            $value = $config['value'];
            $actual = $payload[$field] ?? null;
            
            if ($actual === null) return false;
            
            return match($operator) {
                '>' => $actual > $value,
                '<' => $actual < $value,
                '==' => $actual == $value,
                '>=' => $actual >= $value,
                '<=' => $actual <= $value,
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
class Alert extends Model {
    protected $fillable = ['rule_id', 'device_id', 'severity', 'trigger_reason', 'triggered_at', 'resolved_at'];
    protected $casts = [
        'triggered_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
    
    public function rule() {
        return $this->belongsTo(AlertRule::class);
    }
    
    public function device() {
        return $this->belongsTo(Device::class);
    }
    
    public function notifications() {
        return $this->hasMany(Notification::class);
    }
}

// app/Models/DeviceCommand.php
class DeviceCommand extends Model {
    protected $fillable = ['device_id', 'user_id', 'command_type', 'payload', 'status', 'sent_at', 'acked_at', 'error_message'];
    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
        'acked_at' => 'datetime',
    ];
    
    public function device() {
        return $this->belongsTo(Device::class);
    }
    
    public function user() {
        return $this->belongsTo(User::class);
    }
    
    // Send this command to device via MQTT
    public function send(): bool {
        try {
            $mqtt = resolve('mqtt');
            $topic = $this->device->mqtt_topic_command;
            $payload = json_encode([
                'id' => $this->id,
                'type' => $this->command_type,
                'payload' => $this->payload,
            ]);
            
            $mqtt->publish($topic, $payload, 1); // QoS 1
            $this->update(['status' => 'sent', 'sent_at' => now()]);
            return true;
        } catch (\Exception $e) {
            $this->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            return false;
        }
    }
    
    // Acknowledge receipt from device
    public function acknowledge(): void {
        $this->update(['status' => 'acked', 'acked_at' => now()]);
    }
}
```

---

### 3. MIGRATION STRATEGY

**Phase 1.1: Auth (Week 1)**
- Create `users` table
- Migrate `Device` model to add `device_user` pivot
- Add auth middleware

**Phase 1.2: Device Type Framework (Week 1-2)**
- Create `device_types` table
- Create `device_commands` table
- Seed initial device types (meter, ac_controller, etc.)
- Migrate `device_readings` from hardcoded columns to JSON payload

**Phase 2: Alerting (Week 2-3)**
- Create `alert_rules`, `alerts`, `notifications` tables
- Build alert evaluation engine
- Build notification sender

**Phase 3: Reporting (Week 4)**
- Add reporting API endpoint
- Add exports endpoint

---

### 4. AUTHENTICATION IMPLEMENTATION (Week 1)

Since **auth is BLOCKING everything**, you need this first.

```php
// 1. Add to composer.json (already there!)
// "laravel/sanctum": "^4.0"

// 2. Use Laravel Breeze for scaffold
php artisan breeze:install blade

// 3. This gives you:
// - Login/Register pages
// - Password reset
// - Email verification (optional)
// - Session-based auth

// 4. Add API token auth for mobile/external access
// In User model:
// use Laravel\Sanctum\HasApiTokens;
// class User extends Authenticatable {
//     use HasApiTokens;
// }

// 5. Protect routes with middleware
Route::middleware('auth')->group(function () {
    Route::get('/devices', [DeviceController::class, 'index']);
    Route::post('/devices', [DeviceController::class, 'store']);
});

// 6. Scope queries to authenticated user
public function index(Request $request) {
    return Device::forUser($request->user())->get();
}
```

---

### 5. DEVICE TELEMETRY INGESTION (Refactored)

Currently:
```php
// app/Console/Commands/ConsumeMeterTopic.php
// Hardcoded to expect meter fields
```

Refactored (flexible):

```php
// app/Console/Commands/ConsumeDeviceTelemetry.php
namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DeviceReading;
use App\Services\DeviceTelemetryProcessor;
use Illuminate\Console\Command;
use PhpMqtt\Client\Facades\MQTT;
use Illuminate\Support\Facades\Log;

class ConsumeDeviceTelemetry extends Command {
    protected $signature = 'mqtt:consume {--device=}';
    protected $description = 'Consume telemetry from all or specific devices';
    
    public function __construct(
        private DeviceTelemetryProcessor $processor
    ) {
        parent::__construct();
    }
    
    public function handle(): int {
        $devices = $this->getDevices();
        
        if ($devices->isEmpty()) {
            $this->error('No active devices found');
            return self::FAILURE;
        }
        
        $mqtt = MQTT::connection();
        
        foreach ($devices as $device) {
            $this->line("Subscribing to: {$device->mqtt_topic_data}");
            
            $mqtt->subscribe(
                $device->mqtt_topic_data,
                function ($topic, $message) use ($device) {
                    $this->processMessage($device, $topic, $message);
                },
                config('mqtt-client.subscribe_qos', 1)
            );
        }
        
        $this->line('Listening for telemetry...');
        $mqtt->loop(true);
        
        return self::SUCCESS;
    }
    
    private function getDevices() {
        if ($this->option('device')) {
            return Device::where('id', $this->option('device'))
                ->where('is_active', true)
                ->get();
        }
        return Device::where('is_active', true)->get();
    }
    
    private function processMessage(Device $device, string $topic, string $message): void {
        try {
            $payload = json_decode($message, true);
            if (!$payload) {
                Log::warning('Invalid JSON from device', [
                    'device_id' => $device->id,
                    'topic' => $topic,
                ]);
                return;
            }
            
            // Use service to process
            $result = $this->processor->process($device, $payload);
            
            if ($result->success) {
                Log::info('Telemetry processed', [
                    'device_id' => $device->id,
                    'reading_id' => $result->reading->id,
                ]);
            } else {
                Log::warning($result->error, $result->context);
            }
        } catch (\Exception $e) {
            Log::error('Telemetry processing failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

// app/Services/DeviceTelemetryProcessor.php
namespace App\Services;

use App\Models\Device;
use App\Models\DeviceReading;
use App\Models\LatestDeviceState;
use Illuminate\Support\Facades\DB;

class DeviceTelemetryProcessor {
    public function process(Device $device, array $payload): TelemetryResult {
        // 1. Validate against device type schema
        $errors = $device->type->validatePayload($payload);
        if (!empty($errors)) {
            return TelemetryResult::error('Payload validation failed', [
                'device_id' => $device->id,
                'errors' => $errors,
            ]);
        }
        
        // 2. Extract timestamp (device-provided or now)
        $ts = $payload['ts'] ?? time();
        
        // 3. Store reading in transaction
        $result = DB::transaction(function () use ($device, $payload, $ts) {
            // Check if duplicate
            $existing = DeviceReading::where('device_id', $device->id)
                ->where('ts', $ts)
                ->first();
            
            if ($existing) {
                return TelemetryResult::duplicate($existing);
            }
            
            // Store reading
            $reading = DeviceReading::create([
                'device_id' => $device->id,
                'ts' => $ts,
                'payload' => $payload,
                'received_at' => now(),
            ]);
            
            // Update latest state (with out-of-order protection)
            $latestState = LatestDeviceState::lockForUpdate()
                ->where('device_id', $device->id)
                ->first();
            
            $shouldUpdate = !$latestState || $ts >= ($latestState->ts ?? 0);
            
            if ($shouldUpdate) {
                LatestDeviceState::updateOrCreate(
                    ['device_id' => $device->id],
                    [
                        'ts' => $ts,
                        'payload' => $payload,
                        'received_at' => now(),
                    ]
                );
            }
            
            // Update device last_data_at
            $device->update([
                'last_data_at' => now(),
                'last_seen_at' => now(),
            ]);
            
            return TelemetryResult::success($reading, $shouldUpdate);
        });
        
        // 4. Broadcast to realtime
        if ($result->success) {
            event(new DeviceReadingUpdated(
                $device,
                $result->reading,
                $result->latestStateUpdated
            ));
        }
        
        // 5. Evaluate alert rules (async job)
        dispatch(new EvaluateDeviceAlerts($device, $result->reading));
        
        return $result;
    }
}

// app/DTOs/TelemetryResult.php
class TelemetryResult {
    public function __construct(
        public bool $success,
        public ?DeviceReading $reading = null,
        public bool $latestStateUpdated = false,
        public ?string $error = null,
        public array $context = [],
    ) {}
    
    public static function success(DeviceReading $reading, bool $latestStateUpdated): self {
        return new self(true, $reading, $latestStateUpdated);
    }
    
    public static function duplicate(DeviceReading $existing): self {
        return new self(false, $existing, false, 'Duplicate reading');
    }
    
    public static function error(string $message, array $context): self {
        return new self(false, null, false, $message, $context);
    }
}
```

---

### 6. FLEXIBLE DEVICE CONTROL (Bidirectional)

For devices that can receive commands (AC, switches):

```php
// app/Http/Controllers/DeviceCommandController.php
namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DeviceCommandController extends Controller {
    public function store(Request $request, Device $device) {
        // User must own device and be able to edit
        Gate::authorize('control', $device);
        
        $validated = $request->validate([
            'command_type' => 'required|string',
            'payload' => 'array',
        ]);
        
        // Device must be bidirectional
        if (!$device->isBidirectional()) {
            return response()->json(['error' => 'Device is read-only'], 422);
        }
        
        // Create command record
        $command = DeviceCommand::create([
            'device_id' => $device->id,
            'user_id' => $request->user()->id,
            'command_type' => $validated['command_type'],
            'payload' => $validated['payload'],
            'status' => 'pending',
        ]);
        
        // Send to device via MQTT
        if ($command->send()) {
            return response()->json(['id' => $command->id, 'status' => 'sent']);
        } else {
            return response()->json(['error' => 'Failed to send command'], 500);
        }
    }
    
    public function show(Request $request, DeviceCommand $command) {
        Gate::authorize('view', $command);
        return response()->json($command);
    }
    
    public function history(Request $request, Device $device) {
        Gate::authorize('view', $device);
        
        return response()->json(
            $device->commands()
                ->where('user_id', $request->user()->id)
                ->orderBy('created_at', 'desc')
                ->paginate(50)
        );
    }
}

// Handle command ACKs from device
// When device receives command, it publishes ACK to: devices/{id}/command-ack
// app/Console/Commands/ConsumeCommandAcks.php
class ConsumeCommandAcks extends Command {
    public function handle() {
        $mqtt = MQTT::connection();
        $mqtt->subscribe('devices/+/command-ack', function($topic, $message) {
            $payload = json_decode($message, true);
            $commandId = $payload['command_id'] ?? null;
            $status = $payload['status'] ?? 'acked'; // 'acked', 'failed'
            
            if ($commandId) {
                $command = DeviceCommand::find($commandId);
                if ($status === 'failed') {
                    $command->update([
                        'status' => 'failed',
                        'error_message' => $payload['error'] ?? 'Unknown error',
                    ]);
                } else {
                    $command->acknowledge();
                }
            }
        });
        $mqtt->loop(true);
    }
}
```

---

### 7. ALERTING ENGINE (Week 2-3)

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

class EvaluateDeviceAlerts implements ShouldQueue {
    use InteractsWithQueue, Queueable, SerializesModels;
    
    public function __construct(
        public Device $device,
        public DeviceReading $reading
    ) {}
    
    public function handle() {
        $rules = $this->device->alertRules()
            ->where('is_active', true)
            ->get();
        
        foreach ($rules as $rule) {
            // Check if rule is triggered
            if ($rule->evaluate($this->reading)) {
                // Close any existing unresolved alert for this rule
                Alert::where('rule_id', $rule->id)
                    ->whereNull('resolved_at')
                    ->update(['resolved_at' => now()]);
                
                // Create new alert
                $alert = Alert::create([
                    'rule_id' => $rule->id,
                    'device_id' => $this->device->id,
                    'severity' => $rule->config['severity'] ?? 'warning',
                    'trigger_reason' => $this->buildTriggerReason($rule, $this->reading),
                    'triggered_at' => now(),
                ]);
                
                // Send notifications
                dispatch(new SendAlertNotifications($alert, $rule));
            }
        }
    }
    
    private function buildTriggerReason(AlertRule $rule, DeviceReading $reading): string {
        $config = $rule->config;
        $field = $config['field'] ?? 'unknown';
        $value = $reading->payload[$field] ?? 'N/A';
        $threshold = $config['value'] ?? 'N/A';
        
        return "{$field} = {$value} (threshold: {$threshold})";
    }
}

// app/Jobs/SendAlertNotifications.php
class SendAlertNotifications implements ShouldQueue {
    public function handle(Alert $alert, AlertRule $rule) {
        $channels = $rule->notification_channels;
        
        foreach ($channels as $channel) {
            match($channel) {
                'email' => $this->sendEmail($alert, $rule),
                'sms' => $this->sendSms($alert, $rule),
                'push' => $this->sendPush($alert, $rule),
            };
        }
    }
    
    private function sendEmail(Alert $alert, AlertRule $rule) {
        // Send via Laravel Mail
        Mail::to($rule->user->email)
            ->queue(new AlertNotification($alert, $rule));
    }
}
```

---

### 8. DASHBOARD REFACTORING

Current: Single-device, hardcoded columns  
New: Multi-device, flexible payload

```blade
{{-- resources/views/devices/dashboard.blade.php --}}
@extends('layouts.app')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <!-- Device Selector -->
    <div class="col-span-1">
        <select id="device-selector" class="w-full p-2 border rounded">
            @foreach($user->devices as $device)
                <option value="{{ $device->id }}" @selected($device->id === $selectedDevice->id)>
                    {{ $device->name }} ({{ $device->type->name }})
                </option>
            @endforeach
        </select>
    </div>
    
    <!-- Dynamic KPI Cards -->
    <div class="col-span-2">
        <div class="grid grid-cols-2 gap-4">
            @foreach($selectedDevice->latestState->payload as $field => $value)
                <div class="bg-white p-4 rounded shadow">
                    <div class="text-sm text-gray-600 capitalize">{{ str_replace('_', ' ', $field) }}</div>
                    <div class="text-2xl font-bold">{{ $value }}</div>
                </div>
            @endforeach
        </div>
    </div>
</div>

<!-- Dynamic Charts -->
<div class="mt-8">
    <canvas id="readings-chart"></canvas>
</div>

<script>
// Listen for device selection change
document.getElementById('device-selector').addEventListener('change', (e) => {
    window.location = `/devices/${e.target.value}/dashboard`;
});

// Generic chart rendering (works for any fields)
const ctx = document.getElementById('readings-chart').getContext('2d');
const readings = @json($readings);
const fields = Object.keys(readings[0].payload);

new Chart(ctx, {
    type: 'line',
    data: {
        labels: readings.map(r => new Date(r.ts * 1000).toLocaleTimeString()),
        datasets: fields.map((field, i) => ({
            label: field,
            data: readings.map(r => r.payload[field]),
            borderColor: `hsl(${i * 60}, 70%, 50%)`,
        })),
    },
});
</script>
@endsection
```

---

## IMPLEMENTATION ROADMAP (4 WEEKS)

| Week | Task | Owner | Blocking | Output |
|------|------|-------|----------|--------|
| **Week 1** | Add Auth (Breeze), User model, device_user pivot | Backend | Everything | Multi-user login working |
| **Week 1** | Create DeviceType model, device_types table | Backend | Telemetry refactor | Device type framework ready |
| **Week 1** | Migrate meter_readings → device_readings (JSON payload) | Backend | Alerting | Generic readings table |
| **Week 2** | Refactor ConsumeMeterTopic → ConsumeDeviceTelemetry | Backend | Dashboard | Works with any device type |
| **Week 2** | Update dashboard to be multi-device, flexible | Frontend | N/A | Users can switch devices |
| **Week 3** | Build AlertRule, Alert, Notification models | Backend | N/A | Alert system ready |
| **Week 3** | Implement alert evaluation job + email sender | Backend | N/A | Alerts fire & send emails |
| **Week 4** | Add DeviceCommand table + bidirectional control | Backend | N/A | Can send commands to devices |
| **Week 4** | Reporting API + CSV export | Backend | N/A | Users can export data |

---

## SPECIFIC CODE CHANGES NEEDED

### Remove (Clean up)
- [ ] Delete commented legacy code in `meter-dashboard.blade.php`
- [ ] Delete `METER_DEVICE_CODE` env variable usage
- [ ] Delete `METER_TOPIC` env variable
- [ ] Delete `resources/js/echo.js` (unused)
- [ ] Rename all "meter" prefixed things to "device" prefixed

### Update (Refactor)
- [ ] Rename `ConsumeMeterTopic.php` → `ConsumeDeviceTelemetry.php`
- [ ] Rename `MeterReading` model → `DeviceReading` model
- [ ] Rename `MeterReadingUpdated` event → `DeviceReadingUpdated` event
- [ ] Rename `LatestMeterState` → `LatestDeviceState`
- [ ] Update all database table references (meter_readings → device_readings)
- [ ] Scope all controllers with `forUser()` queries

### Add (Critical)
- [ ] Create User authentication (Laravel Breeze)
- [ ] Create DeviceType model & table
- [ ] Create device_user pivot table
- [ ] Create AlertRule, Alert, Notification models
- [ ] Create DeviceCommand model for bidirectional control
- [ ] Create TelemetryProcessor service
- [ ] Update dashboard to multi-device
- [ ] Add authorization policies (Gate/Policy)

---

## DEPLOYMENT CHECKLIST

Before going live:
- [ ] Auth system tested (register, login, password reset)
- [ ] Multi-user isolation verified (user can only see own devices)
- [ ] Device type framework working (add meter, then test adding switch type)
- [ ] Alert rules tested
- [ ] Command ACK flow tested
- [ ] Tests passing (200+ tests)
- [ ] Load test with 1000+ readings/sec
- [ ] MQTT consumer running under Supervisor/systemd
- [ ] Backups automated
- [ ] Rate limiting enabled
- [ ] CORS configured for mobile
- [ ] Security headers added

---

## NEXT STEPS

**This week:**
1. Read this document completely
2. Ask clarifying questions on any architecture decisions
3. Create migration plan: What gets renamed first? What gets added first?
4. Decide: Refactor all meter code to device code first, OR add auth first?

**My recommendation:** Do auth first (1 week), then do refactoring (1 week), then features.

---

## QUESTIONS FOR YOU

Before I help implement:

1. **Authentication method:** Do you want email/password only, or also OAuth (Google/Apple sign-in)?
2. **Shared access:** Can users share individual devices with family, or all-or-nothing?
3. **Command timeout:** If you send a command to a device and it doesn't respond, how long before timeout?
4. **Alert resolution:** Do alerts auto-resolve when condition clears, or manual resolution only?
5. **Historical data:** How long keep readings? (1 month? 1 year? Forever?)

