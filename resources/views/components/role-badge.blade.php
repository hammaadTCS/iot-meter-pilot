@props(['role'])

@php
$map = [
    'super_admin' => ['label' => 'SA', 'classes' => 'bg-iot-accent/15 text-iot-accent border-iot-accent/30'],
    'admin'       => ['label' => 'A',  'classes' => 'bg-iot-accent2/15 text-iot-accent2 border-iot-accent2/30'],
    'user'        => ['label' => 'U',  'classes' => 'bg-slate-500/15 text-slate-400 border-slate-500/30'],
];
$cfg = $map[$role] ?? $map['user'];
@endphp

<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono font-bold border {{ $cfg['classes'] }}">
    {{ $cfg['label'] }}
</span>
