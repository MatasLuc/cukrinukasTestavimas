<style>
    :root {
      --color-bg: #f7f7fb;
      --color-surface: #ffffff;
      --color-border: #e1e3ef;
      --color-primary: #0b0b0b;
      --color-accent: #4f46e5;
      --color-muted: #6b6b7a;
    }

    * { box-sizing: border-box; }
    body { margin:0; background:var(--color-bg); color:var(--color-primary); }
    a { color:inherit; text-decoration:none; }
    .page { max-width:1200px; margin:0 auto; padding:32px 24px 48px; }

    .hero {
      position: relative;
      background: radial-gradient(circle at 20% 20%, rgba(79,70,229,0.12), transparent 32%),
                  radial-gradient(circle at 80% 0%, rgba(16,185,129,0.12), transparent 30%),
                  #f7f7fb;
      border:1px solid var(--color-border);
      border-radius:24px;
      padding:24px;
      box-shadow:0 12px 40px rgba(15, 23, 42, 0.08);
      display:flex;
      gap:24px;
      align-items:flex-start;
      margin-bottom:18px;
    }
    .hero h1 { margin:8px 0 6px; font-size:28px; }
    .hero .eyebrow { text-transform:uppercase; letter-spacing:0.08em; font-weight:700; font-size:12px; color:var(--color-accent); }
    .hero p { margin:4px 0; color:var(--color-muted); }
    .hero-actions { display:flex; gap:10px; margin-top:10px; }
    .hero-actions a { font-weight:600; padding:10px 14px; border-radius:12px; border:1px solid var(--color-border); background:rgba(255,255,255,0.9); }

    .stat-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:12px; flex:1; }
    .stat-card { background:rgba(255,255,255,0.9); border:1px solid var(--color-border); border-radius:14px; padding:12px 14px; box-shadow:0 10px 24px rgba(15,23,42,0.05); }
    .stat-label { font-size:12px; text-transform:uppercase; letter-spacing:0.05em; color:var(--color-muted); font-weight:700; }
    .stat-value { font-size:26px; font-weight:800; margin-top:4px; }
    .stat-sub { font-size:12px; color:var(--color-muted); }

    .nav { display:flex; gap:10px; row-gap:8px; margin:12px 0 18px; flex-wrap:wrap; }
    .nav a { padding:10px 14px; border-radius:12px; border:1px solid var(--color-border); background:var(--color-surface); font-weight:700; box-shadow:0 8px 16px rgba(15,23,42,0.04); }
    .nav a.active { background:linear-gradient(135deg, #111827, #4338ca); color:#fff; border-color:#111827; box-shadow:0 12px 28px rgba(67,56,202,0.25); }

    .grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:18px; }
    .section-stack { display:flex; flex-direction:column; gap:16px; margin-top:12px; }
    .card { background:var(--color-surface); border-radius:18px; padding:18px; border:1px solid var(--color-border); box-shadow:0 10px 32px rgba(15, 23, 42, 0.06); }
    .card h3 { margin-top:0; margin-bottom:10px; }

    .btn { padding:10px 14px; border-radius:12px; border:1px solid #0b0b0b; background:#0b0b0b; color:#fff; font-weight:700; cursor:pointer; transition:transform 0.1s ease, box-shadow 0.2s ease; }
    .btn:hover { transform:translateY(-1px); box-shadow:0 10px 20px rgba(15,23,42,0.12); }
    .btn.secondary { background:#f7f7fb; color:var(--color-primary); border-color:var(--color-border); }

    input, textarea, select { width:100%; padding:11px 12px; border-radius:12px; border:1px solid var(--color-border); margin-bottom:8px; background:#fff; }
    input:focus, textarea:focus, select:focus { outline:2px solid rgba(79,70,229,0.2); border-color:#4338ca; }

    table { width:100%; border-collapse: collapse; }
    th, td { padding:10px 8px; border-bottom:1px solid #edf0f6; text-align:left; font-size:14px; word-break:break-word; }
    th { text-transform:uppercase; letter-spacing:0.05em; font-size:12px; color:var(--color-muted); }
    tr:hover td { background:#fafbff; }

    .table-form td { vertical-align:middle; }
    .table-form input,
    .table-form select { margin:0; min-width:120px; width:100%; padding:9px 10px; }
    .inline-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .table-note { color:var(--color-muted); font-size:13px; margin:-4px 0 8px; }

    .muted { color:var(--color-muted); }
    .image-list { display:flex; gap:10px; flex-wrap:wrap; }
    .image-tile { border:1px solid #e6e6ef; border-radius:12px; padding:8px; width:140px; text-align:center; background:#f9f9ff; }
    .image-tile img { width:100%; height:90px; object-fit:cover; border-radius:10px; }
    .input-row { display:flex; gap:10px; flex-wrap:wrap; }
    .chip-input { border:1px solid #e6e6ef; border-radius:12px; padding:8px 10px; background:#f7f7fb; display:inline-flex; gap:6px; align-items:center; }
    .pill { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; background:#eef2ff; color:#4338ca; font-weight:600; font-size:12px; }
    .alert { border-radius:14px; padding:12px 14px; border:1px solid; box-shadow:0 8px 16px rgba(15,23,42,0.06); }
    .alert.success { background:#edf9f0; border-color:#b8e2c4; color:#0f5132; }
    .alert.error { background:#fff1f1; border-color:#f3b7b7; color:#991b1b; }
    @media (max-width: 920px) {
      .page { padding:22px 16px 40px; }
      .hero { flex-direction:column; align-items:stretch; gap:16px; }
      .hero-actions { flex-wrap:wrap; }
      .stat-grid { width:100%; }
      .nav { overflow-x:auto; padding-bottom:6px; }
      .nav a { white-space:nowrap; }
      .card { padding:16px; }
      .grid { grid-template-columns: repeat(auto-fit, minmax(220px,1fr)); }
      table { display:block; overflow-x:auto; width:100%; }
      th, td { white-space:nowrap; }
    }
    @media (max-width: 640px) {
      .page { padding:18px 12px 32px; }
      .hero { padding:18px; }
      .input-row { flex-direction:column; }
      .hero-actions { gap:8px; }
    }
</style>
