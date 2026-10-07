@props([
    'columns',
    'rows',
    'paginator' => null,
    'sort' => null,
    'direction' => 'asc',
    'caption' => 'Results',
])

<div {{ $attributes->merge(['class' => 'max-w-full min-w-0']) }}>
    @isset($toolbar)
        <div class="mb-space-16 max-w-full min-w-0">
            {{ $toolbar }}
        </div>
    @endisset

    @if (count($rows) === 0)
        @isset($empty)
            {{ $empty }}
        @else
            <x-empty-state message="Nothing to show yet." />
        @endisset
    @else
        <div class="hidden max-w-full overflow-x-auto rounded-card border border-border bg-surface md:block">
            <table class="w-full min-w-0 border-collapse text-left">
                <caption class="sr-only">{{ $caption }}</caption>
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            @php
                                $aria_sort = 'none';

                                if (($sort ?? null) === $column['key'] && ($column['sortable'] ?? false)) {
                                    $aria_sort = $direction === 'desc' ? 'descending' : 'ascending';
                                }
                            @endphp
                            <th
                                scope="col"
                                @if ($column['sortable'] ?? false) aria-sort="{{ $aria_sort }}" @endif
                                class="sticky top-0 h-12 border-b border-border bg-surface px-space-12 text-small font-semibold text-muted"
                            >
                                @if (($column['sortable'] ?? false) && filled($column['href'] ?? null))
                                    <a href="{{ $column['href'] }}" class="inline-flex min-h-11 items-center gap-space-4 rounded-control text-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
                                        {{ $column['label'] }}
                                        <x-ui.icon name="chevron-down" class="{{ $aria_sort === 'ascending' ? 'rotate-180' : '' }}" />
                                    </a>
                                @else
                                    {{ $column['label'] }}
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="h-12 border-b border-border last:border-b-0 hover:bg-primary-50">
                            @foreach ($columns as $column)
                                <td class="px-space-12 text-body font-normal text-text tabular-nums">
                                    @include('components.partials.table-cell', ['column' => $column, 'row' => $row])
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="grid max-w-full gap-space-12 md:hidden">
            @foreach ($rows as $row)
                <article class="rounded-card border border-border bg-surface p-space-16 shadow-sm">
                    @foreach (collect($columns)->where('important', true)->take(4) as $column)
                        <p class="mt-space-8 flex max-w-full flex-wrap items-center justify-between gap-space-8 text-body font-normal first:mt-0">
                            <span class="text-small font-normal text-muted">{{ $column['label'] }}</span>
                            <span class="min-w-0 text-text tabular-nums">
                                @include('components.partials.table-cell', ['column' => $column, 'row' => $row])
                            </span>
                        </p>
                    @endforeach
                    <div class="mt-space-12">
                        <a href="{{ $row['view_url'] ?? '#' }}" class="inline-flex min-h-11 items-center text-body font-semibold text-primary-600 underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">View</a>
                    </div>
                </article>
            @endforeach
        </div>

        @if ($paginator && $paginator->hasPages())
            <nav aria-label="Pagination" class="mt-space-16 flex max-w-full flex-wrap items-center justify-between gap-space-8">
                @if ($paginator->onFirstPage())
                    <span class="inline-flex min-h-11 items-center text-body font-semibold text-muted">Previous</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" class="inline-flex min-h-11 items-center text-body font-semibold text-primary-600 underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">Previous</a>
                @endif
                <p class="text-small font-normal text-muted tabular-nums">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</p>
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" class="inline-flex min-h-11 items-center text-body font-semibold text-primary-600 underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">Next</a>
                @else
                    <span class="inline-flex min-h-11 items-center text-body font-semibold text-muted">Next</span>
                @endif
            </nav>
        @endif
    @endif
</div>
