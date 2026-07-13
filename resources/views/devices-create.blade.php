<x-app-layout>
    <x-page-header title="Add Device"
        :subtitle="($selfProvisionOnly ?? false)
            ? 'Register your own meter. It will be linked to your account.'
            : 'Register a new IoT device to your smart home.'" />

    <div class="max-w-2xl">
        <div class="bg-iot-surface border border-iot-border rounded-2xl p-6 sm:p-8">
            <form action="{{ route('devices.store') }}" method="POST" class="space-y-5">
                @csrf

                {{-- Owner assignment — requires devices.assign_owner --}}
                @if(auth()->user()->can('devices.assign_owner') && ! ($selfProvisionOnly ?? false))
                    <div>
                        <label for="user_id" class="block font-mono text-xs uppercase tracking-widest text-iot-muted mb-2">
                            Assign To User <span class="text-iot-red">*</span>
                        </label>
                        <select id="user_id" name="user_id"
                            class="w-full px-4 py-2.5 bg-iot-surface2 border border-iot-border rounded-xl text-iot-text text-sm
                                   focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50
                                   @error('user_id') border-iot-red @enderror"
                            required>
                            <option value="">— Select User —</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}" {{ old('user_id') == $u->id ? 'selected' : '' }}>
                                    {{ $u->name }} ({{ $u->email }})
                                </option>
                            @endforeach
                        </select>
                        @error('user_id')
                            <p class="text-iot-red text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                {{-- Device Name --}}
                <div>
                    <label for="name" class="block font-mono text-xs uppercase tracking-widest text-iot-muted mb-2">
                        Device Name <span class="text-iot-red">*</span>
                    </label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}"
                        class="w-full px-4 py-2.5 bg-iot-surface2 border border-iot-border rounded-xl text-iot-text text-sm
                               placeholder:text-iot-muted focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50
                               @error('name') border-iot-red @enderror"
                        placeholder="e.g., Shop 201 Meter" required>
                    @error('name')
                        <p class="text-iot-red text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Device Code --}}
                <div>
                    <label for="code" class="block font-mono text-xs uppercase tracking-widest text-iot-muted mb-2">
                        Device Code <span class="text-iot-red">*</span>
                    </label>
                    <input type="text" id="code" name="code" value="{{ old('code') }}"
                        class="w-full px-4 py-2.5 bg-iot-surface2 border border-iot-border rounded-xl text-iot-text text-sm font-mono
                               placeholder:text-iot-muted focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50
                               @error('code') border-iot-red @enderror"
                        placeholder="e.g., meter-shop-201" required>
                    <p class="text-iot-muted text-xs mt-1">Must be unique per user account</p>
                    @error('code')
                        <p class="text-iot-red text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Device Type --}}
                <div>
                    <label for="type" class="block font-mono text-xs uppercase tracking-widest text-iot-muted mb-2">
                        Device Type <span class="text-iot-red">*</span>
                    </label>
                    @if($selfProvisionOnly ?? false)
                        {{-- Self-provision (meter.self_provision without devices.create):
                             type is locked to Meter — the server rejects anything else. --}}
                        <input type="hidden" name="type" value="meter">
                        <div class="w-full px-4 py-2.5 bg-iot-surface2/50 border border-iot-border rounded-xl text-iot-muted text-sm">
                            ⚡ Meter <span class="text-[10px] uppercase tracking-widest ml-2">(locked)</span>
                        </div>
                    @else
                    <select id="type" name="type"
                        class="w-full px-4 py-2.5 bg-iot-surface2 border border-iot-border rounded-xl text-iot-text text-sm
                               focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50
                               @error('type') border-iot-red @enderror"
                        required>
                        <option value="">— Select Type —</option>
                        <option value="meter"      {{ old('type') === 'meter'      ? 'selected' : '' }}>⚡ Meter</option>
                        <option value="sensor"     {{ old('type') === 'sensor'     ? 'selected' : '' }}>📡 Sensor</option>
                        <option value="smart_plug" {{ old('type') === 'smart_plug' ? 'selected' : '' }}>🔌 Smart Plug</option>
                        <option value="camera"     {{ old('type') === 'camera'     ? 'selected' : '' }}>📷 Camera</option>
                        <option value="thermostat" {{ old('type') === 'thermostat' ? 'selected' : '' }}>🌡️ Thermostat</option>
                        <option value="lock"       {{ old('type') === 'lock'       ? 'selected' : '' }}>🔒 Lock</option>
                    </select>
                    @endif
                    @error('type')
                        <p class="text-iot-red text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- MQTT Topic --}}
                <div>
                    <label for="mqtt_topic" class="block font-mono text-xs uppercase tracking-widest text-iot-muted mb-2">
                        MQTT Topic <span class="text-iot-red">*</span>
                    </label>
                    <input type="text" id="mqtt_topic" name="mqtt_topic" value="{{ old('mqtt_topic') }}"
                        class="w-full px-4 py-2.5 bg-iot-surface2 border border-iot-border rounded-xl text-iot-text text-sm font-mono
                               placeholder:text-iot-muted focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50
                               @error('mqtt_topic') border-iot-red @enderror"
                        placeholder="e.g., meters/shop-201" required>
                    @error('mqtt_topic')
                        <p class="text-iot-red text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Availability Topic --}}
                <div>
                    <label for="availability_topic" class="block font-mono text-xs uppercase tracking-widest text-iot-muted mb-2">
                        Availability Topic <span class="text-iot-muted font-normal normal-case tracking-normal">(optional)</span>
                    </label>
                    <input type="text" id="availability_topic" name="availability_topic" value="{{ old('availability_topic') }}"
                        class="w-full px-4 py-2.5 bg-iot-surface2 border border-iot-border rounded-xl text-iot-text text-sm font-mono
                               placeholder:text-iot-muted focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50"
                        placeholder="e.g., meters/shop-201/availability">
                    <p class="text-iot-muted text-xs mt-1">MQTT heartbeat/LWT topic for online/offline detection</p>
                </div>

                {{-- Active Status --}}
                <div class="flex items-center gap-3 py-1">
                    <div x-data="{ active: {{ old('is_active', true) ? 'true' : 'false' }} }">
                        <input type="hidden" name="is_active" :value="active ? '1' : '0'">
                        <button type="button"
                                @click="active = !active"
                                :class="active ? 'bg-iot-accent' : 'bg-iot-surface2 border border-iot-border'"
                                class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-iot-accent/50">
                            <span :class="active ? 'translate-x-6' : 'translate-x-1'"
                                  class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"></span>
                        </button>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-iot-text">Active</label>
                        <p class="text-xs text-iot-muted">Consumer will subscribe to MQTT topic immediately</p>
                    </div>
                </div>

                {{-- Form actions --}}
                <div class="flex gap-3 pt-2 border-t border-iot-border">
                    <button type="submit"
                            class="flex-1 sm:flex-none px-6 py-2.5 rounded-xl text-sm font-medium
                                   bg-iot-accent text-iot-bg hover:bg-iot-accent/90 transition-colors">
                        Create Device
                    </button>
                    <a href="{{ route('devices.manage') }}"
                       class="flex-1 sm:flex-none px-6 py-2.5 rounded-xl text-sm font-medium text-center
                              bg-iot-surface2 text-iot-muted border border-iot-border
                              hover:text-white hover:bg-iot-border transition-colors">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
