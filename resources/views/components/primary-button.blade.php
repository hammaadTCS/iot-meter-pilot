<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-5 py-2.5 bg-iot-accent text-iot-bg rounded-xl font-semibold text-sm hover:bg-iot-accent/90 focus:outline-none focus:ring-2 focus:ring-iot-accent/50 transition-colors']) }}>
    {{ $slot }}
</button>
