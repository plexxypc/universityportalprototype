<div id="student-documents">
    <x-page-header title="Documents" description="Upload a PDF, JPEG, or PNG up to 5 MB." />

    <x-card title="Upload">
        <form wire:submit="save" class="flex flex-col gap-space-16">
            <x-field name="kind" label="Type" :error="$errors->first('kind')">
                <x-select name="kind" wire:model="kind">
                    <option value="">Choose a type</option>
                    @foreach ($kinds as $kind)
                        <option value="{{ $kind->value }}">{{ $kind->value }}</option>
                    @endforeach
                </x-select>
            </x-field>
            <x-field name="document" label="File" :error="$errors->first('document')">
                <input
                    id="field-document"
                    name="document"
                    type="file"
                    wire:model="upload"
                    class="block w-full text-body text-text"
                />
            </x-field>
            <div>
                <x-button type="submit">Upload</x-button>
            </div>
        </form>
    </x-card>

    <div class="mt-space-16">
        <x-card title="Your files">
            @if ($documents->isEmpty())
                <x-empty-state message="You have not uploaded a document yet." />
            @else
                <ul class="flex flex-col gap-space-12">
                    @foreach ($documents as $document)
                        <li class="flex flex-wrap items-center justify-between gap-space-8">
                            <span class="text-body text-text">{{ $document->kind }} · {{ $document->mime }}</span>
                            <a class="text-body font-semibold text-primary-700 underline" href="{{ route('student.documents.download', $document) }}">Download</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>
</div>
