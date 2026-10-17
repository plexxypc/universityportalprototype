@extends('layouts.student')

@section('title', 'More')

@section('current', 'more')

@section('content')
    <x-page-header title="More" description="Pages you can open." />
    @if ($pages === [])
        <x-empty-state message="No pages are available yet." />
    @else
        <ul class="flex flex-col gap-space-8">
            @foreach ($pages as $page)
                <li>
                    <x-button href="{{ route($page['route']) }}" variant="secondary">{{ $page['label'] }}</x-button>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
