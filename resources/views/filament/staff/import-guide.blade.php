<x-filament-panels::page>
    <p class="mb-4 max-w-xl text-body text-text">
        Create a Google Form with these questions, in this order. Link the form to a sheet, share that sheet as anyone with the link can view, then import the sheet on the import page. The mapping step matches the columns, so the header wording can differ.
    </p>
    <p class="mb-4 max-w-xl text-body text-text">
        Matric number and entry session are import columns. Leave matric number blank to generate one. A blank entry session uses the current session. This form does not ask for a password.
    </p>
    <ol class="mb-6 max-w-xl list-decimal space-y-space-8 ps-space-24 text-body text-text">
        @foreach ($this->questions() as $question)
            <li wire:key="import-question-{{ $loop->index }}">{{ $question }}</li>
        @endforeach
    </ol>
    <div class="flex max-w-full flex-wrap gap-space-12">
        <x-button href="{{ route('staff.downloads.import-template-csv') }}" variant="secondary">Download CSV template</x-button>
        <x-button href="{{ route('staff.downloads.import-template-xlsx') }}" variant="secondary">Download Excel template</x-button>
        <x-button href="{{ \App\Filament\Staff\Pages\ImportStudentsPage::getUrl() }}" variant="primary">Back to import</x-button>
    </div>
</x-filament-panels::page>
