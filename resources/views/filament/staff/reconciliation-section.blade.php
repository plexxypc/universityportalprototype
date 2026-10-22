@if ($state === 'loading')
    <p class="text-body text-text">Loading</p>
@elseif ($state === 'error')
    <p class="text-body text-text">{{ \App\Services\FinanceReport::ERROR }}</p>
@elseif ($state === 'empty')
    <p class="text-body text-text">{{ \App\Services\FinanceReport::EMPTY }}</p>
@else
    <p class="text-body text-text">{{ $count }}</p>
    @foreach ($rows as $row)
        <p class="text-body text-text">{{ $row['reference'] ?? $row['row_id'] ?? '' }} {{ $row['detail'] ?? $row['kind'] ?? '' }}</p>
    @endforeach
@endif
