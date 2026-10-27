<!doctype html>
<html lang="fr"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">
    <title>{{ $title }} · Cofrac</title>
    <link rel="icon" type="image/png" href="/images/logo.png">
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100svh;color:#203e4a;font-family:Verdana,sans-serif;background:radial-gradient(ellipse at 15% 15%,#d6f3ed,transparent 50%),radial-gradient(ellipse at 95% 90%,#dbe8ff,transparent 50%),#f4f7fa;display:grid;place-items:center;padding:24px}main{width:100%;max-width:740px;background:#ffffffed;border:1px solid #d6e1e8;border-radius:24px;padding:clamp(24px,5vw,52px);box-shadow:0 20px 70px #18394c0c}.brand{display:flex;align-items:center;gap:14px;font-size:14px;font-weight:bold}.brand img{width:46px;height:auto}.eyebrow{margin-top:36px;font-size:12px;letter-spacing:.12em;color:#55788c;font-weight:bold}.code{font-size:clamp(58px,13vw,88px);line-height:1;margin:20px 0;color:#0f776b;font-weight:bold}h1{font-size:clamp(22px,4vw,30px);line-height:1.3;max-width:580px}p{font-size:14px;line-height:1.8;color:#526e7d}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:26px}a,button{font:inherit}a.button,button{min-height:44px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #bccfdd;border-radius:10px;padding:11px 16px;font-size:13px;font-weight:bold;text-decoration:none;cursor:pointer;color:#244858;background:white}button{background:#1758e5;border-color:#1758e5;color:white}.diagnostic{margin-top:30px;border-top:1px solid #dce6eb;padding-top:20px}.reference{font-size:12px;overflow-wrap:anywhere;user-select:all;color:#35556a}.hint{font-size:11px}a:focus-visible,button:focus-visible{outline:3px solid #20c5d5;outline-offset:3px}@media(max-width:480px){body{padding:14px}.actions>*{width:100%}.actions form button{width:100%}}
    </style>
</head><body><main>
    <div class="brand"><img src="/images/logo.png" alt="Genius Contrôle"><span>Espace Cofrac</span></div>
    @yield('content')
</main></body></html>
