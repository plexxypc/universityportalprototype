@props([
    'steps',
    'current',
])

@php
    $keys = array_column($steps, 'key');
    $current_index = array_search($current, $keys, true);
    $current_index = is_int($current_index) ? $current_index : -1;
@endphp

<ol aria-label="Import steps" {{ $attributes->merge(['class' => 'mb-6 flex max-w-full flex-wrap gap-space-8']) }}>
    @foreach ($steps as $index => $step)
        @php
            $state = $index === $current_index ? 'current' : ($index < $current_index ? 'complete' : 'upcoming');
            $classes = match ($state) {
                'current' => 'bg-primary-600 text-white',
                'complete' => 'bg-primary-50 text-primary-700',
                default => 'bg-surface text-muted',
            };
        @endphp
        <li
            class="inline-flex min-h-11 items-center gap-space-8 rounded-full border border-border px-space-12 py-space-4 text-small font-semibold {{ $classes }}"
            @if ($state === 'current') aria-current="step" @endif
        >
            <span aria-hidden="true">{{ $index + 1 }}</span>
            <span>{{ $step['label'] }}</span>
        </li>
    @endforeach
</ol>
