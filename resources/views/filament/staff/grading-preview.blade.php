@php
    $lines = $this->preview_lines ?? null;
@endphp

@if (is_array($lines))
    <div class="mb-6">
        <h2>Preview</h2>
        <ul>
            @foreach ($lines['courses'] as $course)
                <li>
                    Total {{ $course['total'] }},
                    {{ $course['letter'] }},
                    {{ $course['points'] }} points,
                    {{ $course['passed'] ? 'Pass' : 'Fail' }}
                </li>
            @endforeach
        </ul>
        @if (is_string($lines['gpa']))
            <p>GPA {{ $lines['gpa'] }}@if (is_string($lines['classification']))
                , {{ $lines['classification'] }}
            @endif</p>
        @endif
    </div>
@endif
