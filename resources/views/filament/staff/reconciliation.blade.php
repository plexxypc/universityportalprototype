<div class="space-y-6" wire:init="loadSections">
    <p class="mb-4"><a class="text-body text-text" href="{{ route('staff.downloads.finance.reconciliation', ['section' => 'stuck']) }}">Download CSV</a></p>

    <section>
        <h2 class="text-body text-text">Stuck pending</h2>
        @include('filament.staff.reconciliation-section', ['state' => $this->stuck_state, 'rows' => $this->stuck_rows, 'count' => $this->stuck_count])
    </section>

    <section>
        <h2 class="text-body text-text">Needs review</h2>
        @include('filament.staff.reconciliation-section', ['state' => $this->review_state, 'rows' => $this->review_rows, 'count' => $this->review_count])
        @if ($this->feesManage())
            <label class="text-body text-text" for="resolve_reference">Payment reference</label>
            <input id="resolve_reference" class="text-body" type="text" wire:model="resolve_reference" />
            <label class="text-body text-text" for="resolve_reason">Reason</label>
            <input id="resolve_reason" class="text-body" type="text" maxlength="500" wire:model="resolve_reason" />
            <button type="button" class="text-body" wire:click="markResolved" wire:loading.attr="disabled" wire:target="markResolved">Mark resolved</button>
        @endif
    </section>

    <section>
        <h2 class="text-body text-text">Last answer disagrees</h2>
        @include('filament.staff.reconciliation-section', ['state' => $this->disagreement_state, 'rows' => $this->disagreement_rows, 'count' => $this->disagreement_count])
    </section>

    <section>
        <h2 class="text-body text-text">Ledger integrity</h2>
        @include('filament.staff.reconciliation-section', ['state' => $this->integrity_state, 'rows' => $this->integrity_rows, 'count' => $this->integrity_count])
        @if ($this->integrity_count > 25)
            <button type="button" class="text-body" wire:click="integrityPage({{ $this->integrity_page + 1 }})">Next</button>
        @endif
    </section>
</div>
