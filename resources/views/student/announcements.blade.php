@extends('layouts.student')

@section('title', 'Announcements')

@section('current', 'more')

@section('content')
    <x-page-header title="Announcements" description="Messages from the university." />
    <x-empty-state message="There are no announcements yet." />
@endsection
