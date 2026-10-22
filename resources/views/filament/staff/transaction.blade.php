<div class="space-y-4">
    @php($view = $this->transactionView())
    <p class="text-body text-text">{{ $view['student'] }}</p>
    <p class="text-body text-text">Reference {{ $view['reference'] }}</p>
    <p class="text-body text-text">Amount {{ $view['amount'] }}</p>
    <p class="text-body"><span class="{{ $view['tone'] }}">{{ $view['status'] }}</span></p>
    <p class="text-body text-text">Provider {{ $view['gateway'] }}</p>
    @if ($view['provider_reference'] !== '')
        <p class="text-body text-text">Provider reference {{ $view['provider_reference'] }}</p>
    @endif
    <p class="text-body text-text">Created {{ $view['created_at'] }}</p>
    @if ($view['paid_at'] !== '')
        <p class="text-body text-text">Paid {{ $view['paid_at'] }}</p>
    @endif
    <p class="text-body"><a class="text-body text-text" href="{{ $view['invoice_url'] }}">Invoice {{ $view['invoice_number'] }}</a></p>
    @if ($view['receipt_url'] !== '')
        <p class="text-body"><a class="text-body text-text" href="{{ $view['receipt_url'] }}">View receipt</a></p>
        <p class="text-body"><a class="text-body text-text" href="{{ $view['receipt_pdf_url'] }}">Download receipt PDF</a></p>
    @endif

    @if ($view['can_manage'])
        <button type="button" class="text-body" wire:click="reverifyPayment" wire:loading.attr="disabled" wire:target="reverifyPayment">Re-verify</button>
    @endif

    @if ($view['can_reverse'])
        <p class="text-body text-text">{{ $view['reversal_notice'] }}</p>
        <label class="text-body text-text" for="reversal_reason">Reason</label>
        <input id="reversal_reason" class="text-body" type="text" maxlength="500" wire:model="reversal_reason" />
        <button type="button" class="text-body" wire:click="reversePayment" wire:confirm="Reverse this payment in the ledger?" wire:loading.attr="disabled" wire:target="reversePayment">Reverse</button>
    @endif

    <h2 class="text-body text-text">Events</h2>
    @forelse ($view['events'] as $event)
        <p class="text-body text-text">{{ $event['at'] }} {{ $event['source'] }} {{ $event['result'] }} {{ $event['reference'] }} {{ $event['amount'] }} {{ $event['currency'] }}</p>
    @empty
        <p class="text-body text-text">No events yet</p>
    @endforelse
</div>
