@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-mono text-xs uppercase tracking-widest text-iot-muted mb-2']) }}>
    {{ $value ?? $slot }}
</label>
