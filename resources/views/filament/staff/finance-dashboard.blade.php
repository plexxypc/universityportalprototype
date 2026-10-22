<div class="space-y-4" wire:init="loadDashboard">
    <label class="text-body text-text" for="session_id">Session</label>
    <select id="session_id" class="text-body" wire:model.live="session_id" wire:change="loadDashboard">
        @foreach ($this->sessionOptions() as $session)
            <option value="{{ $session['id'] }}">{{ $session['name'] }}</option>
        @endforeach
    </select>
    <p class="text-body text-text">{{ \App\Services\FinanceReport::PENDING_NOTE }}</p>
    @if ($this->state === 'loading')
        <p class="text-body text-text">Loading</p>
    @elseif ($this->state === 'error')
        <p class="text-body text-text">{{ \App\Services\FinanceReport::ERROR }}</p>
    @elseif ($this->figures === [])
        <p class="text-body text-text">{{ \App\Services\FinanceReport::EMPTY }}</p>
    @else
        <p class="text-body text-text">Collected {{ $this->figures['collected'] }}</p>
        <p class="text-body text-text">Outstanding {{ $this->figures['outstanding'] }}</p>
        <p class="text-body text-text">Collection rate {{ $this->figures['rate'] }}</p>
        <p class="text-body"><a class="text-body text-text" href="{{ \App\Filament\Staff\Pages\ReconciliationPage::getUrl() }}">Needs review {{ $this->figures['review_count'] }}</a></p>
        <h2 class="text-body text-text">Recent transactions</h2>
        @forelse ($this->figures['recent'] as $row)
            <p class="text-body text-text">{{ $row['reference'] }} {{ $row['detail'] }}</p>
        @empty
            <p class="text-body text-text">{{ \App\Services\FinanceReport::EMPTY }}</p>
        @endforelse
        <h2 class="text-body text-text">By programme and level</h2>
        @foreach ($this->figures['breakdown'] as $row)
            <p class="text-body text-text">{{ $row['programme'] }} {{ $row['level'] }} {{ $row['collected'] }} {{ $row['outstanding'] }}</p>
        @endforeach
    @endif
</div>
