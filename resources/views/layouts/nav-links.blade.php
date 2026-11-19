@foreach($links as [$name, $pattern, $label])
    <a class="nav-link" href="{{ route($name) }}" @if(request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
@endforeach
