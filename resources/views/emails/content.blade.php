@extends('emails.layout')

@section('content')
    @if ($greeting !== '')
        <p style="margin:0 0 16px 0;">{{ $greeting }}</p>
    @endif

    @foreach ($lines as $line)
        <p style="margin:0 0 12px 0;">{{ $line['label'] }}: {{ $line['value'] }}</p>
    @endforeach

    @if ($message_html !== '')
        <p style="margin:0 0 16px 0;">{!! $message_html !!}</p>
    @endif

    <p style="margin:0 0 16px 0;">{{ $instruction }}</p>
    <p style="margin:0;">
        <a href="{{ $action_url }}" style="display:inline-block;background:#4F46E5;color:#FFFFFF;text-decoration:none;border-radius:8px;padding:12px 16px;font-family:system-ui,Segoe UI,sans-serif;font-size:14px;line-height:22px;">{{ $action_label }}</a>
    </p>
@endsection
