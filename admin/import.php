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

$branding = get_branding_settings();

// Download template CSV files
if (isset($_GET['download_template'])) {
    $templateType = $_GET['download_template'];
    if ($templateType === 'users') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sample_teachers.csv"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        echo "citizen_id,fullname,department\n";
        echo "1234567890123,นายสมชาย ใจดี,แผนกวิชาช่างยนต์\n";
        echo "9876543210987,นางสาวสมศรี รักเรียน,แผนกวิชาคอมพิวเตอร์ธุรกิจ\n";
        exit;
    } elseif ($templateType === 'courses') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sample_courses.csv"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        echo "course_code,course_name,teacher_username\n";
        echo "30001-1002,เคมีพื้นฐาน,1234567890123\n";
        echo "30105-2001,วงจรอิเล็กทรอนิกส์,9876543210987\n";
        exit;
    }
}

// Get active semester
$semester = $pdo->query('SELECT id, semester_name FROM semesters WHERE is_active = 1 LIMIT 1')->fetch();
if (!$semester) {
    exit('ไม่พบภาคเรียนที่กำลังเปิดใช้งานในระบบ กรุณาติดต่อผู้ดูแลระบบ');
}

$errorMessage = '';
$successMessage = '';
$importLogs = [];
$activeTab = $_GET['tab'] ?? 'users';
if ($activeTab !== 'users' && $activeTab !== 'courses') {
    $activeTab = 'users';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verify_csrf_token($csrfToken)) {
        $errorMessage = 'CSRF token ไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add_user_manual') {
            // Manual Personnel Import
            $citizenId = trim((string)($_POST['citizen_id'] ?? ''));
            $fullname = trim((string)($_POST['fullname'] ?? ''));
            $department = trim((string)($_POST['department'] ?? ''));
            
            // Format ID: Remove spaces and dashes
            $citizenId = str_replace([' ', '-'], '', $citizenId);
            
            if ($citizenId === '' || $fullname === '' || $department === '') {
                $errorMessage = 'กรุณากรอกข้อมูลให้ครบถ้วนทุกช่อง';
            } elseif (strlen($citizenId) !== 13 || !ctype_digit($citizenId)) {
                $errorMessage = 'เลขบัตรประจำตัวประชาชนต้องเป็นตัวเลข 13 หลักเท่านั้น';
            } else {
                try {
                    // Check if citizen_id already exists in system
                    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
                    $stmt->execute(['username' => $citizenId]);
                    if ($stmt->fetch()) {
                        $errorMessage = "เลขบัตรประชาชน '{$citizenId}' นี้ได้รับการลงทะเบียนในระบบเรียบร้อยแล้ว";
                    } else {
                        // Password defaults to citizen_id
                        $passwordHash = password_hash($citizenId, PASSWORD_DEFAULT);
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO users (username, password, fullname, department, role, status) 
                            VALUES (:username, :password, :fullname, :department, 'teacher', 'active')
                        ");
                        $stmt->execute([
                            'username' => $citizenId,
                            'password' => $passwordHash,
                            'fullname' => $fullname,
                            'department' => $department
                        ]);
                        
                        $successMessage = "เพิ่มบุคลากร '{$fullname}' แผนกวิชา '{$department}' สำเร็จแล้ว! บัญชีพร้อมใช้งานด้วยเลขบัตรประชาชน";
                    }
                } catch (Exception $e) {
                    $errorMessage = "เกิดข้อผิดพลาดของฐานข้อมูล: " . $e->getMessage();
                }
            }
            
        } elseif ($action === 'import_users_csv') {
            // CSV Bulk Personnel Import
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                $errorMessage = 'กรุณาเลือกไฟล์ CSV สำหรับข้อมูลครู';
            } else {
                $fileTmpPath = $_FILES['csv_file']['tmp_name'];
                $fileName = $_FILES['csv_file']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                if ($fileExtension !== 'csv') {
                    $errorMessage = 'ระบบรองรับเฉพาะไฟล์สกุล .csv เท่านั้น';
                } else {
                    if (($handle = fopen($fileTmpPath, 'r')) !== false) {
                        // Skip BOM if present
                        $bom = fread($handle, 3);
                        if ($bom !== "\xEF\xBB\xBF") {
                            rewind($handle);
                        }
                        
                        $rowCount = 0;
                        $successCount = 0;
                        $skipCount = 0;
                        
                        try {
                            $pdo->beginTransaction();
                            
                            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                                $rowCount++;
                                
                                // Trim and clean each cell
                                $data = array_map('trim', $data);
                                
                                // Skip header if detected
                                if ($rowCount === 1 && (!ctype_digit($data[0]) || strlen($data[0]) !== 13)) {
                                    continue;
                                }
                                
                                if (count($data) < 3) {
                                    $importLogs[] = "แถวที่ {$rowCount}: ข้อมูลไม่ครบถ้วน (ต้องประกอบด้วย เลขบัตรประชาชน, ชื่อ-นามสกุล, แผนกวิชา)";
                                    $skipCount++;
                                    continue;
                                }
                                
                                $citizenId = str_replace([' ', '-'], '', $data[0]);
                                $fullname = $data[1];
                                $department = $data[2];
                                
                                if ($citizenId === '' || $fullname === '' || $department === '') {
                                    $importLogs[] = "แถวที่ {$rowCount}: มีข้อมูลว่างเปล่า ข้ามแถวนี้";
                                    $skipCount++;
                                    continue;
                                }
                                
                                if (strlen($citizenId) !== 13 || !ctype_digit($citizenId)) {
                                    $importLogs[] = "แถวที่ {$rowCount}: เลขบัตรประชาชน '{$citizenId}' ไม่ถูกต้อง (ต้องเป็นตัวเลข 13 หลัก)";
                                    $skipCount++;
                                    continue;
                                }
                                
                                // Duplicate check
                                $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
                                $stmt->execute(['username' => $citizenId]);
                                if ($stmt->fetch()) {
                                    $importLogs[] = "แถวที่ {$rowCount}: เลขบัตรประชาชน '{$citizenId}' มีในระบบแล้ว ข้ามแถวนี้";
                                    $skipCount++;
                                    continue;
                                }
                                
                                // Password defaults to citizen_id
                                $passwordHash = password_hash($citizenId, PASSWORD_DEFAULT);
                                
                                $stmt = $pdo->prepare("
                                    INSERT INTO users (username, password, fullname, department, role, status) 
                                    VALUES (:username, :password, :fullname, :department, 'teacher', 'active')
                                ");
                                $stmt->execute([
                                    'username' => $citizenId,
                                    'password' => $passwordHash,
                                    'fullname' => $fullname,
                                    'department' => $department
                                ]);
                                
                                $successCount++;
                            }
                            
                            $pdo->commit();
                            $successMessage = "นำเข้าข้อมูลบุคลากรครูสำเร็จเรียบร้อยแล้ว จำนวน {$successCount} รายการ (ข้าม {$skipCount} รายการ)";
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $errorMessage = "เกิดข้อผิดพลาดในการนำข้อมูลเข้า: " . $e->getMessage();
                        }
                        
                        fclose($handle);
                    } else {
                        $errorMessage = 'ไม่สามารถเปิดอ่านไฟล์ที่อัปโหลดได้';
                    }
                }
            }
            
        } elseif ($action === 'import_courses_csv') {
            // CSV Bulk Courses Import
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                $errorMessage = 'กรุณาเลือกไฟล์ CSV สำหรับข้อมูลรายวิชา';
            } else {
                $fileTmpPath = $_FILES['csv_file']['tmp_name'];
                $fileName = $_FILES['csv_file']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                if ($fileExtension !== 'csv') {
                    $errorMessage = 'ระบบรองรับเฉพาะไฟล์สกุล .csv เท่านั้น';
                } else {
                    if (($handle = fopen($fileTmpPath, 'r')) !== false) {
                        // Skip BOM
                        $bom = fread($handle, 3);
                        if ($bom !== "\xEF\xBB\xBF") {
                            rewind($handle);
                        }
                        
                        $rowCount = 0;
                        $successCount = 0;
                        $skipCount = 0;
                        
                        try {
                            $pdo->beginTransaction();
                            
                            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                                $rowCount++;
                                
                                $data = array_map('trim', $data);
                                
                                // Skip header
                                if ($rowCount === 1 && (strtolower($data[0]) === 'course_code' || $data[0] === 'รหัสวิชา')) {
                                    continue;
                                }
                                
                                if (count($data) < 3) {
                                    $importLogs[] = "แถวที่ {$rowCount}: ข้อมูลไม่ครบถ้วน (ต้องประกอบด้วย รหัสวิชา, ชื่อวิชา, เลขบัตรครูผู้สอน)";
                                    $skipCount++;
                                    continue;
                                }
                                
                                $courseCode = $data[0];
                                $courseName = $data[1];
                                $teacherUsername = str_replace([' ', '-'], '', $data[2]);
                                
                                if ($courseCode === '' || $courseName === '' || $teacherUsername === '') {
                                    $importLogs[] = "แถวที่ {$rowCount}: มีฟิลด์ข้อมูลว่างเปล่า ข้ามแถวนี้";
                                    $skipCount++;
                                    continue;
                                }
                                
                                // Verify teacher exists
                                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username AND role = 'teacher' LIMIT 1");
                                $stmt->execute(['username' => $teacherUsername]);
                                $teacher = $stmt->fetch();
                                
                                if (!$teacher) {
                                    $importLogs[] = "แถวที่ {$rowCount}: ไม่พบคุณครูเจ้าของเลขบัตรประชาชน '{$teacherUsername}' ในระบบวิชาการ ข้ามแถวนี้";
                                    $skipCount++;
                                    continue;
                                }
                                
                                // Check duplicate course for this teacher/semester
                                $stmt = $pdo->prepare('
                                    SELECT id FROM courses 
                                    WHERE course_code = :course_code AND teacher_id = :teacher_id AND semester_id = :semester_id 
                                    LIMIT 1
                                ');
                                $stmt->execute([
                                    'course_code' => $courseCode,
                                    'teacher_id' => $teacher['id'],
                                    'semester_id' => $semester['id']
                                ]);
                                
                                if ($stmt->fetch()) {
                                    $importLogs[] = "แถวที่ {$rowCount}: รายวิชา '{$courseCode}' ถูกมอบหมายให้คุณครูท่านนี้อยู่แล้วในเทอมนี้ ข้ามแถวนี้";
                                    $skipCount++;
                                    continue;
                                }
                                
                                $stmt = $pdo->prepare('
                                    INSERT INTO courses (course_code, course_name, teacher_id, semester_id) 
                                    VALUES (:course_code, :course_name, :teacher_id, :semester_id)
                                ');
                                $stmt->execute([
                                    'course_code' => $courseCode,
                                    'course_name' => $courseName,
                                    'teacher_id' => $teacher['id'],
                                    'semester_id' => $semester['id']
                                ]);
                                
                                $successCount++;
                            }
                            
                            $pdo->commit();
                            $successMessage = "นำเข้ารายวิชาสอนสำเร็จเรียบร้อยแล้ว จำนวน {$successCount} รายการ (ข้าม {$skipCount} รายการ)";
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $errorMessage = "เกิดข้อผิดพลาดในการประมวลผลวิชาสอน: " . $e->getMessage();
                        }
                        
                        fclose($handle);
                    } else {
                        $errorMessage = 'ไม่สามารถเปิดอ่านไฟล์ CSV ที่อัปโหลดได้';
                    }
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>นำเข้าและจัดการข้อมูลบุคลากร | <?= htmlspecialchars($branding['system_name']); ?></title>
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
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
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
                <a href="settings.php" class="hover:text-slate-800">ตั้งค่าเดดไลน์ระบบ</a>
                <a href="import.php" class="text-teal-700 font-black">นำเข้าและจัดการข้อมูล</a>
                <a href="history.php" class="hover:text-slate-800 border-l border-slate-300 pl-6">ประวัติย้อนหลัง</a>
            </nav>
            
            <a href="overview.php"
               class="inline-flex items-center justify-center gap-2 px-4 py-2 border border-slate-200 hover:bg-slate-50 text-slate-600 hover:text-slate-800 text-xs font-semibold rounded-xl transition">
                กลับแดชบอร์ด
            </a>
        </div>
    </div>
</header>

<main class="flex-1 max-w-6xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <!-- Header Page Info -->
    <div class="mb-8">
        <h1 class="text-xl sm:text-2xl font-black text-slate-800">จัดการและนำเข้าข้อมูลทางวิชาการ</h1>
        <p class="text-xs text-slate-400 font-medium mt-1">
            ภาคเรียนที่ <span class="text-indigo-650 font-bold"><?= e($semester['semester_name']); ?></span> &middot; จัดเตรียมบุคลากรครูผู้สอน บัญชีรายวิชาการจัดจ้างสอนในแต่ละภาคเรียน
        </p>
    </div>

    <!-- Feedback Message Banner -->
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

    <!-- Gorgeous Premium Navigation Tabs -->
    <div class="flex border-b border-slate-200 mb-8 bg-white/60 p-1.5 rounded-2xl border border-slate-200 max-w-md">
        <a href="?tab=users" 
           class="flex-1 text-center py-2.5 text-xs font-black rounded-xl transition <?= $activeTab === 'users' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>">
            ข้อมูลบุคลากรครู
        </a>
        <a href="?tab=courses" 
           class="flex-1 text-center py-2.5 text-xs font-black rounded-xl transition <?= $activeTab === 'courses' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>">
            รายวิชาจัดสอน
        </a>
    </div>

    <!-- Content Sections based on Active Tab -->
    <?php if ($activeTab === 'users'): ?>
        <!-- Tab 1: Users (Personnel) -->
        <div class="grid gap-8 lg:grid-cols-3">
            
            <!-- Left Side: Manual Personnel Form -->
            <div class="bg-white border border-slate-200 rounded-[32px] p-6 sm:p-8 shadow-sm lg:col-span-1 flex flex-col justify-between">
                <div>
                    <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-100">
                        <div class="w-8 h-8 rounded-xl bg-teal-50 text-teal-700 flex items-center justify-center font-bold text-sm">
                            +
                        </div>
                        <h3 class="text-sm sm:text-base font-black text-slate-800">เพิ่มบุคลากรรายบุคคล</h3>
                    </div>
                    
                    <form method="post" action="import.php?tab=users" class="space-y-5">
                        <input type="hidden" name="csrf_token" value="<?= e(create_csrf_token()); ?>">
                        <input type="hidden" name="action" value="add_user_manual">
                        
                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2" for="citizen_id">เลขบัตรประจำตัวประชาชน (13 หลัก)</label>
                            <input type="text" name="citizen_id" id="citizen_id" maxlength="13" required
                                   placeholder="เช่น 1234567890123"
                                   class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-850 text-xs sm:text-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500/40 focus:border-teal-500 font-outfit tracking-widest transition">
                            <span class="text-[9px] text-slate-400 font-medium mt-1.5 block leading-normal">
                                * ใช้เป็น Username และ Password แรกเริ่มสำหรับการล็อกอิน
                            </span>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2" for="fullname">ชื่อ - นามสกุล</label>
                            <input type="text" name="fullname" id="fullname" required
                                   placeholder="เช่น นายเก่งกล้า สามารถ"
                                   class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-850 text-xs sm:text-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500/40 focus:border-teal-500 transition">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2" for="department">แผนกวิชา</label>
                            <input type="text" name="department" id="department" required
                                   placeholder="เช่น แผนกวิชาคอมพิวเตอร์ธุรกิจ"
                                   class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-850 text-xs sm:text-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-teal-500/40 focus:border-teal-500 transition">
                        </div>

                        <div class="pt-2">
                            <button type="submit" 
                                    class="w-full py-3 text-xs font-black text-white bg-slate-900 hover:bg-slate-800 rounded-xl transition shadow-md shadow-slate-900/10 flex items-center justify-center gap-2">
                                <span>บันทึกเพิ่มบุคลากร</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Center/Right Side: CSV Personnel Import -->
            <div class="bg-white border border-slate-200 rounded-[32px] p-6 sm:p-8 shadow-sm lg:col-span-2">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-100">
                    <div class="w-8 h-8 rounded-xl bg-teal-50 text-teal-700 flex items-center justify-center font-bold text-sm">
                        📁
                    </div>
                    <h3 class="text-sm sm:text-base font-black text-slate-800">นำเข้าข้อมูลครูผ่านไฟล์ CSV</h3>
                </div>

                <form method="post" action="import.php?tab=users" enctype="multipart/form-data" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= e(create_csrf_token()); ?>">
                    <input type="hidden" name="action" value="import_users_csv">
                    
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2" for="users_csv">เลือกไฟล์บุคลากรครู (.csv)</label>
                        <div class="border-2 border-dashed border-slate-200 rounded-2xl p-8 bg-slate-50/30 text-center hover:bg-slate-50 transition cursor-pointer relative">
                            <input type="file" name="csv_file" id="users_csv" accept=".csv" required
                                   class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100 transition cursor-pointer">
                            <p class="text-[10px] text-slate-400 font-medium mt-3 leading-relaxed">
                                รองรับการเข้ารหัสอักขระแบบ UTF-8 เท่านั้น เพื่อไม่ให้ภาษาไทยมีปัญหาตัวอักษรต่างดาว
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center justify-end pt-2">
                        <button type="submit" 
                                class="px-6 py-3 text-xs font-black text-white bg-slate-900 hover:bg-slate-800 rounded-xl transition shadow-md shadow-slate-900/10">
                            เริ่มนำเข้าข้อมูล CSV
                        </button>
                    </div>
                </form>

                <!-- Skip logs if any -->
                <?php if (count($importLogs) > 0): ?>
                    <div class="mt-8 pt-6 border-t border-slate-100">
                        <h4 class="text-xs font-bold text-rose-600 uppercase tracking-wider mb-3">บันทึกข้อผิดพลาด/แถวที่ข้ามตรวจรับ (Import Skip Logs)</h4>
                        <div class="bg-rose-50/50 rounded-2xl p-4 border border-rose-100 max-h-48 overflow-y-auto text-[11px] font-medium text-rose-700 space-y-1.5 font-mono">
                            <?php foreach ($importLogs as $log): ?>
                                <div class="flex items-start gap-1">
                                    <span class="text-rose-500 shrink-0">•</span>
                                    <span><?= e($log); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Manual / Structure explanation -->
                <div class="mt-8 pt-6 border-t border-slate-100">
                    <div class="flex items-center justify-between gap-4 flex-wrap mb-3">
                        <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider">ดาวน์โหลดไฟล์ตัวอย่าง</h4>
                        <a href="import.php?download_template=users" 
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-teal-50 hover:bg-teal-100 text-teal-700 hover:text-teal-900 text-[10px] font-black rounded-lg transition border border-teal-200 shadow-sm">
                            📥 ดาวน์โหลดไฟล์ตัวอย่างครู (CSV)
                        </a>
                    </div>
                    <p class="text-xs text-slate-500 leading-normal mb-3">
                        กรุณาเรียงแถวข้อมูลของคุณในรูปแบบคอลัมน์ 3 คอลัมน์ดังนี้ (สามารถมีบรรทัดหัวตารางหรือไม่มีก็ได้):
                    </p>
                    <div class="bg-slate-900 text-teal-300 rounded-2xl p-4 font-mono text-[10px] sm:text-xs leading-relaxed overflow-x-auto">
                        citizen_id,fullname,department<br>
                        1234567890123,นายเก่งกล้า สามารถ,แผนกวิชาช่างยนต์<br>
                        9876543210987,นางสาวใจดี งดงาม,แผนกวิชาคอมพิวเตอร์ธุรกิจ
                    </div>
                    <ul class="text-[10px] sm:text-xs text-slate-400 mt-3 list-disc pl-5 space-y-1 leading-normal">
                        <li><b>citizen_id</b> คือ เลขประจำตัวประชาชน 13 หลักถ้วน (ไม่ควรมีขีดกลาง)</li>
                        <li>ชื่อผู้ใช้ (Username) และ รหัสผ่าน (Password) เริ่มต้นเข้าใช้งาน จะเป็น **เลขประจำตัวประชาชน** นี้</li>
                    </ul>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Tab 2: Courses -->
        <div class="bg-white border border-slate-200 rounded-[32px] p-6 sm:p-8 shadow-sm max-w-3xl mx-auto">
            <div class="flex items-center gap-3 mb-6 pb-4 border-b border-slate-100">
                <div class="w-8 h-8 rounded-xl bg-teal-50 text-teal-700 flex items-center justify-center font-bold text-sm">
                    📚
                </div>
                <h3 class="text-sm sm:text-base font-black text-slate-800">นำเข้ารายวิชาสอนผ่านไฟล์ CSV</h3>
            </div>

            <form method="post" action="import.php?tab=courses" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= e(create_csrf_token()); ?>">
                <input type="hidden" name="action" value="import_courses_csv">
                
                <div>
                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2" for="courses_csv">เลือกไฟล์รายวิชาสอน (.csv)</label>
                    <div class="border-2 border-dashed border-slate-200 rounded-2xl p-8 bg-slate-50/30 text-center hover:bg-slate-50 transition cursor-pointer relative">
                        <input type="file" name="csv_file" id="courses_csv" accept=".csv" required
                               class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100 transition cursor-pointer">
                        <p class="text-[10px] text-slate-400 font-medium mt-3 leading-relaxed">
                            ระบบจะตรวจสอบเลขบัตรประจำตัวครูผู้รับผิดชอบกับรหัสบัญชีของระบบก่อนอนุมัตินำเข้ารายวิชา
                        </p>
                    </div>
                </div>

                <div class="flex items-center justify-end pt-2">
                    <button type="submit" 
                            class="px-6 py-3 text-xs font-black text-white bg-slate-900 hover:bg-slate-800 rounded-xl transition shadow-md shadow-slate-900/10">
                        เริ่มนำเข้าข้อมูลรายวิชา
                    </button>
                </div>
            </form>

            <!-- Skip logs if any -->
            <?php if (count($importLogs) > 0): ?>
                <div class="mt-8 pt-6 border-t border-slate-100">
                    <h4 class="text-xs font-bold text-rose-600 uppercase tracking-wider mb-3">บันทึกข้อผิดพลาด/แถวที่ข้ามตรวจรับ (Import Skip Logs)</h4>
                    <div class="bg-rose-50/50 rounded-2xl p-4 border border-rose-100 max-h-48 overflow-y-auto text-[11px] font-medium text-rose-700 space-y-1.5 font-mono">
                        <?php foreach ($importLogs as $log): ?>
                            <div class="flex items-start gap-1">
                                <span class="text-rose-500 shrink-0">•</span>
                                <span><?= e($log); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Formatting explanation -->
            <div class="mt-8 pt-6 border-t border-slate-100">
                <div class="flex items-center justify-between gap-4 flex-wrap mb-3">
                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider">ดาวน์โหลดไฟล์ตัวอย่าง</h4>
                    <a href="import.php?download_template=courses" 
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-teal-50 hover:bg-teal-100 text-teal-700 hover:text-teal-900 text-[10px] font-black rounded-lg transition border border-teal-200 shadow-sm">
                        📥 ดาวน์โหลดไฟล์ตัวอย่างวิชา (CSV)
                    </a>
                </div>
                <p class="text-xs text-slate-500 leading-normal mb-3">
                    กรุณาเรียงแถวข้อมูลรายวิชาสอนในรูปแบบคอลัมน์ 3 คอลัมน์ดังนี้ (สามารถมีบรรทัดหัวตารางหรือไม่มีก็ได้):
                </p>
                <div class="bg-slate-900 text-teal-300 rounded-2xl p-4 font-mono text-[10px] sm:text-xs leading-relaxed overflow-x-auto">
                    course_code,course_name,teacher_username<br>
                    20101-2003,งานปรับแต่งเครื่องยนต์,1234567890123<br>
                    30204-2005,วิเคราะห์ระบบเครือข่ายคอมพิวเตอร์,9876543210987
                </div>
                <ul class="text-[10px] sm:text-xs text-slate-400 mt-3 list-disc pl-5 space-y-1 leading-normal font-medium">
                    <li><b>course_code</b> รหัสวิชาตามหลักสูตรการจัดสอน</li>
                    <li><b>course_name</b> ชื่อของรายวิชา</li>
                    <li><b>teacher_username</b> คือ **เลขประจำตัวประชาชน** ของคุณครูที่ได้รับการลงทะเบียนในระบบวิชาการแล้ว</li>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</main>

<footer class="py-8 bg-white border-t border-slate-200 mt-16">
    <div class="max-w-6xl mx-auto px-4 text-center text-xs text-slate-400 font-medium">
        &copy; <?= date('Y'); ?> <?= htmlspecialchars($branding['college_name']); ?> &middot; ฝ่ายวิชาการ &middot; สงวนลิขสิทธิ์
    </div>
</footer>

</body>
</html>
