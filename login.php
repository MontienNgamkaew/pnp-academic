<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (isset($_GET['logout'])) {
    logout_user();
    redirect_to('login.php');
}

if (is_logged_in()) {
    if (current_user_role() === 'admin') {
        redirect_to('admin/overview.php');
    } else {
        redirect_to('teacher/dashboard.php');
    }
}

$errorMessage = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!verify_csrf_token($csrfToken)) {
        $errorMessage = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } elseif ($username === '' || $password === '') {
        $errorMessage = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, username, password, fullname, role, status
             FROM users
             WHERE username = :username
             LIMIT 1"
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if (
            $user
            && $user['status'] === 'active'
            && password_verify($password, $user['password'])
        ) {
            login_user($user);
            if ($user['role'] === 'admin') {
                redirect_to('admin/overview.php');
            } else {
                redirect_to('teacher/dashboard.php');
            }
        }

        $errorMessage = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
    }
}

$csrfToken = create_csrf_token();
$branding = get_branding_settings();
$themeColorKey = $branding['theme_color'] ?? 'dark-blue';
$themes = [
    'dark-blue' => [
        'bg_class' => 'bg-gradient-to-br from-slate-950 via-indigo-950 to-slate-900',
        'btn_shadow' => 'shadow-indigo-700/25 hover:shadow-indigo-800/30',
        'focus_class' => 'focus:ring-indigo-500/40 focus:border-indigo-500',
        'text_accent' => 'text-indigo-600'
    ],
    'dark-purple' => [
        'bg_class' => 'bg-gradient-to-br from-slate-950 via-purple-950 to-slate-900',
        'btn_shadow' => 'shadow-purple-700/25 hover:shadow-purple-800/30',
        'focus_class' => 'focus:ring-purple-500/40 focus:border-purple-500',
        'text_accent' => 'text-purple-600'
    ],
    'dark-emerald' => [
        'bg_class' => 'bg-gradient-to-br from-slate-950 via-emerald-950 to-slate-900',
        'btn_shadow' => 'shadow-emerald-700/25 hover:shadow-emerald-800/30',
        'focus_class' => 'focus:ring-emerald-500/40 focus:border-emerald-500',
        'text_accent' => 'text-emerald-600'
    ],
    'dark-slate' => [
        'bg_class' => 'bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950',
        'btn_shadow' => 'shadow-slate-700/25 hover:shadow-slate-800/30',
        'focus_class' => 'focus:ring-slate-500/40 focus:border-slate-500',
        'text_accent' => 'text-slate-600'
    ]
];
$activeTheme = $themes[$themeColorKey] ?? $themes['dark-blue'];
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เข้าสู่ระบบ | <?= htmlspecialchars($branding['system_name']); ?></title>
    <?php if (!empty($branding['logo_path'])): ?>
        <link rel="icon" type="image/png" href="<?= htmlspecialchars($branding['logo_path']); ?>">
    <?php endif; ?>
    <meta name="description" content="เข้าสู่ระบบ | <?= htmlspecialchars($branding['college_name']); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&family=Outfit:wght@400;600;850&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        thai: ['"IBM Plex Sans Thai"', 'sans-serif'],
                    },
                    colors: {
                        indigo: {
                            650: '#4f46e5'
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'IBM Plex Sans Thai', Tahoma, sans-serif; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-up { animation: fadeUp 0.5s ease-out both; }
    </style>
</head>
<body class="font-thai min-h-screen <?= $activeTheme['bg_class']; ?> flex items-center justify-center p-4">

<!-- Subtle background pattern -->
<div class="fixed inset-0 opacity-[0.03]" style="background-image: url('data:image/svg+xml,%3Csvg width=&quot;60&quot; height=&quot;60&quot; viewBox=&quot;0 0 60 60&quot; xmlns=&quot;http://www.w3.org/2000/svg&quot;%3E%3Cg fill=&quot;none&quot; fill-rule=&quot;evenodd&quot;%3E%3Cg fill=&quot;%23ffffff&quot; fill-opacity=&quot;1&quot;%3E%3Cpath d=&quot;M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z&quot;/%3E%3C/g%3E%3C/g%3E%3C/svg%3E');"></div>

<main class="relative w-full max-w-md animate-fade-up">
    <div class="bg-white/95 backdrop-blur-xl rounded-3xl shadow-2xl shadow-black/20 border border-white/20 overflow-hidden">
        <div class="p-6 sm:p-8">
            <!-- Brand -->
            <div class="text-center mb-6">
                <?php if (!empty($branding['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($branding['logo_path']); ?>" class="w-14 h-14 object-contain rounded-2xl mx-auto mb-4 shadow-lg">
                <?php else: ?>
                    <div class="w-14 h-14 rounded-2xl bg-slate-900 text-white font-extrabold text-lg flex items-center justify-center mx-auto mb-4 shadow-lg shadow-indigo-600/30">
                        <?= htmlspecialchars($branding['logo_text'] ?? 'PNP'); ?>
                    </div>
                <?php endif; ?>
                <h1 class="text-xl font-bold text-slate-800 mb-0.5"><?= htmlspecialchars($branding['system_name']); ?></h1>
                <p class="text-xs text-slate-400 font-medium"><?= htmlspecialchars($branding['college_name']); ?></p>
            </div>

            <!-- Error Message -->
            <?php if ($errorMessage !== ''): ?>
                <div class="mb-4 flex items-start gap-2.5 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm animate-pulse" role="alert">
                    <svg class="w-5 h-5 shrink-0 mt-0.5 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                    <span><?= e($errorMessage); ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="post" action="login.php" autocomplete="off" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">

                <div class="mb-4">
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5" for="username">ชื่อผู้ใช้</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                        </div>
                        <input
                            class="w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 text-sm placeholder-slate-400 focus:outline-none focus:ring-2 <?= $activeTheme['focus_class']; ?> transition-all duration-150"
                            type="text"
                            id="username"
                            name="username"
                            value="<?= e($username); ?>"
                            maxlength="100"
                            placeholder="กรอกชื่อผู้ใช้"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5" for="password">รหัสผ่าน</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                        </div>
                        <input
                            class="w-full pl-11 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 text-sm placeholder-slate-400 focus:outline-none focus:ring-2 <?= $activeTheme['focus_class']; ?> transition-all duration-150"
                            type="password"
                            id="password"
                            name="password"
                            placeholder="กรอกรหัสผ่าน"
                            required
                        >
                    </div>
                </div>

                <button
                    type="submit"
                    class="w-full flex items-center justify-center gap-2 bg-slate-900 hover:bg-slate-800 active:bg-slate-950 text-white font-semibold py-3 rounded-xl transition-all duration-150 shadow-lg <?= $activeTheme['btn_shadow']; ?>"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/></svg>
                    เข้าสู่ระบบ
                </button>
            </form>
        </div>

        <!-- Bottom bar -->
        <div class="px-6 sm:px-8 py-4 bg-slate-50 border-t border-slate-100">
            <p class="text-center text-xs text-slate-400 font-medium"><?= htmlspecialchars($branding['college_name']); ?> &middot; ฝ่ายวิชาการ</p>
        </div>
    </div>
</main>

</body>
</html>
