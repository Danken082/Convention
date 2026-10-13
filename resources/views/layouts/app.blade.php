<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Convention Registration')</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #182b3b;
            background: #f3f6f8;
            font-synthesis: none;
            text-rendering: optimizeLegibility;
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; }
        .topbar { background: #11334a; color: #fff; padding: 18px 24px; }
        .topbar-inner, main { width: min(920px, calc(100% - 32px)); margin: 0 auto; }
        .brand { font-size: 17px; font-weight: 750; letter-spacing: .02em; }
        main { padding: 48px 0 72px; }
        .intro { margin-bottom: 26px; }
        .eyebrow { color: #16765c; font-size: 12px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
        h1 { margin: 8px 0; font-size: clamp(28px, 5vw, 38px); letter-spacing: -.035em; }
        .intro p, .hint { color: #637687; line-height: 1.6; }
        .card { padding: clamp(20px, 5vw, 36px); border: 1px solid #e0e8ed; border-radius: 16px; background: #fff; box-shadow: 0 12px 36px rgb(22 49 68 / 6%); }
        .section-title { margin: 0 0 18px; font-size: 18px; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 20px; }
        .field { min-width: 0; }
        .field.wide { grid-column: 1 / -1; }
        label { display: block; margin-bottom: 7px; font-size: 13px; font-weight: 700; }
        input, select { width: 100%; min-height: 46px; border: 1px solid #cfdae2; border-radius: 8px; padding: 10px 12px; color: #182b3b; background: #fff; font: inherit; }
        input:focus, select:focus { outline: 3px solid rgb(31 132 101 / 18%); border-color: #1f8465; }
        input[type="file"] { padding: 8px; }
        button[hidden] { display: none; }
        .hint { margin: 6px 0 0; font-size: 12px; }
        .required { color: #b43e38; }
        .actions { display: flex; justify-content: flex-end; margin-top: 24px; }
        button, .button { display: inline-flex; justify-content: center; align-items: center; min-height: 44px; border: 0; border-radius: 8px; padding: 11px 18px; background: #176b54; color: #fff; font: inherit; font-weight: 750; text-decoration: none; cursor: pointer; }
        button:hover, .button:hover { background: #10543f; }
        button.secondary { background: #eaf0f3; color: #243b4a; }
        button.secondary:hover { background: #dce6eb; }
        .notice { margin-bottom: 18px; padding: 13px 15px; border-radius: 8px; line-height: 1.5; }
        .notice.success { background: #e6f5ee; color: #175b42; }
        .notice.warning, .notice.error { background: #fff2e4; color: #865016; }
        .error-text { margin: 6px 0 0; color: #a52e2b; font-size: 12px; }
        dialog { width: min(430px, calc(100% - 28px)); border: 0; border-radius: 14px; padding: 28px; color: #182b3b; box-shadow: 0 22px 70px rgb(0 0 0 / 24%); }
        dialog::backdrop { background: rgb(10 27 39 / 58%); }
        dialog h2 { margin: 0 0 8px; font-size: 22px; }
        dialog p { margin: 0 0 18px; color: #637687; line-height: 1.5; }
        .dialog-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
        .admin-header { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 20px; }
        .admin-header h1 { font-size: 28px; }
        .admin-actions { display: flex; gap: 10px; align-items: center; }
        .admin-actions form { margin: 0; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 12px 10px; border-bottom: 1px solid #e6ecef; text-align: left; white-space: nowrap; }
        th { color: #586e7e; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; }
        .pagination { margin-top: 20px; }
        @media (max-width: 620px) {
            main { padding-top: 32px; }
            .fields { grid-template-columns: 1fr; gap: 15px; }
            .field.wide { grid-column: auto; }
            .admin-header { align-items: flex-start; flex-direction: column; }
            .admin-actions { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner"><div class="brand">Convention Registration</div></div>
    </header>
    <main>
        @yield('content')
    </main>
    @stack('scripts')
</body>
</html>
