# DETAILED IMPLEMENTATION PLAN
## Based on Your Specifications
**Date:** 2026-05-05  
**Timeline:** 4-5 weeks for complete MVP  
**Team:** Medium (5-10 devs)

---

## WEEK 1: AUTHENTICATION & FOUNDATION

### Goal
Users can register, login, and own devices. System knows which user owns which device.

### Tasks

#### 1.1 Install Laravel Breeze (Email/Password Auth)
```bash
php artisan breeze:install blade
npm install
npm run build
```

This gives you:
- ✅ Login page
- ✅ Register page
- ✅ Password reset flow
- ✅ Email verification (optional)
- ✅ User model with relationships
- ✅ Auth middleware

#### 1.2 Create `users` Table (Already exists in Breeze)
Breeze creates:
```sql
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
```

#### 1.3 Update `Device` Model to Link to User
```php
// app/Models/Device.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model {
    protected $fillable = ['user_id', 'code', 'name', 'mqtt_topic_data', 'mqtt_topic_command', 'metadata', 'is_active'];
    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_data_at' => 'datetime',
    ];
    
    // Relationship: Device belongs to one User
    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }
    
    // Scope: Only devices for this user
    public function scopeForUser($query, User $user) {
        return $query->where('user_id', $user->id);
    }
    
    // Scope: Only active devices
    public function scopeActive($query) {
        return $query->where('is_active', true);
    }
}
```

#### 1.4 Create Migration: Add `user_id` to Devices
```bash
php artisan make:migration add_user_id_to_devices_table
```

```php
// database/migrations/2026_05_05_add_user_id_to_devices_table.php
return new class extends Migration {
    public function up(): void {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('cascade')
                ->after('id');
            
            // Make sure we have unique index on (user_id, code)
            // so same device code can't exist twice for same user
            $table->unique(['user_id', 'code']);
        });
    }

    public function down(): void {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
            $table->dropUnique(['user_id', 'code']);
        });
    }
};
```

Run migration:
```bash
php artisan migrate
```

#### 1.5 Update `User` Model
```php
// app/Models/User.php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable {
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    // Relationship: User has many devices
    public function devices(): HasMany {
        return $this->hasMany(Device::class);
    }
    
    // Helper: Can user access this device?
    public function canAccessDevice(Device $device): bool {
        return $this->id === $device->user_id;
    }
}
```

#### 1.6 Create Device API Controller (Protected by Auth)
```bash
php artisan make:controller Api/DeviceController --resource
```

```php
// app/Http/Controllers/Api/DeviceController.php
namespace App\Http\Controllers\Api;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DeviceController extends Controller {
    // List only current user's devices
    public function index(Request $request) {
        return response()->json(
            $request->user()->devices()->get()
        );
    }

    // Create device for current user
    public function store(Request $request) {
        $validated = $request->validate([
            'code' => 'required|string|unique:devices,code,null,id,user_id,' . $request->user()->id,
            'name' => 'required|string|max:255',
            'mqtt_topic_data' => 'required|string|unique:devices',
            'mqtt_topic_command' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $device = $request->user()->devices()->create($validated);
        return response()->json($device, 201);
    }

    // Get single device (must own it)
    public function show(Request $request, Device $device) {
        $this->authorize('view', $device); // Check ownership
        return response()->json($device);
    }

    // Update device (must own it)
    public function update(Request $request, Device $device) {
        $this->authorize('update', $device);
        
        $validated = $request->validate([
            'name' => 'string|max:255',
            'metadata' => 'array',
            'is_active' => 'boolean',
        ]);

        $device->update($validated);
        return response()->json($device);
    }

    // Delete device (must own it)
    public function destroy(Request $request, Device $device) {
        $this->authorize('delete', $device);
        $device->delete();
        return response()->json(['message' => 'Device deleted']);
    }
}
```

#### 1.7 Create Authorization Policy
```bash
php artisan make:policy DevicePolicy --model=Device
```

```php
// app/Policies/DevicePolicy.php
namespace App\Policies;

use App\Models\User;
use App\Models\Device;

class DevicePolicy {
    public function view(User $user, Device $device): bool {
        return $user->id === $device->user_id;
    }

    public function update(User $user, Device $device): bool {
        return $user->id === $device->user_id;
    }

    public function delete(User $user, Device $device): bool {
        return $user->id === $device->user_id;
    }

    public function control(User $user, Device $device): bool {
        return $user->id === $device->user_id;
    }
}
```

Register in `app/Providers/AuthServiceProvider.php`:
```php
protected $policies = [
    Device::class => DevicePolicy::class,
];
```

#### 1.8 Update Routes
```php
// routes/api.php
use App\Http\Controllers\Api\DeviceController;

Route::middleware('auth:sanctum')->group(function () {
    // All device routes require authentication
    Route::apiResource('devices', DeviceController::class);
});
```

```php
// routes/web.php
// Already handled by Breeze
// Login: GET /login
// Register: GET /register
// Logout: POST /logout
// Dashboard: GET /dashboard (protected by auth middleware)
```

#### 1.9 Test Auth System
```bash
# Register a user
POST /register
{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123"
}

# Login
POST /login
{
    "email": "john@example.com",
    "password": "password123"
}

# Create device (authenticated)
POST /api/devices
Headers: Authorization: Bearer {token}
{
    "code": "METER-001",
    "name": "Living Room Meter",
    "mqtt_topic_data": "devices/1/data"
}

# Get user's devices
GET /api/devices
```

#### 1.10 Update MQTT Consumer (Minimal Changes)
For now, just scope it to active devices. Full refactoring comes in Week 2.

```php
// app/Console/Commands/ConsumeMeterTopic.php
// Existing code still works, but queries now must handle user_id in future
// Add comment: "TODO: Week 2 - Refactor for device type framework"
```

### Deliverables (End of Week 1)
- ✅ User registration working
- ✅ User login working  
- ✅ Users can create devices (owned by them)
- ✅ Users can only see their own devices
- ✅ API requires authentication
- ✅ Authorization policies in place
- ✅ Tests passing for auth flow

---

## WEEK 2: DEVICE TYPE FRAMEWORK

### Goal
System can handle any device type (meter, AC, switch) with different payloads. Not hardcoded to meter schema.

### Tasks

#### 2.1 Create `device_types` Table
```bash
php artisan make:migration create_device_types_table
```

```php
// database/migrations/2026_05_05_create_device_types_table.php
return new class extends Migration {
    public function up(): void {
        Schema::create('device_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();           // 'meter', 'ac_controller', 'switch', 'water_system'
            $table->string('name');                      // 'Electricity Meter', 'AC Controller', etc.
            $table->string('category');                  // 'energy', 'climate', 'control', 'water'
            $table->json('payload_schema')->nullable();  // Schema definition
            $table->boolean('is_bidirectional')->default(false); // Can receive commands?
            $table->integer('command_timeout_seconds')->default(30); // Default timeout
            $table->json('valid_commands')->nullable();  // ['toggle', 'set_temperature'] etc.
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('device_types');
    }
};
```

Run:
```bash
php artisan migrate
```

#### 2.2 Seed Initial Device Types
```bash
php artisan make:seeder DeviceTypeSeeder
```

```php
// database/seeders/DeviceTypeSeeder.php
namespace Database\Seeders;

use App\Models\DeviceType;
use Illuminate\Database\Seeder;

class DeviceTypeSeeder extends Seeder {
    public function run(): void {
        DeviceType::create([
            'code' => 'meter',
            'name' => 'Electricity Meter',
            'category' => 'energy',
            'is_bidirectional' => false,
            'payload_schema' => [
                'required_fields' => ['ts', 'voltage', 'current'],
                'optional_fields' => ['power', 'energy_computed_wh', 'energy_pzem_wh', 'frequency', 'pf'],
                'field_types' => [
                    'ts' => 'integer',
                    'voltage' => 'float',
                    'current' => 'float',
                    'power' => 'float',
                    'frequency' => 'integer',
                    'pf' => 'float',
                ],
            ],
        ]);

        DeviceType::create([
            'code' => 'ac_controller',
            'name' => 'AC Controller',
            'category' => 'climate',
            'is_bidirectional' => true,
            'command_timeout_seconds' => 60,
            'valid_commands' => ['toggle', 'set_temperature', 'set_mode'],
            'payload_schema' => [
                'required_fields' => ['ts', 'temperature', 'mode'],
                'optional_fields' => ['humidity', 'power_consumption', 'power_on'],
                'field_types' => [
                    'ts' => 'integer',
                    'temperature' => 'float',
                    'humidity' => 'float',
                    'mode' => 'string', // 'cool', 'heat', 'auto'
                ],
            ],
        ]);

        DeviceType::create([
            'code' => 'switch',
            'name' => '4-Gang Smart Switch',
            'category' => 'control',
            'is_bidirectional' => true,
            'command_timeout_seconds' => 30,
            'valid_commands' => ['toggle', 'on', 'off'],
            'payload_schema' => [
                'required_fields' => ['ts', 'state'],
                'optional_fields' => ['power_consumption'],
                'field_types' => [
                    'ts' => 'integer',
                    'state' => 'string', // 'on', 'off'
                ],
            ],
        ]);

        DeviceType::create([
            'code' => 'water_system',
            'name' => 'Water Management System',
            'category' => 'water',
            'is_bidirectional' => false,
            'payload_schema' => [
                'required_fields' => ['ts', 'flow_rate'],
                'optional_fields' => ['pressure', 'volume_used', 'temperature'],
                'field_types' => [
                    'ts' => 'integer',
                    'flow_rate' => 'float',
                    'pressure' => 'float',
                ],
            ],
        ]);
    }
}
```

Register in `database/seeders/DatabaseSeeder.php`:
```php
public function run(): void {
    $this->call([
        DeviceTypeSeeder::class,
    ]);
}
```

Run seeder:
```bash
php artisan db:seed --class=DeviceTypeSeeder
```

#### 2.3 Create `DeviceType` Model
```bash
php artisan make:model DeviceType
```

```php
// app/Models/DeviceType.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceType extends Model {
    protected $fillable = ['code', 'name', 'category', 'payload_schema', 'is_bidirectional', 'command_timeout_seconds', 'valid_commands'];
    protected $casts = [
        'payload_schema' => 'array',
        'is_bidirectional' => 'boolean',
        'valid_commands' => 'array',
    ];

    public function devices(): HasMany {
        return $this->hasMany(Device::class, 'device_type_id');
    }

    // Validate incoming payload against this type's schema
    public function validatePayload(array $payload): array {
        $schema = $this->payload_schema;
        if (!$schema) return []; // No schema defined = all valid
        
        $errors = [];
        
        // Check required fields
        foreach ($schema['required_fields'] ?? [] as $field) {
            if (!isset($payload[$field])) {
                $errors[$field] = "Required field missing";
            }
        }
        
        // Check field types (optional)
        $fieldTypes = $schema['field_types'] ?? [];
        foreach ($fieldTypes as $field => $expectedType) {
            if (!isset($payload[$field])) continue;
            
            $value = $payload[$field];
            $actualType = gettype($value);
            
            if ($expectedType === 'float' && !is_numeric($value)) {
                $errors[$field] = "Expected float, got " . gettype($value);
            } elseif ($expectedType === 'integer' && !is_int($value)) {
                $errors[$field] = "Expected integer, got " . gettype($value);
            }
        }
        
        return $errors; // Empty = valid
    }

    // Check if a command is valid for this device type
    public function isValidCommand(string $commandType): bool {
        if (!$this->is_bidirectional) return false;
        if (!$this->valid_commands) return true; // All commands OK if not specified
        return in_array($commandType, $this->valid_commands);
    }
}
```

#### 2.4 Update `Device` Model to Link to DeviceType
```php
// app/Models/Device.php (update)
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model {
    protected $fillable = [
        'user_id', 
        'device_type_id',  // NEW
        'code', 
        'name', 
        'mqtt_topic_data', 
        'mqtt_topic_command', 
        'metadata', 
        'is_active'
    ];

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo {  // NEW
        return $this->belongsTo(DeviceType::class, 'device_type_id');
    }

    public function scopeForUser($query, User $user) {
        return $query->where('user_id', $user->id);
    }

    public function scopeActive($query) {
        return $query->where('is_active', true);
    }

    // Check if device is bidirectional
    public function isBidirectional(): bool {
        return $this->type->is_bidirectional;
    }

    // Get health status
    public function getHealthStatus(): string {
        if (!$this->last_data_at) return 'unknown';
        $secondsSinceLastData = now()->diffInSeconds($this->last_data_at);
        
        if ($secondsSinceLastData > 300) return 'down';      // 5 min
        if ($secondsSinceLastData > 60) return 'stale';      // 1 min
        return 'online';
    }
}
```

#### 2.5 Create Migration: Add `device_type_id` to Devices
```bash
php artisan make:migration add_device_type_id_to_devices_table
```

```php
// database/migrations/2026_05_05_add_device_type_id_to_devices_table.php
return new class extends Migration {
    public function up(): void {
        Schema::table('devices', function (Blueprint $table) {
            // Add device_type_id after user_id
            $table->foreignId('device_type_id')
                ->nullable()
                ->constrained('device_types')
                ->onDelete('restrict')
                ->after('user_id');
        });
    }

    public function down(): void {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['device_type_id']);
            $table->dropColumn('device_type_id');
        });
    }
};
```

Run:
```bash
php artisan migrate
```

#### 2.6 Rename: `meter_readings` → `device_readings`

First, create new generic table:
```bash
php artisan make:migration create_device_readings_table
```

```php
// database/migrations/2026_05_05_create_device_readings_table.php
return new class extends Migration {
    public function up(): void {
        Schema::create('device_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')
                ->constrained('devices')
                ->onDelete('cascade');
            $table->bigInteger('ts');                    // Device timestamp
            $table->json('payload');                     // Generic payload: {voltage: 220.5, current: 5.2, ...}
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
            
            // Prevent duplicates
            $table->unique(['device_id', 'ts']);
            $table->index(['device_id', 'ts']);
            $table->index(['device_id', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('device_readings');
    }
};
```

Then copy data from meter_readings:
```bash
php artisan make:migration migrate_meter_readings_to_device_readings
```

```php
// database/migrations/2026_05_05_migrate_meter_readings_to_device_readings.php
return new class extends Migration {
    public function up(): void {
        // Copy meter readings to device_readings with payload JSON
        DB::statement("
            INSERT INTO device_readings (device_id, ts, payload, received_at, created_at, updated_at)
            SELECT 
                device_id,
                ts,
                JSON_OBJECT(
                    'voltage', voltage,
                    'current', current,
                    'power', power,
                    'energy_computed_wh', energy_computed_wh,
                    'energy_pzem_wh', energy_pzem_wh,
                    'frequency', frequency,
                    'pf', pf
                ),
                received_at,
                created_at,
                updated_at
            FROM meter_readings
        ");
    }

    public function down(): void {
        Schema::truncate('device_readings');
    }
};
```

#### 2.7 Create `DeviceReading` Model
```bash
php artisan make:model DeviceReading
```

```php
// app/Models/DeviceReading.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceReading extends Model {
    protected $table = 'device_readings';
    public $timestamps = true;
    protected $fillable = ['device_id', 'ts', 'payload', 'received_at'];
    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
    ];

    public function device(): BelongsTo {
        return $this->belongsTo(Device::class);
    }

    // Scope: Readings in time range
    public function scopeInTimeRange($query, int $startTs, int $endTs) {
        return $query->whereBetween('ts', [$startTs, $endTs]);
    }

    // Scope: Recent readings
    public function scopeRecent($query, int $minutes = 60) {
        $startTs = time() - ($minutes * 60);
        return $query->where('ts', '>', $startTs);
    }
}
```

#### 2.8 Update Dashboard to Show Dynamic Fields
```blade
{{-- resources/views/devices/dashboard.blade.php --}}
@extends('layouts.app')

@section('content')
<div class="container">
    <!-- Device Selector -->
    <div class="mb-4">
        <label for="device-selector" class="block text-sm font-medium mb-2">Select Device</label>
        <select id="device-selector" class="w-full p-2 border rounded">
            @foreach($user->devices as $device)
                <option value="{{ $device->id }}" @selected($device->id === $selectedDevice->id)>
                    {{ $device->name }} ({{ $device->type->name }})
                </option>
            @endforeach
        </select>
    </div>

    <!-- Device Info -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <div class="bg-blue-50 p-4 rounded">
            <p class="text-sm text-gray-600">Device Type</p>
            <p class="text-lg font-semibold">{{ $selectedDevice->type->name }}</p>
        </div>
        <div class="bg-green-50 p-4 rounded">
            <p class="text-sm text-gray-600">Health Status</p>
            <p class="text-lg font-semibold">{{ ucfirst($selectedDevice->getHealthStatus()) }}</p>
        </div>
    </div>

    <!-- Dynamic KPI Cards (Based on Latest Reading) -->
    @if($selectedDevice->latestState)
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            @foreach($selectedDevice->latestState->payload as $field => $value)
                <div class="bg-white p-4 rounded shadow">
                    <div class="text-xs text-gray-600 uppercase">{{ str_replace('_', ' ', $field) }}</div>
                    <div class="text-2xl font-bold mt-2">{{ number_format($value, 2) }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <!-- Dynamic Chart (Works for any fields) -->
    <div class="bg-white p-6 rounded shadow mb-8">
        <canvas id="readings-chart"></canvas>
    </div>

    <!-- Recent Readings Table -->
    <div class="bg-white rounded shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Timestamp</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Data</th>
                </tr>
            </thead>
            <tbody>
                @forelse($readings as $reading)
                    <tr class="border-t">
                        <td class="px-6 py-4 text-sm">{{ date('Y-m-d H:i:s', $reading->ts) }}</td>
                        <td class="px-6 py-4 text-sm font-mono">
                            <pre>{{ json_encode($reading->payload, JSON_PRETTY_PRINT) }}</pre>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="px-6 py-4 text-center text-gray-500">No readings yet</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Handle device selection
document.getElementById('device-selector').addEventListener('change', (e) => {
    window.location = `/devices/${e.target.value}/dashboard`;
});

// Generic chart renderer
@if($readings->count() > 0)
const readings = @json($readings->map(fn($r) => ['ts' => $r->ts, 'payload' => $r->payload]));
const payloadKeys = Object.keys(readings[0].payload).filter(k => is_numeric(readings[0].payload[k]));

const ctx = document.getElementById('readings-chart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: readings.map(r => new Date(r.ts * 1000).toLocaleTimeString()),
        datasets: payloadKeys.map((field, i) => ({
            label: field.replace(/_/g, ' '),
            data: readings.map(r => r.payload[field]),
            borderColor: `hsl(${i * (360 / payloadKeys.length)}, 70%, 50%)`,
            tension: 0.4,
        })),
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top' },
        },
        scales: {
            y: { beginAtZero: true },
        },
    },
});
@endif
</script>
@endsection
```

#### 2.9 Update Device API to Require `device_type_id`
```php
// app/Http/Controllers/Api/DeviceController.php (update store method)
public function store(Request $request) {
    $validated = $request->validate([
        'device_type_id' => 'required|exists:device_types,id',  // NEW
        'code' => 'required|string|unique:devices,code,null,id,user_id,' . $request->user()->id,
        'name' => 'required|string|max:255',
        'mqtt_topic_data' => 'required|string|unique:devices',
        'mqtt_topic_command' => 'nullable|string',
        'metadata' => 'nullable|array',
    ]);

    $device = $request->user()->devices()->create($validated);
    return response()->json($device->load('type'), 201);
}
```

#### 2.10 Update MQTT Consumer (Refactor to Generic)
```bash
php artisan make:command ConsumeDeviceTelemetry
```

```php
// app/Console/Commands/ConsumeDeviceTelemetry.php
namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DeviceReading;
use App\Models\LatestDeviceState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;

class ConsumeDeviceTelemetry extends Command {
    protected $signature = 'mqtt:consume-telemetry {--device=}';
    protected $description = 'Consume telemetry from all registered devices via MQTT';

    public function handle(): int {
        $devices = $this->getDevices();

        if ($devices->isEmpty()) {
            $this->error('No active devices found');
            return self::FAILURE;
        }

        $mqtt = MQTT::connection();
        $subscribeQos = (int) config('mqtt-client.subscribe_qos', 1);

        foreach ($devices as $device) {
            $this->line("Subscribing to: {$device->mqtt_topic_data}");

            $mqtt->subscribe(
                $device->mqtt_topic_data,
                function ($topic, $message) use ($device) {
                    $this->processMessage($device, $topic, $message);
                },
                $subscribeQos
            );
        }

        $this->line('Listening for telemetry...');
        $mqtt->loop(true);

        return self::SUCCESS;
    }

    private function getDevices() {
        if ($this->option('device')) {
            return Device::with('type')
                ->where('id', $this->option('device'))
                ->where('is_active', true)
                ->get();
        }
        return Device::with('type')->where('is_active', true)->get();
    }

    private function processMessage(Device $device, string $topic, string $message): void {
        try {
            // 1. Decode JSON
            $payload = json_decode($message, true);
            if (!$payload) {
                Log::warning('Invalid JSON from device', [
                    'device_id' => $device->id,
                    'topic' => $topic,
                ]);
                return;
            }

            // 2. Validate against device type schema
            $errors = $device->type->validatePayload($payload);
            if (!empty($errors)) {
                Log::warning('Payload validation failed', [
                    'device_id' => $device->id,
                    'errors' => $errors,
                ]);
                return;
            }

            // 3. Extract timestamp
            $ts = $payload['ts'] ?? time();

            // 4. Store in transaction
            DB::transaction(function () use ($device, $payload, $ts) {
                // Check for duplicate
                $exists = DeviceReading::where('device_id', $device->id)
                    ->where('ts', $ts)
                    ->exists();

                if ($exists) {
                    Log::debug('Duplicate reading', ['device_id' => $device->id, 'ts' => $ts]);
                    return;
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

                // Update device metadata
                $device->update([
                    'last_data_at' => now(),
                    'last_seen_at' => now(),
                ]);

                Log::info('Telemetry stored', [
                    'device_id' => $device->id,
                    'reading_id' => $reading->id,
                    'type' => $device->type->code,
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Telemetry processing error', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

Update route:
```php
// routes/console.php
use App\Console\Commands\ConsumeDeviceTelemetry;
Artisan::command('mqtt:consume-telemetry', ConsumeDeviceTelemetry::class);
```

Or run directly:
```bash
php artisan mqtt:consume-telemetry
```

### Deliverables (End of Week 2)
- ✅ Device type framework complete
- ✅ Any device type can be added without code changes
- ✅ Meter readings now in generic `device_readings` table with JSON payloads
- ✅ Dashboard works with any device type dynamically
- ✅ MQTT consumer is generic (ConsumeDeviceTelemetry)
- ✅ Payload validation per device type
- ✅ Tests updated for new schema

---

## WEEK 3: ALERTING SYSTEM

### Goal
Users define alert rules, system evaluates them, sends email notifications. Alerts auto-resolve or snooze.

### Tasks (Detailed in next message)

---

## WEEK 4: BIDIRECTIONAL CONTROL + REPORTING

### Goal  
Users can send commands to devices (AC, switches). Users can export data.

### Tasks (Detailed in next message)

---

## TESTING CHECKLIST

After each week:
```bash
php artisan test
npm run build
php artisan migrate:refresh --seed
```

Verify:
- All 50+ tests passing
- No console errors
- MQTT consumer still working
- Dashboard loads for all device types
- Authorization works (user can't see other user's devices)

---

## DEPLOYMENT READINESS (Week 4 End)

- [ ] Auth system fully tested
- [ ] Multi-user isolation verified
- [ ] Device type framework tested with 2+ types
- [ ] Alert rules working
- [ ] Email notifications sent
- [ ] Commands sent/ACK'd successfully
- [ ] Data export working
- [ ] 200+ tests passing
- [ ] Load tested with 1000+ readings/sec
- [ ] MQTT consumer running under Supervisor
- [ ] Backups automated
- [ ] Rate limiting enabled
- [ ] Security headers added
- [ ] CORS configured
- [ ] Error logging to external service (Sentry)

