@php($summary = $this->financeSummary())
<p class="text-body text-text">Registration {{ $summary['registration_status'] }}</p>
@if ($summary['show_finance'])
    <p class="text-body text-text">Outstanding {{ $summary['outstanding'] }}</p>
    <p class="text-body text-text">Last payment {{ $summary['last_payment_status'] }}</p>
    @if ($summary['transaction_id'] > 0)
        <p class="text-body"><a class="text-body text-text" href="{{ \App\Filament\Staff\Pages\TransactionPage::getUrl(['payment' => $summary['transaction_id']]) }}">Open transaction</a></p>
    @endif
@endif
