@if (($column['format'] ?? 'text') === 'money')
    <x-money :kobo="$row[$column['key']]" />
@elseif (($column['format'] ?? 'text') === 'badge')
    <x-status-badge :status="$row[$column['key']]" />
@elseif (($column['format'] ?? 'text') === 'view')
    <a href="{{ $row['view_url'] ?? '#' }}" class="inline-flex min-h-11 items-center text-body font-semibold text-primary-600 underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">View</a>
@else
    {{ $row[$column['key']] ?? '' }}
@endif
