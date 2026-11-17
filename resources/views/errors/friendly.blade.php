@extends('layouts.app')
@section('title', '页面暂不可用')
@section('content')
    <section class="panel mx-auto max-w-xl text-center"><p class="eyebrow">{{ $code }}</p><h1 class="page-title mt-3">{{ $message }}</h1><p class="page-intro mx-auto">{{ $detail }}</p><div class="mt-7 flex flex-wrap justify-center gap-3"><a class="btn" href="{{ route('home') }}">返回首页</a><a class="btn-secondary" href="{{ route('doctors.index') }}">查找医生</a></div></section>
@endsection
