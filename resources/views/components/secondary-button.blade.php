<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-5 py-2.5 bg-iot-surface2 border border-iot-border rounded-xl font-semibold text-sm text-iot-muted hover:text-white hover:bg-iot-border focus:outline-none focus:ring-2 focus:ring-iot-accent/30 transition-colors']) }}>
    {{ $slot }}
</button>
