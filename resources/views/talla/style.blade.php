<style>
    :root { --brand: #931e1e; --brand-dark: #6f1515; --brand-soft: #f8eded; --ink: #2b1414; --muted: #8a6b6b; --line: #ead6d6; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif;
        background: #fff; color: var(--ink);
        display: flex; align-items: center; justify-content: center; padding: 16px; }
    .card { width: 100%; max-width: 440px; background: #fff; border: 1px solid var(--line);
        border-radius: 20px; padding: 28px 22px; box-shadow: 0 10px 30px rgba(147, 30, 30, .08); }
    h1 { margin: 0 0 6px; font-size: 44px; font-weight: 900; text-align: center; color: var(--brand); }
    .sub { margin: 0 0 22px; text-align: center; color: var(--muted); }
    label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--brand-dark); }
    input, textarea { width: 100%; font: inherit; font-size: 17px; padding: 14px; border-radius: 12px;
        border: 1px solid var(--line); background: #fff; color: inherit; }
    input:focus, textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-soft); }
    textarea { min-height: 110px; resize: vertical; }
    button { width: 100%; margin-top: 16px; font: inherit; font-size: 18px; font-weight: 700; padding: 14px;
        border: 0; border-radius: 12px; background: var(--brand); color: #fff; cursor: pointer; }
    button:hover:not(:disabled) { background: var(--brand-dark); }
    button:disabled { opacity: .5; cursor: default; }
    .err { color: var(--brand); margin-top: 8px; font-size: 14px; }
    .q { font-size: 20px; line-height: 1.6; margin: 0 0 14px; }
</style>
