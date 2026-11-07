<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Resi belum tersedia</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; }
        * { box-sizing: border-box; }
        body { display: grid; min-height: 100vh; margin: 0; place-items: center; background: #f4f7fb; color: #172033; }
        main { width: min(92vw, 480px); padding: 28px; border: 1px solid #dbe3ef; border-radius: 10px; background: #fff; box-shadow: 0 20px 50px rgba(15, 23, 42, .1); }
        .icon { display: grid; width: 44px; height: 44px; margin-bottom: 18px; place-items: center; border-radius: 8px; background: #fff4e5; color: #b45309; font-size: 24px; font-weight: 800; }
        h1 { margin: 0; font-size: 21px; }
        p { margin: 10px 0 0; color: #526078; font-size: 14px; line-height: 1.55; }
        .order { margin-top: 16px; padding: 10px 12px; border-radius: 7px; background: #f1f5f9; font-family: "Courier New", monospace; font-size: 13px; font-weight: 700; }
        .actions { display: flex; gap: 8px; margin-top: 22px; }
        button { padding: 10px 15px; border: 0; border-radius: 7px; cursor: pointer; font-weight: 700; }
        .primary { background: #304674; color: #fff; }
        .secondary { border: 1px solid #d6deea; background: #fff; color: #334155; }
    </style>
</head>
<body>
    <main>
        <div class="icon">!</div>
        <h1>Resi belum dapat dicetak</h1>
        <p>{{ $message }}</p>
        <div class="order">Pesanan {{ $order->order_sn }}</div>
        <div class="actions">
            <button type="button" class="primary" onclick="window.location.reload()">Coba lagi</button>
            <button type="button" class="secondary" onclick="window.close()">Tutup</button>
        </div>
    </main>
</body>
</html>
