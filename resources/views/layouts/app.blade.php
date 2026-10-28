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
        .topbar { background: #f0f0f0; color: #fff; padding: 8px 0 0; }
        .topbar-inner { width: min(740px, calc(100% - 32px)); margin: 0 auto; }
        main { width: min(1280px, calc(100% - 32px)); margin: 0 auto; }
        .brand { font-size: 17px; font-weight: 750; letter-spacing: .02em; }
        .brand-banner { display: block; width: 100%; aspect-ratio: 4 / 1; object-fit: cover; object-position: center 42%; border-radius: 8px; }
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
        body.registration-page .registration-actions {
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        body.registration-page .registration-action-group {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
        }
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
        body.registration-page #registration-success-dialog {
            width: min(520px, calc(100% - 28px));
            text-align: center;
        }
        body.registration-page .registration-success-logo {
            display: block;
            width: 100%;
            height: auto;
            margin: 0 auto 22px;
            border-radius: 8px;
        }
        body.registration-page #registration-success-dialog p {
            margin: 12px 0 22px;
            color: #182b3b;
        }
        body.registration-page #registration-confirmation {
            width: min(720px, calc(100% - 28px));
            max-height: min(80vh, 760px);
            overflow-y: auto;
        }
        body.registration-page #registration-confirmation-summary section {
            margin-top: 18px;
            border-top: 1px solid #e6ecef;
            padding-top: 12px;
        }
        body.registration-page #registration-confirmation-summary h3 {
            margin: 0 0 10px;
            color: #a0602b;
            font-size: 16px;
        }
        body.registration-page #registration-confirmation-summary p {
            margin: 6px 0;
            color: #150d08;
            overflow-wrap: anywhere;
        }
        body.registration-page #proof-preview-dialog {
            width: min(900px, calc(100% - 28px));
            max-height: min(90vh, 900px);
        }
        body.registration-page #proof-preview-image {
            display: block;
            max-width: 100%;
            max-height: 70vh;
            margin: 14px auto 0;
            object-fit: contain;
        }
        body.registration-page .proof-preview-trigger {
            min-height: auto;
            border: 0;
            padding: 0;
            background: #fff !important;
            color: #176b54;
            font-weight: inherit;
            text-decoration: underline;
            overflow-wrap: anywhere;
        }
        body.registration-page .proof-preview-trigger:hover {
            background: #fff;
            color: #10543f;
        }
        @media (max-width: 620px) {
            body.registration-page #registration-confirmation {
                padding: 20px 16px;
            }
            body.registration-page #registration-confirmation .dialog-actions {
                align-items: stretch;
                flex-direction: column-reverse;
            }
        }
        .admin-header { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 20px; }
        .admin-header h1 { font-size: 28px; }
        .admin-actions { display: flex; gap: 10px; align-items: center; }
        .admin-actions form { margin: 0; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 12px 10px; border-bottom: 1px solid #e6ecef; text-align: left; white-space: nowrap; }
        th { color: #586e7e; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; }
        .pagination { margin-top: 20px; }

        body.registration-page { background: #f0f0f0; color: #150d08; }
        body.registration-page .topbar { background: #f0f0f0; }
        body.registration-page main { width: min(740px, calc(100% - 32px)); padding-top: 12px; }
        body.registration-page .registration-shell {
            margin: 0 auto;
        }
        body.registration-page .form-panel {
            padding: 0 0 24px;
        }
        body.registration-page .intro {
            margin: 0 0 12px;
            padding: 20px 22px;
            border: 1px solid #dadce0;
            border-top: 8px solid #999;
            border-left: 5px solid #4285f4;
            border-radius: 8px;
            background: #fff;
        }
        body.registration-page .eyebrow,
        body.registration-page .intro h1,
        body.registration-page .form-card > .section-title {
            color: #a0602b;
        }
        body.registration-page .intro h1 {
            margin: 0 0 10px;
            color: #202124;
            font-size: 28px;
            letter-spacing: 0;
            text-transform: uppercase;
        }
        body.registration-page .eyebrow {
            margin-bottom: 8px;
            color: #5f6368;
            letter-spacing: .04em;
        }
        body.registration-page .form-card label,
        body.registration-page .attendee-heading,
        body.registration-page .form-card input,
        body.registration-page .form-card select {
            color: #150d08;
        }
        body.registration-page .intro p,
        body.registration-page .hint {
            color: #5d4b40;
        }
        body.registration-page .intro p {
            margin: 0 0 16px;
        }
        body.registration-page .registration-instructions {
            margin: 0;
            padding-left: 22px;
        }
        body.registration-page .registration-instructions li {
            padding-left: 2px;
        }
        body.registration-page .email-step {
            margin-bottom: 12px;
            border-top: 8px solid #999;
            border-left: 5px solid #4285f4;
            border-radius: 8px;
            padding: 20px 22px;
            box-shadow: none;
        }
        body.registration-page .section-marker {
            display: table;
            margin: 0 0 0 0;
            border-radius: 8px 8px 0 0;
            padding: 8px 16px;
            background: #999;
            color: #150d08;
            font-size: 13px;
            line-height: 1.2;
        }
        body.registration-page .attendee-form {
            margin-top: 16px;
            border-bottom: 1px solid #e6ecef;
            padding-bottom: 18px;
        }
        body.registration-page .attendee-form[hidden] { display: none; }
        body.registration-page .attendee-form-header {
            display: flex;
            justify-content: flex-end;
            min-height: 12px;
            margin-bottom: 8px;
        }
        body.registration-page .attendee-form-header button {
            min-height: 36px;
            padding: 8px 14px;
        }
        body.registration-page .attendee-pagination {
            display: flex;
            align-items: center;
            gap: 4px;
            margin: 12px 0;
        }
        body.registration-page .attendee-pagination .page-button {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            min-width: 34px;
            width: auto;
            height: 34px;
            min-height: 0;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            padding: 0 9px;
            background: #fff;
            color: #150d08;
            font-size: 14px;
            font-weight: 600;
        }
        body.registration-page .attendee-pagination .page-button:hover:not(:disabled):not(.active) {
            background: #f3f3f3;
        }
        body.registration-page .attendee-pagination .page-button.active {
            border-color: #fb9100;
            background: #d9d9d9;
            color: #150d08;
        }
        body.registration-page .attendee-pagination .page-button:disabled {
            background: #f7f7f7;
            color: #999;
            cursor: not-allowed;
        }
        body.registration-page .page-ellipsis {
            min-width: 20px;
            color: #5d4b40;
            font-size: 14px;
            text-align: center;
        }
        body.registration-page .section-marker + .email-step {
            border-top-left-radius: 0;
        }
        body.registration-page .email-step .section-title {
            color: #202124;
        }
        body.registration-page #email-verification-form input {
            max-width: 460px;
        }
        body.registration-page #verified-email-display p {
            margin-top: 0;
        }
        body.registration-page #email-verification-form #verified-email-display {
            margin-right: auto;
        }
        body.registration-page .bank-details {
            margin-top: 18px;
            line-height: 1.45;
        }
        body.registration-page .bank-details-title {
            margin: 0;
            text-decoration: underline;
        }
        body.registration-page .bank-details p {
            margin: 0;
        }
        body.registration-page .card.form-card {
            background: #fff;
            border-radius: 8px;
            border: 1px solid #dadce0;
            box-shadow: none;
            padding: 20px 22px 24px;
        }
        body.registration-page button:not(.secondary) {
            background: #ff9800;
            color: #150d08;
        }
        body.registration-page button:not(.secondary):hover {
            background: #e78300;
        }
        body.registration-page button.secondary {
            background: #ececec;
            color: #150d08;
        }
        body.registration-page .button.secondary {
            background: #ececec;
            color: #150d08;
        }
        body.registration-page button.secondary:hover {
            background: #d9d9d9;
        }
        body.registration-page .button.secondary:hover {
            background: #d9d9d9;
        }
        body.registration-page .required { color: #a0602b; }
        body.registration-page input:focus,
        body.registration-page select:focus {
            outline-color: rgb(160 96 43 / 22%);
            border-color: #a0602b;
        }
        body.admin-page {
            background: #f0f0f0;
            color: #150d08;
        }
        body.admin-page .topbar {
            background: #f0f0f0;
        }
        body.admin-page main {
            width: min(740px, calc(100% - 32px));
            padding-top: 12px;
        }
        body.admin-page .admin-shell {
            margin: 0 auto;
        }
        body.admin-page .admin-header {
            align-items: flex-end;
            margin-bottom: 12px;
        }
        body.admin-page .admin-heading h1 {
            margin: 0;
            color: #202124;
            font-size: 28px;
            letter-spacing: 0;
            text-transform: uppercase;
        }
        body.admin-page .admin-heading .eyebrow {
            margin: 0 0 6px;
            color: #a0602b;
        }
        body.admin-page .admin-actions {
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
        }
        body.admin-page .admin-actions button,
        body.admin-page .admin-actions .button {
            min-height: 36px;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
        }
        body.admin-page .admin-card {
            border-top: 8px solid #999;
            border-left: 5px solid #4285f4;
            border-radius: 8px;
            padding: 20px 16px 24px;
            box-shadow: none;
        }
        body.admin-page .admin-card .notice {
            border-radius: 6px;
        }
        body.admin-page .admin-card > .hint {
            margin: 0 0 14px;
            color: #5d4b40;
        }
        body.admin-page .table-wrap {
            border: 1px solid #e6ecef;
            border-radius: 6px;
        }
        body.admin-page table {
            font-size: 12px;
        }
        body.admin-page th,
        body.admin-page td {
            padding: 10px 9px;
        }
        body.admin-page th {
            background: #fafafa;
            color: #5f6368;
            font-size: 10px;
        }
        body.admin-page td {
            color: #150d08;
        }
        body.admin-page td a {
            color: #176b54;
            font-weight: 700;
        }
        body.admin-page .pagination {
            color: #5d4b40;
            font-size: 12px;
        }
        body.admin-page .pagination nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        body.admin-page .pagination nav > div:first-child {
            display: none;
        }
        body.admin-page .pagination nav > div:last-child > span,
        body.admin-page .pagination nav > div:last-child > a {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        body.admin-page .pagination a,
        body.admin-page .pagination span {
            min-width: 28px;
            min-height: 28px;
            align-items: center;
            justify-content: center;
            border: 1px solid #d9d9d9;
            border-radius: 5px;
            padding: 4px 8px;
            color: #150d08;
            text-decoration: none;
        }
        body.admin-page .pagination svg {
            width: 14px;
            height: 14px;
        }
        body.admin-page .pagination span[aria-current="page"] {
            border-color: #fb9100;
            background: #d9d9d9;
        }
        @media (max-width: 620px) {
            main { padding-top: 32px; }
            .fields { grid-template-columns: 1fr; gap: 15px; }
            .field.wide { grid-column: auto; }
            .admin-header { align-items: flex-start; flex-direction: column; }
            .admin-actions { flex-wrap: wrap; }
            body.registration-page .topbar-inner,
            body.registration-page main { width: min(100%, calc(100% - 24px)); }
            body.registration-page .intro { padding: 18px 16px; }
            body.registration-page .email-step { padding: 18px 16px; }
            body.registration-page .card.form-card { padding: 18px 16px; }
            body.registration-page .registration-actions { align-items: flex-start; }
            body.admin-page main { width: min(100%, calc(100% - 24px)); }
            body.admin-page .admin-header { align-items: flex-start; flex-direction: column; }
            body.admin-page .admin-actions { justify-content: flex-start; }
            body.admin-page .admin-card { padding: 18px 12px; }
        }
    </style>
</head>
<body class="@yield('body-class', '')">
    <header class="topbar">
        <div class="topbar-inner">
            <img class="brand-banner" src="{{ asset('images/unstoppable-2027-banner.png') }}" alt="Unstoppable 2027, Foursquare Global Conference for Young Adults, July 23–25 at SMX Manila, Philippines.">
        </div>
    </header>
    <main>
        @yield('content')
    </main>
    @stack('scripts')
</body>
</html>
