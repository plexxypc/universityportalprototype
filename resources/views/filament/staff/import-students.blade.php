<x-filament-panels::page>
    <x-stepper
        :current="$step"
        :steps="[
            ['key' => 'source', 'label' => 'Source'],
            ['key' => 'preview', 'label' => 'Preview'],
            ['key' => 'map', 'label' => 'Map columns'],
            ['key' => 'validate', 'label' => 'Validate'],
            ['key' => 'import', 'label' => 'Import'],
            ['key' => 'report', 'label' => 'Report'],
        ]"
    />

    @if ($step === 'source')
        <form wire:submit="stageUpload" class="mb-8 flex max-w-xl flex-col gap-space-16">
            <x-field name="upload" label="CSV or Excel file" :error="$errors->first('upload')">
                <x-input name="upload" type="file" accept=".csv,.xlsx" wire:model="upload" />
            </x-field>
            <x-button type="submit">Preview file</x-button>
        </form>

        <form wire:submit="stageSheet" class="flex max-w-xl flex-col gap-space-16">
            <x-field name="sheet_url" label="Google Sheets link" help="The sheet must be shared as anyone with the link can view." :error="$errors->first('sheet_url')">
                <x-input name="sheet_url" type="url" inputmode="url" wire:model="sheet_url" />
            </x-field>
            <x-button type="submit" variant="secondary">Preview sheet</x-button>
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
            <x-button type="submit">Map columns</x-button>
        </form>
    @elseif ($step === 'map')
        @php
            $preview = $this->previewTable();
        @endphp
        <form wire:submit="confirmMap" class="flex max-w-xl flex-col gap-space-16">
            @foreach ($this->mappingFields() as $field)
                <x-field
                    wire:key="map-{{ $field['key'] }}"
                    name="column_map_{{ $field['key'] }}"
                    :label="$field['label']"
                    :required="$field['required']"
                >
                    <x-select name="column_map_{{ $field['key'] }}" wire:model="column_map.{{ $field['key'] }}">
                        <option value="">Not in this file</option>
                        @foreach ($preview['headers'] as $index => $header)
                            <option value="{{ $index }}">{{ $header !== '' ? $header : 'Column '.($index + 1) }}</option>
                        @endforeach
                    </x-select>
                </x-field>
            @endforeach
            @if ($errors->first('column_map') !== '')
                <p class="flex items-start gap-space-8 text-small font-normal text-danger-text">
                    <x-ui.icon name="alert" class="mt-0.5 size-4 shrink-0" />
                    <span>{{ $errors->first('column_map') }}</span>
                </p>
            @endif
            <x-button type="submit">Save mapping</x-button>
        </form>
    @elseif ($step === 'validate')
        @php
            $report = $this->validationReport();
        @endphp
        <div class="mb-6 flex max-w-xl flex-col gap-space-16">
            <x-field name="duplicate_mode" label="Existing students">
                <x-select name="duplicate_mode" wire:model.live="duplicate_mode">
                    <option value="skip">Skip existing students</option>
                    <option value="update">Update existing students</option>
                </x-select>
            </x-field>
            <x-field name="send_mode" label="Credentials emails" :error="$errors->first('send_mode')">
                <x-select name="send_mode" wire:model="send_mode">
                    <option value="now">Send credentials now</option>
                    <option value="later">Send credentials later</option>
                </x-select>
            </x-field>
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
            <x-button type="submit">Import students</x-button>
        </form>
    @elseif ($step === 'import')
        @php
            $progress = $this->importProgress();
        @endphp
        <p class="mb-2">{{ $progress['processed_rows'] }} of {{ $progress['total_rows'] }} rows processed.</p>
        <progress class="mb-4 w-full" value="{{ $progress['processed_rows'] }}" max="{{ max(1, $progress['total_rows']) }}"></progress>
        <p class="mb-4 text-sm">Created {{ $progress['created_count'] }}, updated {{ $progress['updated_count'] }}, skipped {{ $progress['skipped_count'] }}, failed {{ $progress['failed_count'] }}.</p>
        <form wire:submit="commitChunk">
            <x-button type="submit">Continue import</x-button>
        </form>
    @elseif ($step === 'report')
        @php
            $committed = $this->commitReport();
        @endphp
        <p class="mb-2">Created {{ $committed['created_count'] }}, updated {{ $committed['updated_count'] }}, skipped {{ $committed['skipped_count'] }}, failed {{ $committed['failed_count'] }}.</p>
        <p class="mb-4">{{ $committed['credentials_needed'] }} accounts still need credentials.</p>
        <div class="flex max-w-full flex-wrap gap-space-12">
            @if ($committed['download_url'] !== '')
                <x-button href="{{ $committed['download_url'] }}" variant="secondary">Download failed rows</x-button>
            @endif
            @if ($committed['students_url'] !== '')
                <x-button href="{{ $committed['students_url'] }}" variant="primary">View accounts that still need credentials</x-button>
            @endif
        </div>
    @endif
</x-filament-panels::page>
