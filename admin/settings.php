<?php
declare(strict_types=1);

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/auth.php';

// Force admin role
require_admin();

// 1. Get active semester
$semester = $pdo->query('SELECT id, semester_name FROM semesters WHERE is_active = 1 LIMIT 1')->fetch();
if (!$semester) {
    exit('ไม่พบภาคเรียนที่กำลังเปิดใช้งานในระบบ กรุณาติดต่อผู้ดูแลระบบ');
}
$branding = get_branding_settings();

$errorMessage = '';
$successMessage = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verify_csrf_token($csrfToken)) {
        $errorMessage = 'CSRF token ไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $action = $_POST['action'] ?? 'save_settings';
        
        if ($action === 'new_semester') {
            $newSemesterName = trim((string)($_POST['new_semester_name'] ?? ''));
            
            if ($newSemesterName === '') {
                $errorMessage = 'กรุณาระบุชื่อภาคเรียนใหม่ (เช่น 2/2569)';
            } else {
                try {
                    $pdo->beginTransaction();
                    
                    // Check duplicate
                    $stmt = $pdo->prepare('SELECT id FROM semesters WHERE semester_name = :name LIMIT 1');
                    $stmt->execute(['name' => $newSemesterName]);
                    if ($stmt->fetch()) {
                        throw new Exception("ภาคเรียนชื่อ '{$newSemesterName}' มีอยู่ในระบบเรียบร้อยแล้ว");
                    }
                    
                    // Mark old semesters inactive
                    $pdo->exec('UPDATE semesters SET is_active = 0');
                    
                    // Insert new active semester
                    $stmt = $pdo->prepare('INSERT INTO semesters (semester_name, is_active) VALUES (:name, 1)');
                    $stmt->execute(['name' => $newSemesterName]);
                    $newSemesterId = (int)$pdo->lastInsertId();
                    
                    // Data is now frozen (old semester is inactive).
                    // We no longer TRUNCATE tables.
                    
                    // Initialize system settings for the new semester
                    $defaultDeadlines = [
                        'course_syllabus' => date('Y-m-d 23:59:59', strtotime('+1 month')),
                        'lesson_plan' => date('Y-m-d 23:59:59', strtotime('+1.5 month')),
                        'teaching_materials' => date('Y-m-d 23:59:59', strtotime('+2 month'))
                    ];
                    
                    $stmt = $pdo->prepare('INSERT INTO system_settings (system_type, semester_id, deadline_date, is_open) VALUES (:type, :sem_id, :deadline, 1)');
                    foreach ($defaultDeadlines as $type => $deadline) {
                        $stmt->execute([
                            'type' => $type,
                            'sem_id' => $newSemesterId,
                            'deadline' => $deadline
                        ]);
                    }
                    
                    $pdo->commit();
                    
                    // Refresh current active semester context
                    $semester = [
                        'id' => $newSemesterId,
                        'semester_name' => $newSemesterName
                    ];
                    
                    $successMessage = "เริ่มต้นภาคเรียนใหม่ '{$newSemesterName}' สำเร็จเรียบร้อยแล้ว! ข้อมูลการจัดสอนและประวัติการส่งงานเทอมเก่าถูกแช่แข็งไว้แล้ว พร้อมสำหรับการนำเข้าแผนงานสำหรับเทอมใหม่ครับ";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errorMessage = $e->getMessage();
                }
            }
        } elseif ($action === 'garbage_collect') {
            try {
                $deletedFilesCount = 0;
                $freedSpace = 0;
                
                // 1. Fetch all valid file_paths from submissions
                $stmt = $pdo->query("SELECT file_path FROM submissions WHERE file_path IS NOT NULL AND file_path != ''");
                $validFiles = [];
                while ($row = $stmt->fetch()) {
                    $validFiles[] = ltrim($row['file_path'], '/');
                }
                
                // 2. Scan uploads directory recursively (excluding uploads/branding)
                $uploadDir = dirname(__DIR__) . '/uploads';
                if (is_dir($uploadDir)) {
                    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS));
                    foreach ($iterator as $file) {
                        if ($file->isFile()) {
                            $realPath = $file->getRealPath();
                            // Skip branding folder
                            if (strpos($realPath, DIRECTORY_SEPARATOR . 'branding' . DIRECTORY_SEPARATOR) !== false) continue;
                            
                            // Calculate relative path to match DB format (e.g. uploads/1/...)
                            $relativePath = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $realPath);
                            $relativePath = str_replace('\\', '/', $relativePath);
                            
                            // If not in database, delete it
                            if (!in_array($relativePath, $validFiles)) {
                                $freedSpace += filesize($realPath);
                                @unlink($realPath);
                                $deletedFilesCount++;
                            }
                        }
                    }
                }
                
                $freedMB = round($freedSpace / 1048576, 2);
                $successMessage = "ดำเนินการล้างไฟล์ขยะเสร็จสิ้น! ลบไฟล์เอกสารที่ไม่ได้ถูกอ้างอิงไปจำนวน {$deletedFilesCount} ไฟล์ ได้คืนพื้นที่ว่าง {$freedMB} MB";
            } catch (Exception $e) {
                $errorMessage = "เกิดข้อผิดพลาดในการล้างไฟล์ขยะ: " . $e->getMessage();
            }
        } elseif ($action === 'save_branding') {
            $systemName = trim((string)($_POST['system_name'] ?? ''));
            $collegeName = trim((string)($_POST['college_name'] ?? ''));
            $logoText = trim((string)($_POST['logo_text'] ?? ''));
            $deleteLogo = isset($_POST['delete_logo']) ? 1 : 0;
            
            try {
                if ($systemName === '' || $collegeName === '') {
                    throw new Exception("กรุณากรอกชื่อระบบและชื่อวิทยาลัยให้ครบถ้วน");
                }
                
                $currentBranding = get_branding_settings();
                $logoPath = $currentBranding['logo_path'];
                
                // Handle Delete Logo
                if ($deleteLogo) {
                    if (!empty($logoPath) && file_exists(dirname(__DIR__) . '/' . $logoPath)) {
                        @unlink(dirname(__DIR__) . '/' . $logoPath);
                    }
                    $logoPath = '';
                }
                
                // Handle Upload Logo
                if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                    $fileTmpPath = $_FILES['logo_file']['tmp_name'];
                    $fileName = $_FILES['logo_file']['name'];
                    $fileSize = $_FILES['logo_file']['size'];
                    $fileType = $_FILES['logo_file']['type'];
                    
                    // Validate image type
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml'];
                    if (!in_array($fileType, $allowedTypes)) {
                        throw new Exception("รูปแบบไฟล์โลโก้ไม่ถูกต้อง อนุญาตเฉพาะ PNG, JPG, GIF และ SVG เท่านั้น");
                    }
                    
                    $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
                    $newFileName = 'logo_' . time() . '.' . $fileExtension;
                    
                    $uploadFileDir = dirname(__DIR__) . '/uploads/branding/';
                    if (!is_dir($uploadFileDir)) {
                        mkdir($uploadFileDir, 0777, true);
                    }
                    
                    $destPath = $uploadFileDir . $newFileName;
                    if (move_uploaded_file($fileTmpPath, $destPath)) {
                        // Delete old file if upload was successful
                        if (!empty($logoPath) && file_exists(dirname(__DIR__) . '/' . $logoPath)) {
                            @unlink(dirname(__DIR__) . '/' . $logoPath);
                        }
                        $logoPath = 'uploads/branding/' . $newFileName;
                    } else {
                        throw new Exception("เกิดข้อผิดพลาดในการบันทึกไฟล์โลโก้บนเซิร์ฟเวอร์");
                    }
                }
                
                // Save to database
                $stmt = $pdo->prepare("INSERT INTO branding_settings (meta_key, meta_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE meta_value = :value2");
                
                $stmt->execute(['key' => 'system_name', 'value' => $systemName, 'value2' => $systemName]);
                $stmt->execute(['key' => 'college_name', 'value' => $collegeName, 'value2' => $collegeName]);
                $stmt->execute(['key' => 'logo_text', 'value' => $logoText, 'value2' => $logoText]);
                $stmt->execute(['key' => 'logo_path', 'value' => $logoPath, 'value2' => $logoPath]);
                
                $themeColor = trim((string)($_POST['theme_color'] ?? 'dark-blue'));
                if (!in_array($themeColor, ['dark-blue', 'dark-purple', 'dark-emerald', 'dark-slate'])) {
                    $themeColor = 'dark-blue';
                }
                $stmt->execute(['key' => 'theme_color', 'value' => $themeColor, 'value2' => $themeColor]);
                
                // Force reload of static get_branding_settings
                $branding = get_branding_settings(true);
                
                $successMessage = 'บันทึกการตั้งค่ารูปลักษณ์และข้อมูลระบบสำเร็จเรียบร้อยแล้ว';
            } catch (Exception $e) {
                $errorMessage = $e->getMessage();
            }
        } else {
            // Save standard settings (the existing logic)
            $types = ['course_syllabus', 'lesson_plan', 'teaching_materials'];
            
            try {
                $pdo->beginTransaction();
                
                foreach ($types as $type) {
                    $isOpen = isset($_POST["is_open_{$type}"]) ? 1 : 0;
                    $deadlineInput = $_POST["deadline_{$type}"] ?? '';
                    
                    if ($deadlineInput === '') {
                        throw new Exception("กรุณากรอกวันเวลาสิ้นสุดกำหนดส่งของแต่ละประเภทระบบส่งงานให้ครบถ้วน");
                    }
                    
                    // Convert datetime-local value (YYYY-MM-DDTHH:MM) to MySQL DATETIME (YYYY-MM-DD HH:MM:SS)
                    $deadlineDate = date('Y-m-d H:i:s', strtotime($deadlineInput));
                    
                    $stmt = $pdo->prepare("
                        UPDATE system_settings
                        SET deadline_date = :deadline_date, is_open = :is_open
                        WHERE system_type = :system_type AND semester_id = :semester_id
                    ");
                    $stmt->execute([
                        'deadline_date' => $deadlineDate,
                        'is_open' => $isOpen,
                        'system_type' => $type,
                        'semester_id' => $semester['id']
                    ]);
                }
                
                $pdo->commit();
                $successMessage = 'บันทึกการตั้งค่ากำหนดเวลาและเปิดระบบย่อยสำเร็จเรียบร้อยแล้ว';
            } catch (Exception $e) {
                $pdo->rollBack();
                $errorMessage = $e->getMessage();
            }
        }
    }
}

// Fetch current settings
$stmt = $pdo->prepare('SELECT system_type, deadline_date, is_open FROM system_settings WHERE semester_id = :semester_id');
$stmt->execute(['semester_id' => $semester['id']]);
$settings = [];
foreach ($stmt->fetchAll() as $row) {
    $settings[$row['system_type']] = $row;
}

$systemTypeLabels = [
    'course_syllabus' => 'ระบบส่งโครงการสอน (Syllabus)',
    'lesson_plan' => 'ระบบส่งแผนการจัดการเรียนรู้ (Lesson Plan)',
    'teaching_materials' => 'ระบบส่งสื่อการเรียนการสอน (Teaching Materials)'
];
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ตั้งค่าระบบและรูปลักษณ์ | <?= htmlspecialchars($branding['system_name']); ?></title>
    <?php if (!empty($branding['logo_path'])): ?>
        <link rel="icon" type="image/png" href="../<?= htmlspecialchars($branding['logo_path']); ?>">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&family=Outfit:wght@400;600;850&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        teal: {
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
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-thai min-h-screen flex flex-col relative overflow-x-hidden">

<header class="w-full sticky top-0 z-50 bg-gradient-to-r from-indigo-50/80 via-white/95 to-teal-50/80 backdrop-blur-md border-b border-slate-200/85 shadow-sm">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-20">
            <a href="overview.php" class="flex items-center gap-3 group no-underline">
                <?php if (!empty($branding['logo_path'])): ?>
                    <img src="../<?= htmlspecialchars($branding['logo_path']); ?>" class="w-10 h-10 object-contain rounded-xl shadow-md group-hover:scale-105 transition-transform duration-200">
                <?php else: ?>
                    <div class="w-10 h-10 rounded-2xl bg-slate-900 text-white font-extrabold text-xs flex items-center justify-center tracking-wider">
                        <?= htmlspecialchars($branding['logo_text']); ?>
                    </div>
                <?php endif; ?>
                <div>
                    <div class="text-base font-black text-slate-800 tracking-wide leading-tight"><?= htmlspecialchars($branding['system_name']); ?></div>
                    <div class="text-[10px] text-slate-400 font-medium uppercase tracking-wider mt-0.5"><?= htmlspecialchars($branding['college_name']); ?></div>
                </div>
            </a>
            
            <nav class="hidden md:flex items-center gap-6 text-xs font-bold text-slate-500">
                <a href="overview.php" class="hover:text-slate-800">ภาพรวมความคืบหน้า</a>
                <a href="settings.php" class="text-teal-700 font-black">ตั้งค่าเดดไลน์ระบบ</a>
                <a href="import.php" class="hover:text-slate-800">นำเข้าบุคลากร (CSV)</a>
                <a href="history.php" class="hover:text-slate-800 border-l border-slate-300 pl-6">ประวัติย้อนหลัง</a>
            </nav>
            
            <a href="overview.php"
               class="inline-flex items-center justify-center gap-2 px-4 py-2 border border-slate-200 hover:bg-slate-50 text-slate-600 hover:text-slate-800 text-xs font-semibold rounded-xl transition">
                กลับแดชบอร์ด
            </a>
        </div>
    </div>
</header>

<main class="flex-1 max-w-4xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="mb-8">
        <h1 class="text-xl sm:text-2xl font-black text-slate-800">ตั้งค่าช่วงเวลากำหนดส่งและเปิดรับงาน</h1>
        <p class="text-xs text-slate-400 font-medium mt-1">
            ภาคเรียนที่ <span class="text-indigo-650 font-bold"><?= e($semester['semester_name']); ?></span> &middot; กำหนดวันเวลาสิ้นสุด และสวิตช์เปิดปิดของ 3 ระบบงานวิชาการย่อยเพื่อควบคุมพฤติกรรมครูผู้ส่ง
        </p>
    </div>

    <?php if ($errorMessage): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-2xl p-4 text-xs sm:text-sm font-bold mb-6 flex items-center gap-2">
            <svg class="w-5 h-5 text-rose-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span><?= e($errorMessage); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($successMessage): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 rounded-2xl p-4 text-xs sm:text-sm font-bold mb-6 flex items-center gap-2">
            <svg class="w-5 h-5 text-green-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span><?= e($successMessage); ?></span>
        </div>
    <?php endif; ?>

    <div class="bg-white border border-slate-200 rounded-[32px] p-6 sm:p-10 shadow-sm">
        
        <form method="post" action="settings.php" class="space-y-8">
            <input type="hidden" name="csrf_token" value="<?= e(create_csrf_token()); ?>">
            
            <div class="space-y-6">
                <?php foreach ($systemTypeLabels as $typeKey => $typeLabel): ?>
                    <?php 
                    $setting = $settings[$typeKey] ?? ['deadline_date' => date('Y-m-d 23:59:59'), 'is_open' => 1];
                    // Format datetime-local attribute (YYYY-MM-DDTHH:MM)
                    $formattedDeadline = date('Y-m-d\TH:i', strtotime($setting['deadline_date']));
                    ?>
                    
                    <div class="p-6 border border-slate-150 rounded-2xl bg-slate-50/50 hover:bg-slate-50 transition grid gap-6 sm:grid-cols-3 items-center">
                        <div class="sm:col-span-1">
                            <span class="text-xs font-black text-slate-500 uppercase tracking-widest block mb-1">บริการระบบย่อย</span>
                            <span class="text-xs sm:text-sm font-bold text-slate-800"><?= $typeLabel; ?></span>
                        </div>
                        
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5" for="deadline_<?= $typeKey; ?>">วันเวลาสิ้นสุด (Deadline)</label>
                            <input type="datetime-local" name="deadline_<?= $typeKey; ?>" id="deadline_<?= $typeKey; ?>" value="<?= $formattedDeadline; ?>"
                                   class="w-full px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-700 focus:outline-none focus:border-teal-700 transition"
                                   required>
                        </div>
                        
                        <div class="flex items-center justify-start sm:justify-center gap-3">
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">สวิตช์เปิด-ปิดระบบย่อย:</span>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="is_open_<?= $typeKey; ?>" value="1" <?= (int)$setting['is_open'] === 1 ? 'checked' : ''; ?> class="sr-only peer">
                                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-slate-900"></div>
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pt-6 border-t border-slate-100 flex items-center justify-end gap-3">
                <a href="overview.php" class="px-5 py-2.5 border border-slate-200 hover:bg-slate-50 text-slate-500 text-xs font-bold rounded-xl transition">
                    ยกเลิก / ย้อนกลับ
                </a>
                <button type="submit" 
                        class="px-6 py-2.5 text-xs font-black text-white bg-slate-900 hover:bg-slate-800 rounded-xl transition shadow-md shadow-slate-900/10">
                    บันทึกการตั้งค่ากำหนดเวลาทั้งหมด
                </button>
            </div>
            
        </form>

    </div>

    <!-- Branding Settings Card -->
    <div class="mt-8 bg-white border border-slate-200 rounded-[32px] p-6 sm:p-10 shadow-sm">
        <h3 class="text-base sm:text-lg font-black text-slate-800 mb-2 flex items-center gap-2">
            <svg class="w-5 h-5 text-slate-700 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-2.22 1.124l-3.197-2.132a3 3 0 000-4.228l3.197-2.132a3 3 0 002.22 1.124H20a2 2 0 012 2v2a2 2 0 01-2 2H9.53zM5 12h14" />
            </svg>
            <span>ตั้งค่าข้อมูลวิทยาลัยและรูปลักษณ์ (Branding Settings)</span>
        </h3>
        <p class="text-xs text-slate-400 font-medium mb-6 leading-relaxed">
            ผู้ดูแลระบบสามารถอัปโหลดโลโก้โรงเรียน/วิทยาลัย ปรับเปลี่ยนชื่อย่อของระบบ ชื่อวิทยาลัย และข้อความหัวข้อหลักที่จะถูกนำไปแสดงผลบนแถบเมนูและหน้าต่างเข้าสู่ระบบ
        </p>
        
        <form method="post" action="settings.php" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= e(create_csrf_token()); ?>">
            <input type="hidden" name="action" value="save_branding">
            
            <div class="grid gap-6 md:grid-cols-2">
                <!-- System Name -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2" for="system_name">ชื่อระบบหลัก</label>
                    <input type="text" name="system_name" id="system_name" value="<?= e($branding['system_name']); ?>"
                           class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm font-semibold text-slate-700 focus:outline-none focus:border-teal-700 focus:bg-white transition"
                           required>
                </div>
                
                <!-- College Name -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2" for="college_name">ชื่อโรงเรียน / วิทยาลัย</label>
                    <input type="text" name="college_name" id="college_name" value="<?= e($branding['college_name']); ?>"
                           class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm font-semibold text-slate-700 focus:outline-none focus:border-teal-700 focus:bg-white transition"
                           required>
                </div>
                
                <!-- Logo Text Abbreviation -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2" for="logo_text">ตัวอักษรย่อโลโก้ (เมื่อไม่มีไฟล์รูปภาพ)</label>
                    <input type="text" name="logo_text" id="logo_text" value="<?= e($branding['logo_text'] ?? 'PNP'); ?>" maxLength="5"
                           class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm font-bold text-slate-700 focus:outline-none focus:border-teal-700 focus:bg-white transition"
                           placeholder="ตัวอย่าง: PNP" required>
                    <span class="text-[9px] text-slate-400 block mt-1">ใช้ตัวย่อสั้นๆ (สูงสุด 5 ตัวอักษร) แสดงบนวงกลมแทนนามโลโก้</span>
                </div>
                
                <!-- Logo File Upload -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2">ไฟล์รูปภาพโลโก้หลัก (Logo Image)</label>
                    <div class="flex items-center gap-4">
                        <?php if (!empty($branding['logo_path'])): ?>
                            <div class="flex flex-col items-center justify-center p-2 bg-slate-50 border border-slate-200 rounded-xl shrink-0">
                                <img src="../<?= e($branding['logo_path']); ?>" class="w-12 h-12 object-contain rounded-lg">
                                <label class="inline-flex items-center gap-1 mt-2 text-[10px] font-bold text-rose-600 cursor-pointer">
                                    <input type="checkbox" name="delete_logo" value="1" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                                    <span>ลบโลโก้เดิม</span>
                                </label>
                            </div>
                        <?php endif; ?>
                        
                        <div class="flex-1">
                            <input type="file" name="logo_file" id="logo_file" accept="image/png, image/jpeg, image/gif, image/svg+xml"
                                   class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 transition">
                            <span class="text-[9px] text-slate-400 block mt-1.5 font-thai">รองรับรูปแบบไฟล์ JPG, PNG, GIF, SVG ขนาดภาพที่แนะนำคืออัตราส่วนจัตุรัส</span>
                        </div>
                    </div>
                </div>

                <!-- System Theme Color -->
                <div class="md:col-span-2 pt-4 border-t border-slate-100">
                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-3">ธีมสีสไตล์ของหน้าแรกระบบ (System Theme Style)</label>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <label class="border border-slate-200 rounded-2xl p-3.5 flex items-center gap-3 cursor-pointer hover:bg-slate-50 transition shadow-sm relative">
                            <input type="radio" name="theme_color" value="dark-blue" <?= ($branding['theme_color'] ?? 'dark-blue') === 'dark-blue' ? 'checked' : '' ?> class="text-indigo-600 focus:ring-indigo-500">
                            <div class="flex flex-col">
                                <span class="text-xs font-black text-slate-800">Dark Blue</span>
                                <span class="text-[9px] font-bold text-slate-400 mt-0.5">น้ำเงินมืดพรีเมียม</span>
                            </div>
                        </label>
                        <label class="border border-slate-200 rounded-2xl p-3.5 flex items-center gap-3 cursor-pointer hover:bg-slate-50 transition shadow-sm relative">
                            <input type="radio" name="theme_color" value="dark-purple" <?= ($branding['theme_color'] ?? 'dark-blue') === 'dark-purple' ? 'checked' : '' ?> class="text-purple-600 focus:ring-purple-500">
                            <div class="flex flex-col">
                                <span class="text-xs font-black text-slate-800">Dark Purple</span>
                                <span class="text-[9px] font-bold text-slate-400 mt-0.5">ม่วงห้วงอวกาศหรู</span>
                            </div>
                        </label>
                        <label class="border border-slate-200 rounded-2xl p-3.5 flex items-center gap-3 cursor-pointer hover:bg-slate-50 transition shadow-sm relative">
                            <input type="radio" name="theme_color" value="dark-emerald" <?= ($branding['theme_color'] ?? 'dark-blue') === 'dark-emerald' ? 'checked' : '' ?> class="text-emerald-600 focus:ring-emerald-500">
                            <div class="flex flex-col">
                                <span class="text-xs font-black text-slate-800">Dark Emerald</span>
                                <span class="text-[9px] font-bold text-slate-400 mt-0.5">เขียวหยกหรูล้ำลึก</span>
                            </div>
                        </label>
                        <label class="border border-slate-200 rounded-2xl p-3.5 flex items-center gap-3 cursor-pointer hover:bg-slate-50 transition shadow-sm relative">
                            <input type="radio" name="theme_color" value="dark-slate" <?= ($branding['theme_color'] ?? 'dark-blue') === 'dark-slate' ? 'checked' : '' ?> class="text-slate-600 focus:ring-slate-500">
                            <div class="flex flex-col">
                                <span class="text-xs font-black text-slate-800">Dark Slate</span>
                                <span class="text-[9px] font-bold text-slate-400 mt-0.5">เทาถ่านหินทันสมัย</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="pt-6 border-t border-slate-100 flex items-center justify-end gap-3">
                <button type="submit" 
                        class="px-6 py-2.5 text-xs font-black text-white bg-slate-900 hover:bg-slate-800 rounded-xl transition shadow-md shadow-slate-900/10">
                    บันทึกข้อมูลรูปลักษณ์ระบบ
                </button>
            </div>
        </form>
    </div>

    <!-- New Semester / Data Archiving Section -->
    <div class="mt-10 bg-white border border-indigo-200/60 rounded-[32px] p-6 sm:p-10 shadow-sm shadow-indigo-50/50">
        <h3 class="text-base sm:text-lg font-black text-indigo-800 mb-2 flex items-center gap-2">
            <svg class="w-5 h-5 text-indigo-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <span>ปิดภาคเรียนปัจจุบัน & เริ่มต้นภาคเรียนการศึกษาใหม่</span>
        </h3>
        <p class="text-xs text-slate-400 font-medium mb-6 leading-relaxed">
            เมื่อสิ้นสุดภาคเรียนปัจจุบัน แดชบอร์ดแอดมินสามารถเปิดใช้งานภาคเรียนถัดไปได้จากส่วนนี้ ระบบจะทำการ <b>"แช่แข็ง" (Freeze)</b> ข้อมูลรายวิชาและประวัติการส่งงานทั้งหมดของเทอมเก่าไว้เป็นประวัติ (สามารถดูย้อนหลังได้ผ่านเมนูประวัติ) และเคลียร์กระดานว่างเพื่อเตรียมการสำหรับเทอมการศึกษาใหม่
        </p>
        
        <form method="post" action="settings.php" onsubmit="return confirm('⚠️ ยืนยันการปิดภาคเรียน:\nการดำเนินการนี้จะทำการแช่แข็งประวัติการยื่นส่งและรายวิชาสอนเดิมทั้งหมดไว้เป็นประวัติ เพื่อเตรียมเปิดภาคเรียนใหม่\n\nคุณมั่นใจที่จะเปิดภาคเรียนใหม่ใช่หรือไม่?');" class="grid gap-4 sm:grid-cols-3 items-end">
            <input type="hidden" name="csrf_token" value="<?= e(create_csrf_token()); ?>">
            <input type="hidden" name="action" value="new_semester">
            
            <div class="sm:col-span-2">
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2" for="new_semester_name">ระบุชื่อภาคเรียนการศึกษาใหม่</label>
                <input type="text" name="new_semester_name" id="new_semester_name" placeholder="ตัวอย่างเช่น: 2/2569 หรือ 1/2570"
                       class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm font-semibold text-slate-700 focus:outline-none focus:border-indigo-500 focus:bg-white transition"
                       required>
            </div>
            
            <div>
                <button type="submit" 
                        class="w-full py-3.5 text-xs font-black text-white bg-indigo-700 hover:bg-indigo-800 active:scale-[0.98] transition rounded-xl shadow-md shadow-indigo-700/10">
                    เปิดภาคเรียนใหม่
                </button>
            </div>
        </form>
    </div>

    <!-- Garbage Collection Section -->
    <div class="mt-10 bg-white border border-slate-200/60 rounded-[32px] p-6 sm:p-10 shadow-sm">
        <h3 class="text-base sm:text-lg font-black text-slate-800 mb-2 flex items-center gap-2">
            <svg class="w-5 h-5 text-slate-700 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            <span>ล้างไฟล์ขยะบนเซิร์ฟเวอร์ (Garbage Collection)</span>
        </h3>
        <p class="text-xs text-slate-400 font-medium mb-6 leading-relaxed">
            ใช้สำหรับลบไฟล์เอกสารเก่า หรือไฟล์ที่อัปโหลดซ้ำ/ไม่ได้ใช้งานออกจากเซิร์ฟเวอร์ เพื่อคืนพื้นที่เก็บข้อมูล (ระบบจะเปรียบเทียบและลบเฉพาะไฟล์ที่ไม่มีการลิงก์เชื่อมต่อในฐานข้อมูลแล้วเท่านั้น)
        </p>
        
        <form method="post" action="settings.php" onsubmit="return confirm('ยืนยันการสแกนและลบไฟล์ขยะ? กระบวนการนี้อาจใช้เวลาสักครู่');">
            <input type="hidden" name="csrf_token" value="<?= e(create_csrf_token()); ?>">
            <input type="hidden" name="action" value="garbage_collect">
            
            <button type="submit" 
                    class="px-6 py-2.5 text-xs font-black text-white bg-slate-900 hover:bg-slate-800 active:scale-[0.98] transition rounded-xl shadow-md shadow-slate-900/10">
                เริ่มสแกนและล้างไฟล์ขยะ
            </button>
        </form>
    </div>
</main>

<footer class="py-8 bg-white border-t border-slate-200 mt-16">
    <div class="max-w-4xl mx-auto px-4 text-center text-xs text-slate-400 font-medium">
        &copy; <?= date('Y'); ?> <?= htmlspecialchars($branding['college_name']); ?> &middot; ฝ่ายวิชาการ &middot; สงวนลิขสิทธิ์
    </div>
</footer>

</body>
</html>
