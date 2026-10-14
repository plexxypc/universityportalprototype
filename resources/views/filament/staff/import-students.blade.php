<x-filament-panels::page>
    @php
        $steps = [
            'source' => '1. Source',
            'preview' => '2. Preview',
            'map' => '3. Map',
            'validate' => '4. Validate',
            'import' => '5. Import',
            'report' => '6. Report',
        ];
    @endphp
    <ol class="mb-6 flex flex-wrap gap-2 text-sm">
        @foreach ($steps as $name => $label)
            <li class="rounded-full px-3 py-1 {{ $step === $name ? 'bg-primary-600 text-white' : '' }}">{{ $label }}</li>
        @endforeach
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
    @elseif ($step === 'preview')
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
        <form wire:submit="openMap" class="mt-6">
            <x-filament::button type="submit">
                Map columns
            </x-filament::button>
        </form>
    @elseif ($step === 'map')
        @php
            $preview = $this->previewTable();
        @endphp
        <form wire:submit="confirmMap" class="flex max-w-xl flex-col gap-4">
            @foreach ($this->mappingFields() as $field)
                <div wire:key="map-{{ $field['key'] }}">
                    <label for="map-{{ $field['key'] }}" class="mb-1 block font-medium">
                        {{ $field['label'] }}@if ($field['required']) * @endif
                    </label>
                    <select id="map-{{ $field['key'] }}" wire:model="column_map.{{ $field['key'] }}" class="block w-full rounded-lg border px-3 py-2">
                        <option value="">Not in this file</option>
                        @foreach ($preview['headers'] as $index => $header)
                            <option value="{{ $index }}">{{ $header !== '' ? $header : 'Column '.($index + 1) }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
            @error('column_map')
                <p class="text-sm text-danger-600">{{ $message }}</p>
            @enderror
            <x-filament::button type="submit">
                Save mapping
            </x-filament::button>
        </form>
    @elseif ($step === 'validate')
        @php
            $report = $this->validationReport();
        @endphp
        <div class="mb-6 flex max-w-xl flex-col gap-4">
            <div>
                <label for="student-import-duplicates" class="mb-1 block font-medium">Existing students</label>
                <select id="student-import-duplicates" wire:model.live="duplicate_mode" class="block w-full rounded-lg border px-3 py-2">
                    <option value="skip">Skip existing students</option>
                    <option value="update">Update existing students</option>
                </select>
            </div>
            <div>
                <label for="student-import-send" class="mb-1 block font-medium">Credentials emails</label>
                <select id="student-import-send" wire:model="send_mode" class="block w-full rounded-lg border px-3 py-2">
                    <option value="now">Send credentials now</option>
                    <option value="later">Send credentials later</option>
                </select>
            </div>
        </div>
        <p class="mb-2">{{ $report['ready_count'] }} of {{ $report['total_rows'] }} rows can be imported. {{ $report['error_count'] }} errors.</p>
        @if ($report['remaining_error_count'] > 0)
            <p class="mb-4 text-sm">Showing the first 50 errors. {{ $report['remaining_error_count'] }} more are not listed.</p>
        @endif
        @if ($report['errors'] !== [])
            <div class="mb-6 max-w-full overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th class="border-b px-2 py-2 font-medium">Row</th>
                            <th class="border-b px-2 py-2 font-medium">Field</th>
                            <th class="border-b px-2 py-2 font-medium">Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['errors'] as $error)
                            <tr wire:key="import-error-{{ $loop->index }}">
                                <td class="border-b px-2 py-2">{{ $error['row'] > 0 ? $error['row'] : 'File' }}</td>
                                <td class="border-b px-2 py-2">{{ $error['field'] }}</td>
                                <td class="border-b px-2 py-2">{{ $error['message'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p class="mb-2">{{ $report['duplicate_count'] }} rows match an existing student.</p>
        @if ($report['duplicates'] !== [])
            <div class="max-w-full overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th class="border-b px-2 py-2 font-medium">Row</th>
                            <th class="border-b px-2 py-2 font-medium">Field</th>
                            <th class="border-b px-2 py-2 font-medium">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['duplicates'] as $duplicate)
                            <tr wire:key="import-duplicate-{{ $loop->index }}">
                                <td class="border-b px-2 py-2">{{ $duplicate['row'] }}</td>
                                <td class="border-b px-2 py-2">{{ $duplicate['field'] }}</td>
                                <td class="border-b px-2 py-2">{{ $duplicate['message'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <form wire:submit="commitChunk" class="mt-6">
            <x-filament::button type="submit">
                Import students
            </x-filament::button>
        </form>
    @elseif ($step === 'import')
        @php
            $progress = $this->importProgress();
        @endphp
        <p class="mb-2">{{ $progress['processed_rows'] }} of {{ $progress['total_rows'] }} rows processed.</p>
        <progress class="mb-4 w-full" value="{{ $progress['processed_rows'] }}" max="{{ max(1, $progress['total_rows']) }}"></progress>
        <p class="mb-4 text-sm">Created {{ $progress['created_count'] }}, updated {{ $progress['updated_count'] }}, skipped {{ $progress['skipped_count'] }}, failed {{ $progress['failed_count'] }}.</p>
        <form wire:submit="commitChunk">
            <x-filament::button type="submit">
                Continue import
            </x-filament::button>
        </form>
    @elseif ($step === 'report')
        @php
            $committed = $this->commitReport();
        @endphp
        <p class="mb-2">Created {{ $committed['created_count'] }}, updated {{ $committed['updated_count'] }}, skipped {{ $committed['skipped_count'] }}, failed {{ $committed['failed_count'] }}.</p>
        <p class="mb-4">{{ $committed['credentials_needed'] }} accounts still need credentials.</p>
        @if ($committed['students_url'] !== '')
            <a href="{{ $committed['students_url'] }}">View accounts that still need credentials</a>
        @endif
    @endif
</x-filament-panels::page>
