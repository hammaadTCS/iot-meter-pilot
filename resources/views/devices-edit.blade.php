<x-app-layout>
    <x-page-header title="Edit Device" :subtitle="'Updating: ' . $device->name" />

    <div class="max-w-2xl">
        <div class="bg-iot-surface border border-iot-border rounded-2xl p-6 sm:p-8">
            <form action="{{ route('devices.update', $device) }}" method="POST" class="space-y-5">
                @csrf
                @method('PATCH')

                {{-- Admin: reassign --}}
                @if(auth()->user()->isAdminOrAbove())
                    <div>
                        <label for="user_id" class="block font-mono text-xs uppercase tracking-widest text-iot-muted mb-2">
                            Assigned To User <span class="text-iot-red">*</span>
                        </label>
                        <select id="user_id" name="user_id"
                            class="w-full px-4 py-2.5 bg-iot-surface2 border border-iot-border rounded-xl text-iot-text text-sm
                                   focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50
                                   @error('user_id') border-iot-red @enderror"
                            required>
                            <option value="">— Select User —</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}" {{ old('user_id', $device->user_id) == $u->id ? 'selected' : '' }}>
                                    {{ $u->name }} ({{ $u->email }}) — {{ $u->role }}
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
                    <input type="text" id="name" name="name" value="{{ old('name', $device->name) }}"
                        class="w-full px-4 py-2.5 bg-iot-surface2 border border-iot-border rounded-xl text-iot-text text-sm
                               placeholder:text-iot-muted focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50
                               @error('name') border-iot-red @enderror"
                        required>
                    @error('name')
                        <p class="text-iot-red text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Code --}}
                <div>
                    <label for="code" class="block font-mono text-xs uppercase tracking-widest text-iot-muted mb-2">
                        Device Code <span class="text-iot-red">*</span>
                    </label>
                    <input type="text" id="code" name="code" value="{{ old('code', $device->code) }}"
                        class="w-full px-4 py-2.5 bg-iot-surface2 border border-iot-border rounded-xl text-iot-text text-sm font-mono
                               placeholder:text-iot-muted focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50
                               @error('code') border-iot-red @enderror"
                        required>
                    @error('code')
                        <p class="text-iot-red text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Type --}}
                <div>
                    <label for="type" class="block font-mono text-xs uppercase tracking-widest text-iot-muted mb-2">
                        Device Type <span class="text-iot-red">*</span>
                    </label>
                    <select id="type" name="type"
                        class="w-full px-4 py-2.5 bg-iot-surface2 border border-iot-border rounded-xl text-iot-text text-sm
                               focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50
                               @error('type') border-iot-red @enderror"
                        required>
                        <option value="">— Select Type —</option>
                        <option value="meter"      {{ old('type', $device->type) === 'meter'      ? 'selected' : '' }}>⚡ Meter</option>
                        <option value="sensor"     {{ old('type', $device->type) === 'sensor'     ? 'selected' : '' }}>📡 Sensor</option>
                        <option value="smart_plug" {{ old('type', $device->type) === 'smart_plug' ? 'selected' : '' }}>🔌 Smart Plug</option>
                        <option value="camera"     {{ old('type', $device->type) === 'camera'     ? 'selected' : '' }}>📷 Camera</option>
                        <option value="thermostat" {{ old('type', $device->type) === 'thermostat' ? 'selected' : '' }}>🌡️ Thermostat</option>
                        <option value="lock"       {{ old('type', $device->type) === 'lock'       ? 'selected' : '' }}>🔒 Lock</option>
                    </select>
                    @error('type')
                        <p class="text-iot-red text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- MQTT Topic --}}
                <div>
                    <label for="mqtt_topic" class="block font-mono text-xs uppercase tracking-widest text-iot-muted mb-2">
                        MQTT Topic <span class="text-iot-red">*</span>
                    </label>
                    <input type="text" id="mqtt_topic" name="mqtt_topic" value="{{ old('mqtt_topic', $device->mqtt_topic) }}"
                        class="w-full px-4 py-2.5 bg-iot-surface2 border border-iot-border rounded-xl text-iot-text text-sm font-mono
                               placeholder:text-iot-muted focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50
                               @error('mqtt_topic') border-iot-red @enderror"
                        required>
                    @error('mqtt_topic')
                        <p class="text-iot-red text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Availability Topic --}}
                <div>
                    <label for="availability_topic" class="block font-mono text-xs uppercase tracking-widest text-iot-muted mb-2">
                        Availability Topic <span class="text-iot-muted font-normal normal-case tracking-normal">(optional)</span>
                    </label>
                    <input type="text" id="availability_topic" name="availability_topic"
                        value="{{ old('availability_topic', $device->availability_topic) }}"
                        class="w-full px-4 py-2.5 bg-iot-surface2 border border-iot-border rounded-xl text-iot-text text-sm font-mono
                               placeholder:text-iot-muted focus:outline-none focus:border-iot-accent focus:ring-1 focus:ring-iot-accent/50"
                        placeholder="e.g., meters/shop-201/availability">
                </div>

                {{-- Active Status --}}
                <div class="flex items-center gap-3 py-1">
                    <div x-data="{ active: {{ old('is_active', $device->is_active) ? 'true' : 'false' }} }">
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
                        Update Device
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
