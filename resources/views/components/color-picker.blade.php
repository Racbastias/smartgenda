@props(['name' => 'color', 'value' => null, 'autosubmit' => false])
@php
    $palette = ['#1D4ED8', '#0891B2', '#059669', '#65A30D', '#D97706', '#EA580C', '#DC2626', '#DB2777', '#7C3AED', '#475569'];
    $value = strtoupper($value ?? $palette[0]);
    if (! in_array($value, $palette, true)) { $palette[] = $value; }
@endphp
<div class="swatches" role="radiogroup" aria-label="Color">
    @foreach ($palette as $color)
        <label class="swatch" style="--cal-color: {{ $color }}" title="{{ $color }}">
            <input type="radio" name="{{ $name }}" value="{{ $color }}" @checked($color === $value) @if ($autosubmit) onchange="this.form.requestSubmit()" @endif>
            <span class="sr-only">{{ $color }}</span>
        </label>
    @endforeach
</div>
