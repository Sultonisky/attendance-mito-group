<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="theme-color" content="#eb1c24">
    <title>Sedang Dalam Pemeliharaan | MITO Group</title>
    <link rel="icon" type="image/png" href="/images/mito.png">
    <style>
        :root {
            --mito-primary: #eb1c24;
            --mito-navy: #0b2540;
            --mito-slate: #64748b;
            --mito-page-bg: #f8fafc;
            --mito-card-bg: #ffffff;
            --mito-card-border: #e2e8f0;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-width: 320px;
            min-height: 100svh;
            font-family: 'DM Sans', 'Segoe UI', ui-sans-serif, system-ui, sans-serif;
            background: var(--mito-page-bg);
            color: var(--mito-navy);
            -webkit-font-smoothing: antialiased;
        }

        .error-page {
            position: relative;
            min-height: 100svh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        .bg-ambient {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            background:
                radial-gradient(circle at 50% 15%, rgba(235, 28, 36, 0.04) 0%, transparent 60%),
                radial-gradient(circle at 90% 90%, rgba(11, 37, 64, 0.03) 0%, transparent 50%);
        }

        .error-main {
            position: relative;
            z-index: 1;
            flex: 1 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 1.25rem;
            width: 100%;
        }

        .error-container {
            width: 100%;
            max-width: 580px;
            margin: 0 auto;
        }

        .error-card {
            background: var(--mito-card-bg);
            border: 1px solid var(--mito-card-border);
            border-radius: 1.25rem;
            box-shadow:
                0 10px 30px -5px rgba(11, 37, 64, 0.06),
                0 4px 6px -2px rgba(11, 37, 64, 0.02);
            padding: 2.75rem 2.25rem 2.25rem;
            text-align: center;
        }

        .error-code {
            font-size: clamp(4.5rem, 12vw, 6rem);
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.04em;
            margin: 0 0 0.75rem;
            user-select: none;
            display: inline-block;
            background: linear-gradient(135deg, var(--mito-primary) 0%, #b91c1c 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            color: transparent;
        }

        .error-title {
            font-size: clamp(1.25rem, 3vw, 1.5rem);
            font-weight: 700;
            color: var(--mito-navy);
            margin: 0 0 0.75rem;
            letter-spacing: -0.02em;
        }

        .error-desc {
            font-size: 0.9375rem;
            color: var(--mito-slate);
            line-height: 1.6;
            margin: 0 auto;
            max-width: 460px;
        }

        .error-hint {
            margin: 1.25rem auto 0;
            font-size: 0.8125rem;
            color: var(--mito-slate);
            opacity: 0.85;
        }

        @media (max-width: 576px) {
            .error-card {
                padding: 2rem 1.25rem 1.75rem;
                border-radius: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="error-page">
        <div class="bg-ambient" aria-hidden="true"></div>
        <main class="error-main">
            <div class="error-container">
                <section class="error-card">
                    <div class="error-code" aria-hidden="true">503</div>
                    <h1 class="error-title">Sedang Dalam Pemeliharaan</h1>
                    <p class="error-desc">
                        Sistem sedang tidak tersedia sementara karena pemeliharaan. Silakan coba lagi beberapa saat.
                    </p>
                    <p class="error-hint" id="status-hint" aria-live="polite">
                        Halaman akan terbuka otomatis setelah sistem siap.
                    </p>
                </section>
            </div>
        </main>
    </div>

    <script>
        (function () {
            var hint = document.getElementById('status-hint');
            var timer = null;

            function probe() {
                fetch('/api/v1/health', {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                })
                    .then(function (res) {
                        if (res.ok) {
                            if (hint) hint.textContent = 'Sistem siap. Membuka kembali…';
                            window.location.replace('/');
                            return;
                        }
                        schedule();
                    })
                    .catch(function () {
                        schedule();
                    });
            }

            function schedule() {
                timer = window.setTimeout(probe, 8000);
            }

            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'visible') {
                    if (timer) window.clearTimeout(timer);
                    probe();
                }
            });

            schedule();
        })();
    </script>
</body>
</html>
