<style>
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif;
        background: radial-gradient(circle at top, #1e3a5f, #0b1424 70%); color: #f4f6fb;
        display: flex; align-items: center; justify-content: center; padding: 16px; }
    .card { width: 100%; max-width: 440px; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12);
        border-radius: 20px; padding: 28px 22px; backdrop-filter: blur(6px); }
    h1 { margin: 0 0 6px; font-size: 28px; text-align: center; }
    .sub { margin: 0 0 22px; text-align: center; color: #b8c4d9; }
    label { display: block; margin-bottom: 8px; font-weight: 600; }
    input, textarea { width: 100%; font: inherit; font-size: 17px; padding: 14px; border-radius: 12px;
        border: 1px solid rgba(255,255,255,.2); background: rgba(0,0,0,.25); color: inherit; }
    textarea { min-height: 110px; resize: vertical; }
    button { width: 100%; margin-top: 16px; font: inherit; font-size: 18px; font-weight: 700; padding: 14px;
        border: 0; border-radius: 12px; background: #f5b83d; color: #1a1300; cursor: pointer; }
    button:disabled { opacity: .5; cursor: default; }
    .err { color: #ff8a8a; margin-top: 8px; font-size: 14px; }
    .q { font-size: 20px; line-height: 1.6; margin: 0 0 14px; }
</style>
