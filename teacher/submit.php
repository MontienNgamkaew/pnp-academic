<?php
declare(strict_types=1);

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/auth.php';

// Force teacher role
require_teacher();

$teacherId = current_user_id();

// 1. Get active semester
$semester = $pdo->query('SELECT id, semester_name FROM semesters WHERE is_active = 1 LIMIT 1')->fetch();
if (!$semester) {
    exit('ไม่พบภาคเรียนที่กำลังเปิดใช้งานในระบบ กรุณาติดต่อผู้ดูแลระบบ');
}

// 2. Fetch teacher's courses
$stmt = $pdo->prepare('SELECT id, course_code, course_name FROM courses WHERE teacher_id = :teacher_id AND semester_id = :semester_id ORDER BY course_code ASC');
$stmt->execute([
    'teacher_id' => $teacherId,
    'semester_id' => $semester['id']
]);
$courses = $stmt->fetchAll();

// Get input parameters
$selectedCourseId = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;
$selectedSystemType = isset($_GET['system_type']) ? $_GET['system_type'] : '';

// Validate system type
$validSystemTypes = ['course_syllabus', 'lesson_plan', 'teaching_materials'];
if ($selectedSystemType && !in_array($selectedSystemType, $validSystemTypes, true)) {
    $selectedSystemType = '';
}

// Fetch specific course details if selected
$courseDetails = null;
if ($selectedCourseId) {
    foreach ($courses as $c) {
        if ((int)$c['id'] === $selectedCourseId) {
            $courseDetails = $c;
            break;
        }
    }
    // If course not found in teacher's assigned courses, reset selection
    if (!$courseDetails) {
        $selectedCourseId = 0;
    }
}

// Check deadline and open status for the system type
$systemSetting = null;
$isOpen = false;
$isLate = false;
$deadlineStr = '-';

if ($selectedSystemType) {
    $stmt = $pdo->prepare('SELECT deadline_date, is_open FROM system_settings WHERE system_type = :system_type AND semester_id = :semester_id LIMIT 1');
    $stmt->execute([
        'system_type' => $selectedSystemType,
        'semester_id' => $semester['id']
    ]);
    $systemSetting = $stmt->fetch();
    
    if ($systemSetting) {
        $isOpen = (int)$systemSetting['is_open'] === 1;
        $deadlineTime = strtotime($systemSetting['deadline_date']);
        $isLate = time() > $deadlineTime;
        $deadlineStr = date('d/m/Y H:i', $deadlineTime);
    }
}

$errorMessage = '';
$successMessage = '';

// Handle file/link upload submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verify_csrf_token($csrfToken)) {
        $errorMessage = 'CSRF token ไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $postCourseId = isset($_POST['course_id']) ? (int) $_POST['course_id'] : 0;
        $postSystemType = isset($_POST['system_type']) ? $_POST['system_type'] : '';
        $driveLink = isset($_POST['drive_link']) ? trim($_POST['drive_link']) : '';
        
        // Validation
        $validPostCourse = false;
        foreach ($courses as $c) {
            if ((int)$c['id'] === $postCourseId) {
                $validPostCourse = true;
                break;
            }
        }
        
        if (!$validPostCourse) {
            $errorMessage = 'กรุณาเลือกวิชาที่สอนให้ถูกต้อง';
        } elseif (!in_array($postSystemType, $validSystemTypes, true)) {
            $errorMessage = 'กรุณาเลือกประเภทระบบส่งงานให้ถูกต้อง';
        } else {
            // Get system settings for validation
            $stmt = $pdo->prepare('SELECT deadline_date, is_open FROM system_settings WHERE system_type = :system_type AND semester_id = :semester_id LIMIT 1');
            $stmt->execute([
                'system_type' => $postSystemType,
                'semester_id' => $semester['id']
            ]);
            $setting = $stmt->fetch();
            
            if (!$setting || (int)$setting['is_open'] !== 1) {
                $errorMessage = 'ระบบนี้ถูกปิดรับการส่งเอกสารชั่วคราวโดยผู้ดูแลระบบ';
            } else {
                $deadlineTime = strtotime($setting['deadline_date']);
                $submittedTiming = (time() > $deadlineTime) ? 'late' : 'on_time';
                
                $filePath = null;
                $fileUploaded = false;
                
                // Process File Upload
                if (isset($_FILES['file_upload']) && $_FILES['file_upload']['error'] === UPLOAD_ERR_OK) {
                    $fileTmpPath = $_FILES['file_upload']['tmp_name'];
                    $fileName = $_FILES['file_upload']['name'];
                    $fileSize = $_FILES['file_upload']['size'];
                    $fileType = $_FILES['file_upload']['type'];
                    
                    $fileNameCmps = explode(".", $fileName);
                    $fileExtension = strtolower(end($fileNameCmps));
                    
                    // Safe Extensions only
                    $allowedExtensions = ['pdf', 'docx', 'doc', 'xls', 'xlsx', 'zip', 'rar'];
                    if (!in_array($fileExtension, $allowedExtensions, true)) {
                        $errorMessage = 'ไม่อนุญาตให้อัปโหลดไฟล์นามสกุลนี้ อนุญาตเฉพาะ PDF, DOCX, DOC, XLSX, XLS, ZIP, RAR เท่านั้น';
                    } elseif ($fileSize > 52428800) { // 50MB limit
                        $errorMessage = 'ขนาดไฟล์เกิน 50 MB กรุณาลดขนาดไฟล์ก่อนอัปโหลด';
                    } else {
                        // Create directory: uploads/semester_id/teacher_id/course_id/system_type/
                        $uploadDir = dirname(__DIR__) . "/uploads/{$semester['id']}/{$teacherId}/{$postCourseId}/{$postSystemType}/";
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }
                        
                        // Clean filename
                        $newFileName = $postSystemType . '_' . time() . '.' . $fileExtension;
                        $destPath = $uploadDir . $newFileName;
                        
                        if (move_uploaded_file($fileTmpPath, $destPath)) {
                            $filePath = "uploads/{$semester['id']}/{$teacherId}/{$postCourseId}/{$postSystemType}/" . $newFileName;
                            $fileUploaded = true;
                        } else {
                            $errorMessage = 'เกิดข้อผิดพลาดในการย้ายไฟล์ไปยังโฟลเดอร์เซิร์ฟเวอร์';
                        }
                    }
                }
                
                // Validation for having either a file or a Google Drive link
                if (!$errorMessage) {
                    if (!$fileUploaded && empty($driveLink)) {
                        $errorMessage = 'กรุณาเลือกอัปโหลดไฟล์จากเครื่อง หรือใส่อัปโหลดผ่านลิงก์ Google Drive อย่างใดอย่างหนึ่ง';
                    } elseif (!empty($driveLink) && !filter_var($driveLink, FILTER_VALIDATE_URL)) {
                        $errorMessage = 'รูปแบบลิงก์ Google Drive ไม่ถูกต้อง (กรุณากรอกในรูปแบบ URL)';
                    } else {
                        // All validated! Save to submissions table
                        $stmt = $pdo->prepare("
                            INSERT INTO submissions (course_id, system_type, file_path, drive_link, submission_timing, status)
                            VALUES (:course_id, :system_type, :file_path, :drive_link, :submission_timing, 'pending')
                        ");
                        
                        $stmt->execute([
                            'course_id' => $postCourseId,
                            'system_type' => $postSystemType,
                            'file_path' => $filePath,
                            'drive_link' => !empty($driveLink) ? $driveLink : null,
                            'submission_timing' => $submittedTiming
                        ]);
                        
                        $_SESSION['success_flash'] = 'ยื่นส่งเอกสารของคุณเรียบร้อยแล้ว ระบบกำลังรอการตรวจประเมินจากผู้ดูแลระบบ';
                        redirect_to('dashboard.php');
                    }
                }
            }
        }
    }
}

// Label display helper
$systemTypeLabels = [
    'course_syllabus' => 'โครงการสอน (Syllabus)',
    'lesson_plan' => 'แผนการจัดการเรียนรู้ (Lesson Plan)',
    'teaching_materials' => 'สื่อการเรียนการสอน (Teaching Materials)'
];
$branding = get_branding_settings();
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ส่งภารกิจวิชาการ | <?= htmlspecialchars($branding['system_name']); ?></title>
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
            <a href="dashboard.php" class="flex items-center gap-3 group no-underline">
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
            
            <a href="dashboard.php"
               class="inline-flex items-center justify-center gap-2 px-4 py-2 border border-slate-200 hover:bg-slate-50 text-slate-600 hover:text-slate-800 text-xs font-semibold rounded-xl transition">
                กลับหน้าแดชบอร์ด
            </a>
        </div>
    </div>
</header>

<main class="flex-1 max-w-4xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="mb-8">
        <h1 class="text-xl sm:text-2xl font-black text-slate-800">ยื่นส่งเอกสารงานวิชาการ</h1>
        <p class="text-xs text-slate-400 font-medium mt-1">
            ภาคเรียนที่ <span class="text-indigo-650 font-bold"><?= e($semester['semester_name']); ?></span> &middot; กรุณากรอกและส่งไฟล์ที่ถูกต้องเพื่อความรวดเร็วในการพิจารณาตรวจสอบ
        </p>
    </div>

    <?php if ($errorMessage): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-2xl p-4 text-xs sm:text-sm font-bold mb-6 flex items-center gap-2">
            <svg class="w-5 h-5 text-rose-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span><?= e($errorMessage); ?></span>
        </div>
    <?php endif; ?>

    <div class="bg-white border border-slate-200 rounded-[32px] p-6 sm:p-10 shadow-sm">
        
        <form method="get" action="submit.php" class="grid gap-6 sm:grid-cols-2 mb-8 pb-8 border-b border-slate-100">
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2" for="course_select">1. เลือกรายวิชา</label>
                <select name="course_id" id="course_select" onchange="this.form.submit()" 
                        class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm font-semibold text-slate-700 focus:outline-none focus:border-teal-700 focus:bg-white transition">
                    <option value="0">-- กรุณาเลือกรายวิชา --</option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?= $c['id']; ?>" <?= $selectedCourseId === (int)$c['id'] ? 'selected' : ''; ?>>
                            [<?= e($c['course_code']); ?>] <?= e($c['course_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2" for="type_select">2. เลือกประเภทเอกสารที่ต้องการส่ง</label>
                <select name="system_type" id="type_select" onchange="this.form.submit()"
                        class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm font-semibold text-slate-700 focus:outline-none focus:border-teal-700 focus:bg-white transition">
                    <option value="">-- กรุณาเลือกประเภทเอกสาร --</option>
                    <?php foreach ($systemTypeLabels as $typeKey => $typeLabel): ?>
                        <option value="<?= $typeKey; ?>" <?= $selectedSystemType === $typeKey ? 'selected' : ''; ?>>
                            <?= $typeLabel; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if ($selectedCourseId && $selectedSystemType): ?>
            
            <!-- Real-time Deadline Box -->
            <div class="mb-8">
                <?php if ($systemSetting): ?>
                    <?php if ($isOpen): ?>
                        <?php if ($isLate): ?>
                            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 text-amber-800">
                                <div class="flex items-start gap-3">
                                    <span class="p-1 rounded-lg bg-amber-100 text-amber-700">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </span>
                                    <div>
                                        <h4 class="text-xs sm:text-sm font-black">เลยกำหนดระยะเวลาปิดระบบส่งงาน (ส่งล่าช้า)</h4>
                                        <p class="text-[11px] sm:text-xs text-amber-700 font-medium mt-1 leading-relaxed">
                                            ระบบยังอนุญาตให้ท่านยื่นส่งเอกสารได้ตามปกติหลังเวลาเดดไลน์ (<?= $deadlineStr; ?> น.) แต่ระบบจะทำการไฮไลต์บันทึกประวัติการส่งเป็น **"ส่งล่าช้า"** โดยอัตโนมัติเพื่อประกอบการพิจารณาครับ
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="bg-teal-50/70 border border-teal-100 rounded-2xl p-5 text-teal-800">
                                <div class="flex items-start gap-3">
                                    <span class="p-1 rounded-lg bg-teal-100 text-teal-700">
                                        <svg class="w-5 h-5 text-teal-800" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </span>
                                    <div>
                                        <h4 class="text-xs sm:text-sm font-black">ระบบเปิดอยู่ (ภายในระยะเวลากำหนดส่ง)</h4>
                                        <p class="text-[11px] sm:text-xs text-teal-700 font-medium mt-1 leading-relaxed">
                                            สามารถยื่นส่งเอกสารได้ตามเกณฑ์เวลาปกติ วันสิ้นสุดกำหนดส่งคือวันที่ <span class="font-black text-teal-900"><?= $deadlineStr; ?> น.</span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="bg-rose-50 border border-rose-200 rounded-2xl p-5 text-rose-800">
                            <div class="flex items-start gap-3">
                                <span class="p-1 rounded-lg bg-rose-100 text-rose-700">
                                    <svg class="w-5 h-5 text-rose-700" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                </span>
                                <div>
                                    <h4 class="text-xs sm:text-sm font-black">ปิดระบบชั่วคราว</h4>
                                    <p class="text-[11px] sm:text-xs text-rose-600 font-medium mt-1 leading-relaxed">
                                        ผู้ดูแลระบบงานวิชาการได้ทำการปิดสวิตช์การส่งงานสำหรับประเภทนี้ชั่วคราว ท่านจะไม่สามารถกดปุ่มส่งเอกสารได้ในขณะนี้
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Upload Form -->
            <form method="post" action="submit.php?course_id=<?= $selectedCourseId; ?>&system_type=<?= $selectedSystemType; ?>" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= e(create_csrf_token()); ?>">
                <input type="hidden" name="course_id" value="<?= $selectedCourseId; ?>">
                <input type="hidden" name="system_type" value="<?= $selectedSystemType; ?>">

                <div class="grid gap-6 md:grid-cols-2">
                    
                    <!-- File Upload Option -->
                    <div class="border border-slate-200 rounded-2xl p-6 bg-slate-50/50 hover:bg-slate-50 transition relative">
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">ช่องทางที่ 1: อัปโหลดไฟล์จากเครื่องโดยตรง</label>
                        <input type="file" name="file_upload" id="file_upload"
                               class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-black file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100 transition cursor-pointer"
                               <?= !$isOpen ? 'disabled' : ''; ?>>
                        <p class="text-[10px] text-slate-400 font-medium mt-2 leading-relaxed">
                            อนุญาตเฉพาะไฟล์: **PDF, DOCX, DOC, XLSX, XLS, ZIP, RAR** ขนาดไฟล์ไม่เกิน 20MB
                        </p>
                    </div>

                    <!-- Drive Link Option -->
                    <div class="border border-slate-200 rounded-2xl p-6 bg-slate-50/50 hover:bg-slate-50 transition">
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2" for="drive_link">ช่องทางที่ 2: แนบลิงก์ Google Drive</label>
                        <input type="url" name="drive_link" id="drive_link" placeholder="https://drive.google.com/..."
                               class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs sm:text-sm font-semibold text-slate-700 focus:outline-none focus:border-teal-700 transition"
                               <?= !$isOpen ? 'disabled' : ''; ?>>
                        <p class="text-[10px] text-slate-400 font-medium mt-2 leading-relaxed">
                            กรุณาเปิดการแชร์ลิงก์ให้เป็น *"ทุกคนที่มีลิงก์มีสิทธิ์อ่าน"* เพื่อให้งานวิชาการสามารถเปิดตรวจได้
                        </p>
                    </div>

                </div>

                <div class="pt-4 flex items-center justify-end gap-3">
                    <a href="dashboard.php" class="px-5 py-2.5 border border-slate-200 hover:bg-slate-50 text-slate-500 text-xs font-bold rounded-xl transition">
                        ยกเลิก
                    </a>
                    <button type="submit" 
                            class="px-6 py-2.5 text-xs font-black text-white bg-slate-900 hover:bg-slate-800 disabled:bg-slate-200 disabled:text-slate-400 disabled:cursor-not-allowed rounded-xl transition shadow-md shadow-slate-900/10"
                            <?= !$isOpen ? 'disabled' : ''; ?>>
                        ยืนยันการส่งเอกสาร
                    </button>
                </div>

            </form>

        <?php else: ?>
            <div class="py-12 text-center text-slate-400 font-medium">
                <svg class="w-12 h-12 mx-auto text-slate-300 mb-3" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <span>กรุณาเลือกรายวิชาและประเภทของเอกสารด้านบนเพื่อทำการยื่นส่งงานครับ</span>
            </div>
        <?php endif; ?>

    </div>
</main>

<footer class="py-8 bg-white border-t border-slate-200 mt-16">
    <div class="max-w-4xl mx-auto px-4 text-center text-xs text-slate-400 font-medium">
        &copy; <?= date('Y'); ?> <?= htmlspecialchars($branding['college_name']); ?> &middot; ฝ่ายวิชาการ &middot; สงวนลิขสิทธิ์
    </div>
</footer>

</body>
</html>
