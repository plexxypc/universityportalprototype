<x-filament-panels::page>
    <ol class="mb-6 flex flex-wrap gap-2 text-sm">
        <li class="rounded-full px-3 py-1 {{ $step === 'source' ? 'bg-primary-600 text-white' : '' }}">1. Source</li>
        <li class="rounded-full px-3 py-1 {{ $step === 'preview' ? 'bg-primary-600 text-white' : '' }}">2. Preview</li>
        <li class="rounded-full px-3 py-1">3. Map</li>
        <li class="rounded-full px-3 py-1">4. Validate</li>
        <li class="rounded-full px-3 py-1">5. Import</li>
        <li class="rounded-full px-3 py-1">6. Report</li>
    </ol>

    @if ($step === 'source')
        <form wire:submit="stageUpload" class="mb-8 flex max-w-xl flex-col gap-4">
            <div>
                <label for="student-import-file" class="mb-1 block font-medium">CSV or Excel file</label>
                <input id="student-import-file" type="file" wire:model="upload" accept=".csv,.xlsx" class="block w-full">
                @error('upload')
                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>
            <x-filament::button type="submit">
                Preview file
            </x-filament::button>
        </form>

        <form wire:submit="stageSheet" class="flex max-w-xl flex-col gap-4">
            <div>
                <label for="student-import-sheet" class="mb-1 block font-medium">Google Sheets link</label>
                <input id="student-import-sheet" type="url" wire:model="sheet_url" class="block w-full rounded-lg border px-3 py-2" placeholder="https://docs.google.com/spreadsheets/d/…">
                <p class="mt-1 text-sm">The sheet must be shared as anyone with the link can view.</p>
                @error('sheet_url')
                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>
            <x-filament::button type="submit" color="gray">
                Preview sheet
            </x-filament::button>
        </form>
    @else
        @php
            $preview = $this->previewTable();
        @endphp
        <div class="mb-4">
            <p class="font-medium">{{ $preview['original_name'] }}</p>
            <p class="text-sm">Showing {{ count($preview['rows']) }} of {{ $preview['total_rows'] }} rows.</p>
        </div>
        @if ($preview['headers'] === [])
            <x-empty-state message="This file has headers only." />
        @else
            <div class="max-w-full overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr>
                            @foreach ($preview['headers'] as $header)
                                <th class="border-b px-2 py-2 font-medium" wire:key="header-{{ $loop->index }}">{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preview['rows'] as $row)
                            <tr wire:key="preview-row-{{ $loop->index }}">
                                @foreach ($preview['headers'] as $index => $header)
                                    <td class="border-b px-2 py-2">{{ $row[$index] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-filament-panels::page>
