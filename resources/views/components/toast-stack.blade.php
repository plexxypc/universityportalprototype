<div
    x-data="{
        toasts: [],
        push(detail) {
            const id = Date.now();
            const message = detail && detail.message ? String(detail.message) : '';
            this.toasts.push({ id: id, message: message });
            setTimeout(() => this.dismiss(id), 5000);
        },
        dismiss(id) {
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        }
    }"
    x-on:toast.window="push($event.detail)"
    aria-live="polite"
    class="pointer-events-none fixed inset-x-0 top-0 z-50 flex w-full flex-col gap-space-8 p-space-16 sm:inset-x-auto sm:end-0 sm:w-96"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div role="status" class="pointer-events-auto flex w-full min-w-0 items-start justify-between gap-space-12 rounded-card border border-border bg-surface p-space-16 shadow-lg">
            <p class="text-body font-normal text-text" x-text="toast.message"></p>
            <button type="button" class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control text-body font-semibold text-text hover:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2" x-on:click="dismiss(toast.id)">
                Dismiss
            </button>
        </div>
    </template>
</div>
