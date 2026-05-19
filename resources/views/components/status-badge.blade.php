@props(['status', 'label' => null])

@php
$map = [
    'online'      => ['dot' => 'bg-iot-green shadow-[0_0_6px_#10b981]', 'text' => 'text-iot-green'],
    'ok'          => ['dot' => 'bg-iot-green shadow-[0_0_6px_#10b981]', 'text' => 'text-iot-green'],
    'healthy'     => ['dot' => 'bg-iot-green shadow-[0_0_6px_#10b981]', 'text' => 'text-iot-green'],
    'no_issue'    => ['dot' => 'bg-iot-green shadow-[0_0_6px_#10b981]', 'text' => 'text-iot-green'],
    'stale'       => ['dot' => 'bg-iot-amber', 'text' => 'text-iot-amber'],
    'warning'     => ['dot' => 'bg-iot-amber', 'text' => 'text-iot-amber'],
    'silent'      => ['dot' => 'bg-iot-amber', 'text' => 'text-iot-amber'],
    'down'        => ['dot' => 'bg-iot-red', 'text' => 'text-iot-red'],
    'error'       => ['dot' => 'bg-iot-red', 'text' => 'text-iot-red'],
    'offline'     => ['dot' => 'bg-iot-red', 'text' => 'text-iot-red'],
    'issue'       => ['dot' => 'bg-iot-red', 'text' => 'text-iot-red'],
    'never_seen'     => ['dot' => 'bg-iot-muted', 'text' => 'text-iot-muted'],
    'disabled'       => ['dot' => 'bg-iot-muted', 'text' => 'text-iot-muted'],
    'unknown'        => ['dot' => 'bg-iot-muted', 'text' => 'text-iot-muted'],
    'no_issue'       => ['dot' => 'bg-iot-green shadow-[0_0_6px_#10b981]', 'text' => 'text-iot-green'],
    'no issue'       => ['dot' => 'bg-iot-green shadow-[0_0_6px_#10b981]', 'text' => 'text-iot-green'],
    'payload_error'  => ['dot' => 'bg-iot-amber', 'text' => 'text-iot-amber'],
    'payload error'  => ['dot' => 'bg-iot-amber', 'text' => 'text-iot-amber'],
    'recovered'      => ['dot' => 'bg-blue-400', 'text' => 'text-blue-400'],
];
$cfg = $map[$status] ?? ['dot' => 'bg-iot-muted', 'text' => 'text-iot-muted'];
$displayLabel = $label ?? ucfirst(str_replace('_', ' ', $status));
@endphp

<span class="inline-flex items-center gap-1.5 text-xs font-medium {{ $cfg['text'] }}">
    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0 {{ $cfg['dot'] }}"></span>
    {{ $displayLabel }}
</span>
