<?php
declare(strict_types=1);

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once __DIR__ . '/config.php';

$title = "เครื่องมือตรวจสอบการเชื่อมต่อฐานข้อมูล | " . htmlspecialchars(get_branding_settings()['system_name']);
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&family=Outfit:wght@400;600;850&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'IBM Plex Sans Thai', 'Outfit', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex items-center justify-center p-4">

<main class="w-full max-w-2xl bg-white border border-slate-200/80 rounded-[32px] p-6 sm:p-10 shadow-xl shadow-slate-100/50">
    <div class="text-center mb-8">
        <div class="w-16 h-16 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center mx-auto mb-4 border border-indigo-100 shadow-sm">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.75 9.75v10.5a2 2 0 002 2h10.5a2 2 0 002-2V9.75M4.75 9.75c0-1.242 1.343-2.25 3-2.25h8.5c1.657 0 3 1.008 3 2.25M4.75 9.75a3 3 0 011.5-2.598m0 0A3.003 3.003 0 0012 3m0 0a3.003 3.003 0 005.75 4.152M12 3v13.5" />
            </svg>
        </div>
        <h1 class="text-xl font-bold text-slate-800">เครื่องมือทดสอบการเชื่อมต่อฐานข้อมูล</h1>
        <p class="text-xs text-slate-400 font-medium mt-1">ใช้ตรวจสอบค่ากำหนดการเชื่อมต่อของเซิร์ฟเวอร์ Hostinger</p>
    </div>

    <!-- DB Settings Info -->
    <div class="bg-slate-50 rounded-2xl p-5 border border-slate-200/50 mb-6">
        <h2 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">ค่าที่ใช้เชื่อมต่อปัจจุบัน (จาก config.php)</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs sm:text-sm font-semibold">
            <div class="flex flex-col gap-1">
                <span class="text-slate-400 font-medium text-[10px] uppercase">Database Host</span>
                <span class="text-slate-700 bg-white border border-slate-100 px-3 py-2 rounded-xl"><?= htmlspecialchars(DB_HOST); ?></span>
            </div>
            <div class="flex flex-col gap-1">
                <span class="text-slate-400 font-medium text-[10px] uppercase">Database Name</span>
                <span class="text-slate-700 bg-white border border-slate-100 px-3 py-2 rounded-xl"><?= htmlspecialchars(DB_NAME); ?></span>
            </div>
            <div class="flex flex-col gap-1">
                <span class="text-slate-400 font-medium text-[10px] uppercase">Database User</span>
                <span class="text-slate-700 bg-white border border-slate-100 px-3 py-2 rounded-xl"><?= htmlspecialchars(DB_USER); ?></span>
            </div>
            <div class="flex flex-col gap-1">
                <span class="text-slate-400 font-medium text-[10px] uppercase">Password Status</span>
                <span class="text-slate-700 bg-white border border-slate-100 px-3 py-2 rounded-xl">
                    <?= DB_PASS !== '' ? '•••••••• (' . strlen(DB_PASS) . ' ตัวอักษร)' : 'ไม่ได้ตั้งรหัสผ่าน'; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Connection Test Status -->
    <div class="border rounded-2xl p-6 mb-6">
        <h2 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">ผลการทดลองเชื่อมต่อด้วย PDO</h2>
        <?php
        try {
            $test_dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
            $test_pdo = new PDO($test_dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5 // Timeout after 5 seconds
            ]);
            
            // Connection Success Layout
            ?>
            <div class="flex items-start gap-3 text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-xs sm:text-sm font-bold">
                <svg class="w-5 h-5 text-emerald-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <div>
                    <span class="block text-base font-extrabold mb-1">เชื่อมต่อสำเร็จ! (Success)</span>
                    <span class="text-xs font-medium text-emerald-600">แอปพลิเคชันสามารถติดต่อ MySQL Database บนเซิร์ฟเวอร์นี้ได้อย่างสมบูรณ์แบบ ข้อมูลการเชื่อมต่อถูกต้อง 100%</span>
                </div>
            </div>
            
            <div class="mt-4 border-t border-slate-100 pt-4 text-xs font-medium text-slate-500">
                <span class="block mb-2 font-bold text-[10px] text-slate-400 uppercase">สแกนตรวจสอบตารางข้อมูลเบื้องต้น:</span>
                <div class="flex flex-wrap gap-1.5 mt-1.5">
                    <?php
                    $tables = $test_pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                    if (empty($tables)) {
                        echo "<span class='px-2.5 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-md text-[10px] font-bold'>⚠️ ฐานข้อมูลว่างเปล่า (ยังไม่มีตารางงาน)</span>";
                    } else {
                        foreach ($tables as $t) {
                            $rowsCount = $test_pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
                            echo "<span class='px-2.5 py-1 bg-slate-100 text-slate-600 border border-slate-200 rounded-md text-[10px] font-bold'>$t ($rowsCount แถว)</span>";
                        }
                    }
                    ?>
                </div>
            </div>
            <?php
        } catch (PDOException $e) {
            // Connection Failed Layout
            $error_code = $e->getCode();
            $error_msg = $e->getMessage();
            ?>
            <div class="flex items-start gap-3 text-rose-700 bg-rose-50 border border-rose-200 rounded-xl p-4 text-xs sm:text-sm font-bold">
                <svg class="w-5 h-5 text-rose-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <div>
                    <span class="block text-base font-extrabold mb-1">การเชื่อมต่อล้มเหลว (Connection Failed)</span>
                    <span class="text-xs font-semibold text-rose-600 block mb-2 leading-relaxed">
                        ระบบไม่สามารถจับคู่เชื่อมต่อกับฐานข้อมูลได้เนื่องจากมีข้อมูลไม่ถูกต้อง หรือเซิร์ฟเวอร์ MySQL ปิดกั้นสิทธิ์การเข้าถึง
                    </span>
                    <div class="bg-rose-900/10 border border-rose-200/50 rounded-lg p-3 mt-3 text-xs font-mono font-bold text-rose-800 break-all">
                        SQLSTATE Code: <?= htmlspecialchars((string)$error_code); ?><br>
                        Error Details: <?= htmlspecialchars($error_msg); ?>
                    </div>
                </div>
            </div>
            
            <div class="mt-4 border-t border-slate-100 pt-4 text-xs font-medium text-slate-500 leading-relaxed">
                <span class="block mb-2 font-bold text-[10px] text-slate-400 uppercase">💡 ข้อแนะนำในการแก้ไขจุดเชื่อมต่อ:</span>
                <ul class="list-disc pl-5 gap-1 flex flex-col mt-1">
                    <li>หากเออเรอร์ระบุว่า <code class="bg-slate-100 px-1 rounded font-bold">Access denied for user...</code>: กรุณาตรวจสอบรหัสผ่าน <code class="bg-slate-100 px-1 rounded font-bold">DB_PASS</code> ในไฟล์ <code class="bg-slate-100 px-1 rounded font-bold">config.php</code> และสิทธิ์ผู้ใช้ของฐานข้อมูลในแผง hPanel</li>
                    <li>หากเออเรอร์ระบุว่า <code class="bg-slate-100 px-1 rounded font-bold">Unknown database...</code>: แสดงว่าชื่อฐานข้อมูล <code class="bg-slate-100 px-1 rounded font-bold">DB_NAME</code> มีการกรอกตัวอักษรไม่ตรงหรือยังไม่สร้างตารางจริง</li>
                    <li>หากเออเรอร์ระบุว่า <code class="bg-slate-100 px-1 rounded font-bold">Can't connect to local MySQL server...</code>: แสดงว่า Hostinger ตัวนี้อาจจะใช้ Hostname ที่ไม่ใช่ localhost (เช่น มีระบุโฮสต์พิเศษในหน้าฐานข้อมูล MySQL ของ Hostinger)</li>
                </ul>
            </div>
            <?php
        }
        ?>
    </div>

    <!-- Actions -->
    <div class="flex flex-col sm:flex-row justify-between items-center gap-3 border-t border-slate-150 pt-6">
        <span class="text-[10px] font-bold text-slate-400 uppercase font-outfit">Environment Host: <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'Unknown'); ?></span>
        <a href="index.php" class="px-5 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold rounded-xl transition shadow-md shadow-slate-900/10">
            ย้อนกลับไปหน้าแรก
        </a>
    </div>
</main>

</body>
</html>
