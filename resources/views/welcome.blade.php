<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MediQueue · 医院预约挂号</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900">
    <main class="mx-auto max-w-3xl px-6 py-16 sm:py-24">
        <p class="text-sm font-semibold tracking-wide text-teal-700">MediQueue / 医院预约挂号课程项目</p>
        <h1 class="mt-6 text-4xl font-semibold tracking-tight sm:text-5xl">从可靠的预约开始。</h1>
        <p class="mt-6 text-lg leading-8 text-slate-600">单医院、多科室预约系统。当前为 P0 基础工程，业务功能将在后续阶段经审核后逐步开放。</p>
        <aside class="mt-10 rounded-2xl border border-amber-200 bg-amber-50 p-6" aria-label="演示说明">
            <h2 class="font-semibold text-amber-950">模拟微信支付 · 无实际扣款</h2>
            <p class="mt-2 leading-7 text-amber-900">仅用于课程演示，使用合成数据，不用于真实临床服务。当前阶段尚未开放预约、付款或退款。</p>
        </aside>
        <section class="mt-10 grid gap-4 sm:grid-cols-2" aria-label="项目状态">
            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="font-semibold">当前阶段</h2>
                <p class="mt-2 leading-7 text-slate-600">P0 · 工程与数据库基础验证</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="font-semibold">后续开发</h2>
                <p class="mt-2 leading-7 text-slate-600">验收后进入 P1 · 数据库模型</p>
            </div>
        </section>
        <p class="mt-10 text-sm text-slate-500">本页面不采集患者资料。正式需求依据为项目中的 V1.1 设计文档。</p>
    </main>
</body>
</html>
