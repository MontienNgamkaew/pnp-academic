<?php
declare(strict_types=1);

// Prevent browser/server caching of dynamic page content
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$isLoggedIn = is_logged_in();
$dashboardUrl = '#';
if ($isLoggedIn) {
    $dashboardUrl = (current_user_role() === 'admin') ? 'admin/overview.php' : 'teacher/dashboard.php';
}
$loginUrl = 'login.php';

$semester = $pdo->query('SELECT id, semester_name FROM semesters WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetch();
$systemSettings = [];
if ($semester) {
    $stmt = $pdo->prepare('SELECT system_type, deadline_date, is_open FROM system_settings WHERE semester_id = :semester_id');
    $stmt->execute(['semester_id' => $semester['id']]);
    foreach ($stmt->fetchAll() as $row) {
        $systemSettings[$row['system_type']] = $row;
    }
}
$branding = get_branding_settings();

$themeColorKey = $branding['theme_color'] ?? 'dark-blue';
$themes = [
    'dark-blue' => [
        'bg_gradient' => 'linear-gradient(160deg, #0f172a 0%, #1e293b 30%, #0c1a3a 60%, #0f172a 100%)',
        'glow_css' => '
            radial-gradient(ellipse 900px 500px at 15% 0%, rgba(56, 97, 251, 0.15), transparent 65%),
            radial-gradient(ellipse 800px 450px at 85% 10%, rgba(99, 102, 241, 0.12), transparent 60%),
            radial-gradient(ellipse 600px 400px at 50% 90%, rgba(14, 165, 233, 0.08), transparent 55%)
        ',
        'btn_accent' => 'bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 shadow-lg shadow-indigo-600/30'
    ],
    'dark-purple' => [
        'bg_gradient' => 'linear-gradient(160deg, #0f172a 0%, #1d102f 30%, #2e1049 60%, #0f172a 100%)',
        'glow_css' => '
            radial-gradient(ellipse 900px 500px at 15% 0%, rgba(168, 85, 247, 0.15), transparent 65%),
            radial-gradient(ellipse 800px 450px at 85% 10%, rgba(236, 72, 153, 0.12), transparent 60%),
            radial-gradient(ellipse 600px 400px at 50% 90%, rgba(139, 92, 246, 0.08), transparent 55%)
        ',
        'btn_accent' => 'bg-purple-600 hover:bg-purple-500 active:bg-purple-700 shadow-lg shadow-purple-600/30'
    ],
    'dark-emerald' => [
        'bg_gradient' => 'linear-gradient(160deg, #0f172a 0%, #062f25 30%, #022c22 60%, #0f172a 100%)',
        'glow_css' => '
            radial-gradient(ellipse 900px 500px at 15% 0%, rgba(16, 185, 129, 0.15), transparent 65%),
            radial-gradient(ellipse 800px 450px at 85% 10%, rgba(20, 184, 166, 0.12), transparent 60%),
            radial-gradient(ellipse 600px 400px at 50% 90%, rgba(52, 211, 153, 0.08), transparent 55%)
        ',
        'btn_accent' => 'bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 shadow-lg shadow-emerald-600/30'
    ],
    'dark-slate' => [
        'bg_gradient' => 'linear-gradient(160deg, #0f172a 0%, #1e293b 30%, #334155 60%, #0f172a 100%)',
        'glow_css' => '
            radial-gradient(ellipse 900px 500px at 15% 0%, rgba(148, 163, 184, 0.15), transparent 65%),
            radial-gradient(ellipse 800px 450px at 85% 10%, rgba(100, 116, 139, 0.12), transparent 60%),
            radial-gradient(ellipse 600px 400px at 50% 90%, rgba(71, 85, 105, 0.08), transparent 55%)
        ',
        'btn_accent' => 'bg-slate-700 hover:bg-slate-650 active:bg-slate-800 shadow-lg shadow-slate-700/30'
    ]
];
$activeTheme = $themes[$themeColorKey] ?? $themes['dark-blue'];
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($branding['system_name']); ?> | <?= htmlspecialchars($branding['college_name']); ?></title>
    <?php if (!empty($branding['logo_path'])): ?>
        <link rel="icon" type="image/png" href="<?= htmlspecialchars($branding['logo_path']); ?>">
    <?php endif; ?>
    <meta name="description" content="พอร์ทัลกลางสำหรับส่งและติดตามงานโครงการสอน แผนการจัดการเรียนรู้ และสื่อการเรียนการสอน ออนไลน์">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&family=Outfit:wght@400;600;850&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        indigo: {
                            750: '#312e81',
                        },
                        teal: {
                            150: '#ccfbf1',
                            650: '#0e7490',
                            750: '#0f766e',
                        }
                    },
                    fontFamily: {
                        thai: ['"IBM Plex Sans Thai"', 'sans-serif'],
                        outfit: ['"Outfit"', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'IBM Plex Sans Thai', 'Outfit', sans-serif; }
        .dark-glow {
            background: <?= $activeTheme['glow_css']; ?>;
        }
        @keyframes pulse-soft {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(0.96); }
        }
        .pulse-dot { animation: pulse-soft 2s infinite ease-in-out; }
        .card-syllabus { background: linear-gradient(135deg, #faf5ff 0%, #eef2ff 50%, #f5f3ff 100%); }
        .card-lesson   { background: linear-gradient(135deg, #fffbeb 0%, #fff7ed 50%, #fef2f2 100%); }
        .card-material { background: linear-gradient(135deg, #f0fdfa 0%, #ecfeff 50%, #eff6ff 100%); }
    </style>
</head>
<body class="text-white font-thai min-h-screen flex flex-col dark-glow relative overflow-x-hidden" style="background: <?= $activeTheme['bg_gradient']; ?>;">

<!-- Subtle decorative background pattern -->
<div class="absolute inset-0 opacity-[0.03] pointer-events-none" style="background-image: url('data:image/svg+xml,%3Csvg width=&quot;60&quot; height=&quot;60&quot; viewBox=&quot;0 0 60 60&quot; xmlns=&quot;http://www.w3.org/2000/svg&quot;%3E%3Cpath d=&quot;M54 0v54H0v6h60V0h-6z&quot; fill=&quot;%2394a3b8&quot; fill-opacity=&quot;0.5&quot;/%3E%3C/svg%3E');"></div>

<!-- Top Navigation Header -->
<header class="w-full sticky top-0 z-50 bg-slate-900/80 backdrop-blur-xl border-b border-white/10">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-20">
            <a href="index.php" class="flex items-center gap-3.5 group no-underline">
                <?php if (!empty($branding['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($branding['logo_path']); ?>" class="w-11 h-11 object-contain rounded-2xl shadow-lg shadow-slate-900/10 group-hover:scale-105 transition-transform duration-200">
                <?php else: ?>
                    <div class="w-11 h-11 rounded-2xl bg-slate-900 text-white font-extrabold text-base flex items-center justify-center tracking-wider shadow-lg shadow-slate-900/10 group-hover:scale-105 transition-transform duration-200">
                        <?= htmlspecialchars($branding['logo_text'] ?? 'PNP'); ?>
                    </div>
                <?php endif; ?>
                <div>
                    <div class="text-base font-black text-white tracking-wide leading-tight group-hover:text-indigo-400 transition-colors"><?= htmlspecialchars($branding['system_name']); ?></div>
                    <div class="text-[10px] text-slate-400 font-medium uppercase tracking-wider mt-0.5"><?= htmlspecialchars($branding['college_name']); ?></div>
                </div>
            </a>
            <div>
                <?php if ($isLoggedIn): ?>
                    <div class="flex items-center gap-2">
                        <a href="<?= $dashboardUrl; ?>"
                           class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-white/10 hover:bg-white/20 active:bg-white/25 text-white text-xs font-bold rounded-xl transition-all border border-white/15">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
                            <span>แดชบอร์ด</span>
                        </a>
                        <a href="login.php?logout=1"
                           class="inline-flex items-center justify-center gap-2 px-3.5 py-2.5 border border-white/15 hover:bg-white/10 text-slate-300 text-xs font-semibold rounded-xl transition">
                            ออกจากระบบ
                        </a>
                    </div>
                <?php else: ?>
                    <a href="<?= $loginUrl; ?>"
                       class="inline-flex items-center justify-center gap-2 px-5 py-2.5 <?= $activeTheme['btn_accent']; ?> text-white text-xs font-black rounded-xl transition-all shadow-lg">
                        <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/></svg>
                        <span>เข้าสู่ระบบ</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>

<main class="flex-1 max-w-6xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-16">
    <!-- Systems Grid -->
    <div class="grid gap-6 md:grid-cols-3">
        <?php
        $cards = [
            'course_syllabus' => [
                'title' => 'ระบบส่งโครงการสอน',
                'color_theme' => 'from-indigo-600 via-indigo-700 to-violet-600',
                'color_bg' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                'card_class' => 'card-syllabus border-indigo-100/80',
                'shadow_glow' => 'rgba(79,70,229,0.10)',
                'icon' => '<svg class="w-8 h-8 text-indigo-650" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 9h3m-3 3h3m-3 3h3"/>
                </svg>'
            ],
            'lesson_plan' => [
                'title' => 'ระบบส่งแผนการจัดการเรียนรู้',
                'color_theme' => 'from-amber-500 via-orange-600 to-rose-500',
                'color_bg' => 'bg-amber-50 text-amber-700 border-amber-200',
                'card_class' => 'card-lesson border-amber-100/80',
                'shadow_glow' => 'rgba(217,119,6,0.10)',
                'icon' => '<svg class="w-8 h-8 text-amber-600" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5"/>
                </svg>'
            ],
            'teaching_materials' => [
                'title' => 'ระบบส่งสื่อการเรียนการสอน',
                'color_theme' => 'from-teal-500 via-cyan-600 to-blue-600',
                'color_bg' => 'bg-teal-50 text-teal-700 border-teal-200',
                'card_class' => 'card-material border-teal-100/80',
                'shadow_glow' => 'rgba(13,148,136,0.10)',
                'icon' => '<svg class="w-8 h-8 text-teal-600" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/>
                </svg>'
            ]
        ];
        ?>
        <?php foreach ($cards as $type => $info): ?>
            <?php
            $setting = $systemSettings[$type] ?? null;
            $isOpen = $setting ? (int) $setting['is_open'] === 1 : false;
            $deadline = $setting && $setting['deadline_date'] ? date('d/m/Y H:i', strtotime($setting['deadline_date'])) : '-';

            // Dynamic label and target link logic
            $btnLabel = 'เข้าสู่ระบบ';
            $btnUrl = $loginUrl;

            if ($isLoggedIn) {
                $userRole = current_user_role();
                if ($userRole === 'teacher') {
                    $btnUrl = 'teacher/submit.php?system_type=' . $type;
                    if ($type === 'course_syllabus') {
                        $btnLabel = 'ส่งโครงการสอน';
                    } elseif ($type === 'lesson_plan') {
                        $btnLabel = 'ส่งแผนการจัดการเรียนรู้';
                    } elseif ($type === 'teaching_materials') {
                        $btnLabel = 'ส่งสื่อการสอน';
                    }
                } elseif ($userRole === 'admin') {
                    $btnUrl = 'admin/overview.php';
                    if ($type === 'course_syllabus') {
                        $btnLabel = 'ตรวจโครงการสอน';
                    } elseif ($type === 'lesson_plan') {
                        $btnLabel = 'ตรวจแผนการจัดการเรียนรู้';
                    } elseif ($type === 'teaching_materials') {
                        $btnLabel = 'ตรวจสื่อการสอน';
                    }
                }
            }
            ?>
            <!-- Interactive Floating Card (Vibrant Light Mode) -->
            <div class="group <?= $info['card_class']; ?> border rounded-[24px] p-6 flex flex-col justify-between hover:shadow-xl hover:shadow-slate-300/60 transition-all duration-300 hover:-translate-y-2 relative overflow-hidden text-center"
                 style="box-shadow: 0 12px 28px -10px rgba(148, 163, 184, 0.16), 0 0 40px 0 <?= $info['shadow_glow']; ?>;">
                
                <!-- Glowing Top Background Accent -->
                <div class="absolute -top-10 -left-10 w-32 h-32 bg-gradient-to-br <?= $info['color_theme']; ?> rounded-full blur-[45px] opacity-[0.10] group-hover:opacity-[0.15] group-hover:scale-125 transition-all duration-500"></div>
                <div class="absolute -bottom-16 -right-16 w-36 h-36 bg-gradient-to-tl <?= $info['color_theme']; ?> rounded-full blur-[55px] opacity-[0.05] group-hover:opacity-[0.08] transition-all duration-500"></div>

                <div class="flex flex-col items-center">
                    <!-- Status Indicator at the Top Center -->
                    <div class="w-full flex justify-center mb-4 relative z-10">
                        <?php if ($isOpen): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-green-50 text-green-700 border border-green-200/85 shadow-sm leading-none">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-500 pulse-dot"></span>
                                <span>เปิดระบบ</span>
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-red-50 text-red-700 border border-red-200/85 shadow-sm leading-none">
                                <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                                <span>ปิดระบบ</span>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Centered Larger Illustrative Icon -->
                    <div class="w-16 h-16 rounded-2xl <?= $info['color_bg']; ?> border flex items-center justify-center shadow-md group-hover:scale-110 group-hover:shadow-lg transition-all duration-300 mb-4 relative z-10">
                        <?= $info['icon']; ?>
                    </div>

                    <!-- Centered System Name Only (Clean) -->
                    <h2 class="text-sm sm:text-base md:text-lg font-black text-slate-800 tracking-tight group-hover:text-indigo-600 transition-colors relative z-10 mb-1.5 whitespace-nowrap">
                        <?= $info['title']; ?>
                    </h2>
                </div>

                <!-- Live Status Deadline Info Area -->
                <div class="mt-5 border-t border-slate-100 pt-5">
                    <?php if ($isOpen): ?>
                        <div class="bg-slate-50/70 rounded-2xl p-3 border border-slate-150 mb-5 shadow-inner text-center">
                            <span class="text-[9px] font-extrabold text-slate-400 uppercase tracking-widest block mb-1 font-thai">ระยะเวลากำหนดส่ง</span>
                            <span class="text-xs sm:text-sm font-black text-slate-800 flex items-center justify-center gap-1">
                                <svg class="w-3 h-3 shrink-0 text-slate-500" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span class="text-sm sm:text-base font-black"><?= $deadline; ?> น.</span>
                            </span>
                            
                            <?php if ($setting && $setting['deadline_date']): ?>
                                <!-- Countdown Timer Blocks -->
                                <div class="mt-3 flex items-center justify-center gap-1" id="countdown-<?= $type; ?>" data-deadline="<?= date('c', strtotime($setting['deadline_date'])); ?>">
                                    <div class="flex flex-col items-center min-w-[42px] bg-white border border-slate-200 rounded-xl p-1.5 shadow-sm">
                                        <span class="text-base sm:text-lg font-extrabold text-slate-800 leading-none day-val">00</span>
                                        <span class="text-[8px] font-bold text-slate-400 uppercase tracking-widest mt-1 font-thai">วัน</span>
                                    </div>
                                    <span class="text-slate-400 font-extrabold leading-none text-base">:</span>
                                    <div class="flex flex-col items-center min-w-[42px] bg-white border border-slate-200 rounded-xl p-1.5 shadow-sm">
                                        <span class="text-base sm:text-lg font-extrabold text-slate-800 leading-none hour-val">00</span>
                                        <span class="text-[8px] font-bold text-slate-400 uppercase tracking-widest mt-1 font-thai">ชม.</span>
                                    </div>
                                    <span class="text-slate-400 font-extrabold leading-none text-base">:</span>
                                    <div class="flex flex-col items-center min-w-[42px] bg-white border border-slate-200 rounded-xl p-1.5 shadow-sm">
                                        <span class="text-base sm:text-lg font-extrabold text-slate-800 leading-none min-val">00</span>
                                        <span class="text-[8px] font-bold text-slate-400 uppercase tracking-widest mt-1 font-thai">นาที</span>
                                    </div>
                                    <span class="text-slate-400 font-extrabold leading-none text-base">:</span>
                                    <div class="flex flex-col items-center min-w-[42px] bg-white border border-slate-200 rounded-xl p-1.5 shadow-sm">
                                        <span class="text-base sm:text-lg font-extrabold text-slate-800 leading-none sec-val">00</span>
                                        <span class="text-[8px] font-bold text-slate-400 uppercase tracking-widest mt-1 font-thai">วิ</span>
                                    </div>
                                </div>
                                
                                <div class="mt-3 hidden text-[11px] font-black text-amber-700 bg-amber-50 border border-amber-200/75 rounded-xl py-2 px-3 items-center justify-center gap-1.5" id="passed-<?= $type; ?>">
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse shrink-0"></span>
                                    <span class="font-thai">เลยกำหนดส่งแล้ว (บันทึกส่งล่าช้า)</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-slate-50/70 rounded-2xl p-3 border border-slate-150 mb-5 text-slate-400 shadow-inner text-center">
                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest block mb-1 font-thai">ข้อความแจ้งเตือน</span>
                            <span class="text-xs font-semibold flex items-center justify-center gap-1.5">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                <span class="font-thai">ปิดรับยื่นส่งเอกสารชั่วคราว</span>
                            </span>
                        </div>
                    <?php endif; ?>

                    <!-- Action Trigger Link Button -->
                    <a href="<?= $btnUrl; ?>"
                       class="w-full inline-flex items-center justify-center gap-2 py-3 bg-gradient-to-r <?= $info['color_theme']; ?> text-white text-xs font-black rounded-xl hover:shadow-lg active:scale-[0.97] transition-all duration-150 shadow-md">
                        <span><?= $btnLabel; ?></span>
                        <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform duration-200" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<!-- Unified Bottom Footer -->
<footer class="mt-auto py-8 border-t border-white/10">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <p class="text-center text-xs text-slate-400 font-light">&copy; <?= date('Y'); ?> <?= htmlspecialchars($branding['college_name']); ?> &middot; ฝ่ายวิชาการ &middot; สงวนลิขสิทธิ์</p>
    </div>
</footer>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const countdowns = document.querySelectorAll('[id^="countdown-"]');
        
        function updateTimers() {
            const now = new Date().getTime();
            
            countdowns.forEach(el => {
                const deadlineStr = el.getAttribute('data-deadline');
                if (!deadlineStr) return;
                
                const deadline = new Date(deadlineStr).getTime();
                const diff = deadline - now;
                
                const type = el.id.replace('countdown-', '');
                const passedEl = document.getElementById('passed-' + type);
                
                if (diff > 0) {
                    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                    const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                    
                    el.querySelector('.day-val').textContent = String(days).padStart(2, '0');
                    el.querySelector('.hour-val').textContent = String(hours).padStart(2, '0');
                    el.querySelector('.min-val').textContent = String(minutes).padStart(2, '0');
                    el.querySelector('.sec-val').textContent = String(seconds).padStart(2, '0');
                    
                    el.classList.remove('hidden');
                    el.classList.add('flex');
                    if (passedEl) {
                        passedEl.classList.add('hidden');
                        passedEl.classList.remove('flex');
                    }
                } else {
                    el.classList.add('hidden');
                    el.classList.remove('flex');
                    if (passedEl) {
                        passedEl.classList.remove('hidden');
                        passedEl.classList.add('flex');
                    }
                }
            });
        }
        
        updateTimers();
        setInterval(updateTimers, 1000);
    });
</script>

</body>
</html>
