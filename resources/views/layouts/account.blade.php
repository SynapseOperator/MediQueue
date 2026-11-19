@auth
    <span class="nav-user" title="{{ auth()->user()->username }}">{{ auth()->user()->username }}</span>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-link" type="submit">退出登录</button></form>
@else
    <a class="nav-link" href="{{ route('login') }}">登录</a>
    <a class="btn" href="{{ route('register') }}">患者注册</a>
@endauth
