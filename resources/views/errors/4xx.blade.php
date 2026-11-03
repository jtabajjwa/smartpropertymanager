@extends('errors.layout')

{{-- Any other 4xx without its own page. A 422 from abort() carries a message written for people, so show it. --}}
@php
    $status = $exception->getStatusCode();
    $reason = $status === 422 && $exception->getMessage() !== '' ? $exception->getMessage() : null;
@endphp

@section('code', (string) $status)
@section('title', 'That couldn’t be done')
@section('message', $reason ?? 'The request couldn’t be completed. Please go back, refresh the page and try again.')
