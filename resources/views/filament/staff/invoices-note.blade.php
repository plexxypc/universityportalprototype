<p class="mb-4">A later price change does not change an invoice that was already issued.</p>
<p class="mb-4">Payments under review: {{ app(\App\Services\PaymentService::class)->openReviewCount() }}</p>
