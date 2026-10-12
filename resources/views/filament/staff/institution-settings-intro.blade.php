<div class="mb-6 flex flex-col gap-4">
    <p>{{ \App\Support\MatricPattern::notice() }}</p>
    <img
        src="{{ app(\App\Services\SettingsService::class)->brand()['logo_url'] }}"
        alt=""
        width="48"
        height="48"
        class="size-12"
    >
</div>
