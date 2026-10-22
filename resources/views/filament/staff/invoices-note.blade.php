<p class="mb-4">A later price change does not change an invoice that was already issued.</p>
<p class="mb-4">Payments under review: {{ app(\App\Services\PaymentService::class)->openReviewCount() }}</p>
<p class="mb-4"><a class="text-body text-text" href="{{ \App\Filament\Staff\Pages\ReconciliationPage::getUrl() }}">Open reconciliation</a></p>
