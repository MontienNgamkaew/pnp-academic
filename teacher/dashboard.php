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

$errorMessage = '';

// Handle Add/Delete Course actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verify_csrf_token($csrfToken)) {
        $errorMessage = 'CSRF token ไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add_course') {
            $courseCode = trim((string)($_POST['course_code'] ?? ''));
            $courseName = trim((string)($_POST['course_name'] ?? ''));
            
            if ($courseCode === '' || $courseName === '') {
                $errorMessage = 'กรุณากรอกรหัสวิชาและชื่อรายวิชาให้ครบถ้วน';
            } else {
                // Check if already exists for this teacher in this semester
                $stmt = $pdo->prepare('SELECT id FROM courses WHERE course_code = :code AND teacher_id = :t_id AND semester_id = :sem_id LIMIT 1');
                $stmt->execute([
                    'code' => $courseCode,
                    't_id' => $teacherId,
                    'sem_id' => $semester['id']
                ]);
                if ($stmt->fetch()) {
                    $errorMessage = "คุณมีรายวิชา '{$courseCode}' ในบัญชีการจัดสอนประจำภาคเรียนนี้อยู่แล้ว";
                } else {
                    $stmt = $pdo->prepare('INSERT INTO courses (course_code, course_name, teacher_id, semester_id) VALUES (:code, :name, :t_id, :sem_id)');
                    $stmt->execute([
                        'code' => $courseCode,
                        'name' => $courseName,
                        't_id' => $teacherId,
                        'sem_id' => $semester['id']
                    ]);
                    
                    $_SESSION['success_flash'] = "เพิ่มรายวิชา '{$courseCode} - {$courseName}' สำเร็จเรียบร้อยแล้ว!";
                    redirect_to('dashboard.php');
                }
            }
        } elseif ($action === 'delete_course') {
            $courseId = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
            
            // Verify ownership first for security
            $stmt = $pdo->prepare('SELECT course_code, course_name FROM courses WHERE id = :course_id AND teacher_id = :t_id AND semester_id = :sem_id LIMIT 1');
            $stmt->execute([
                'course_id' => $courseId,
                't_id' => $teacherId,
                'sem_id' => $semester['id']
            ]);
            $courseToDelete = $stmt->fetch();
            
            if ($courseToDelete) {
                // Delete course (will cascade delete submissions in DB)
                $stmt = $pdo->prepare('DELETE FROM courses WHERE id = :course_id');
                $stmt->execute(['course_id' => $courseId]);
                
                $_SESSION['success_flash'] = "ลบรายวิชา '{$courseToDelete['course_code']} - {$courseToDelete['course_name']}' ออกจากบัญชีสอนเรียบร้อยแล้ว";
                redirect_to('dashboard.php');
            } else {
                $errorMessage = 'ไม่พบรายวิชาที่ต้องการลบ หรือคุณไม่มีสิทธิ์ลบรายวิชานี้';
            }
        }
    }
}

// 2. Fetch system settings (deadlines and open statuses)
$stmt = $pdo->prepare('SELECT system_type, deadline_date, is_open FROM system_settings WHERE semester_id = :semester_id');
$stmt->execute(['semester_id' => $semester['id']]);
$systemSettings = [];
foreach ($stmt->fetchAll() as $row) {
    $systemSettings[$row['system_type']] = $row;
}

// 3. Fetch all courses for this teacher and their latest submissions
$stmt = $pdo->prepare("
    SELECT c.*, 
           s_sub.status AS syllabus_status, s_sub.id AS syllabus_sub_id, s_sub.submission_timing AS syllabus_timing,
           s_plan.status AS plan_status, s_plan.id AS plan_sub_id, s_plan.submission_timing AS plan_timing,
           s_mat.status AS mat_status, s_mat.id AS mat_sub_id, s_mat.submission_timing AS mat_timing
    FROM courses c
    LEFT JOIN (
        SELECT s1.* FROM submissions s1
        INNER JOIN (
            SELECT MAX(id) as max_id FROM submissions WHERE system_type = 'course_syllabus' GROUP BY course_id
        ) s2 ON s1.id = s2.max_id
    ) s_sub ON c.id = s_sub.course_id
    LEFT JOIN (
        SELECT s1.* FROM submissions s1
        INNER JOIN (
            SELECT MAX(id) as max_id FROM submissions WHERE system_type = 'lesson_plan' GROUP BY course_id
        ) s2 ON s1.id = s2.max_id
    ) s_plan ON c.id = s_plan.course_id
    LEFT JOIN (
        SELECT s1.* FROM submissions s1
        INNER JOIN (
            SELECT MAX(id) as max_id FROM submissions WHERE system_type = 'teaching_materials' GROUP BY course_id
        ) s2 ON s1.id = s2.max_id
    ) s_mat ON c.id = s_mat.course_id
    WHERE c.teacher_id = :teacher_id AND c.semester_id = :semester_id
    ORDER BY c.course_code ASC
");
$stmt->execute([
    'teacher_id' => $teacherId,
    'semester_id' => $semester['id']
]);
$courses = $stmt->fetchAll();
$branding = get_branding_settings();

// 4. Calculate compliance stats
$totalCourses = count($courses);

$syllabusSubmitted = 0;
$syllabusApproved = 0;
$planSubmitted = 0;
$planApproved = 0;
$matSubmitted = 0;
$matApproved = 0;

foreach ($courses as $c) {
    if ($c['syllabus_status']) {
        $syllabusSubmitted++;
        if ($c['syllabus_status'] === 'approved') {
            $syllabusApproved++;
        }
    }
    if ($c['plan_status']) {
        $planSubmitted++;
        if ($c['plan_status'] === 'approved') {
            $planApproved++;
        }
    }
    if ($c['mat_status']) {
        $matSubmitted++;
        if ($c['mat_status'] === 'approved') {
            $matApproved++;
        }
    }
}

// Syllabus compliance rule: Any number of courses, but show overall progress
$syllabusPercent = $totalCourses > 0 ? round(($syllabusSubmitted / $totalCourses) * 100) : 0;

// Lesson Plan compliance rule: At least 1 course
$planCompliant = $planSubmitted >= 1;

// Teaching Materials compliance rule: At least 1 course
$matCompliant = $matSubmitted >= 1;

// Helper to render badge
function renderStatusBadge(?string $status, ?string $timing = null): string
{
    if (!$status) {
        return '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-500 border border-slate-200">ยังไม่ส่ง</span>';
    }
    $lateBadge = ($timing === 'late') ? ' <span class="text-[10px] text-amber-600 font-extrabold bg-amber-50 px-1.5 py-0.5 rounded border border-amber-200 ml-1 shrink-0">ส่งช้า</span>' : '';
    switch ($status) {
        case 'pending':
            return '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200/70"><span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>รออนุมัติ</span>' . $lateBadge;
        case 'approved':
            return '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-green-50 text-green-700 border border-green-200">อนุมัติแล้ว</span>' . $lateBadge;
        case 'rejected':
            return '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200">ต้องแก้ไข</span>' . $lateBadge;
        default:
            return '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-500 border border-slate-200">ยังไม่ส่ง</span>';
    }
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>แดชบอร์ดครูผู้สอน | <?= htmlspecialchars($branding['system_name']); ?></title>
    <?php if (!empty($branding['logo_path'])): ?>
        <link rel="icon" type="image/png" href="../<?= htmlspecialchars($branding['logo_path']); ?>">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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

<!-- Top Navigation Header -->
<header class="w-full sticky top-0 z-50 bg-gradient-to-r from-indigo-50/80 via-white/95 to-teal-50/80 backdrop-blur-md border-b border-slate-200/85 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-20">
            <a href="../index.php" class="flex items-center gap-3 group no-underline">
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
            
            <div class="flex items-center gap-4">
                <div class="hidden sm:flex flex-col text-right">
                    <span class="text-xs font-bold text-slate-800"><?= e(current_user_fullname()); ?></span>
                    <span class="text-[10px] font-medium text-slate-400">บทบาท: ครูผู้สอน &middot; ภาคเรียน <?= e($semester['semester_name']); ?></span>
                </div>
                <div class="w-[1px] h-8 bg-slate-200 hidden sm:block"></div>
                <a href="../login.php?logout=1"
                   class="inline-flex items-center justify-center gap-2 px-4 py-2 border border-slate-200 hover:bg-slate-50 text-slate-500 hover:text-slate-800 text-xs font-semibold rounded-xl transition">
                    ออกจากระบบ
                </a>
            </div>
        </div>
    </div>
</header>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <!-- User Banner -->
    <div class="bg-gradient-to-r from-teal-50/50 via-cyan-50/50 to-blue-50/50 border border-teal-100 rounded-[28px] p-6 sm:p-8 mb-8 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6 shadow-sm">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-2xl bg-teal-50 text-teal-700 border border-teal-100 flex items-center justify-center text-xl font-bold">
                ครู
            </div>
            <div>
                <h1 class="text-lg sm:text-xl font-black text-slate-800">ยินดีต้อนรับกลับ, <?= e(current_user_fullname()); ?></h1>
                <p class="text-xs text-slate-400 font-medium mt-1">
                    ข้อมูลภาระงานสอนภาคเรียนที่ <span class="text-indigo-650 font-bold"><?= e($semester['semester_name']); ?></span> &middot; วิชาที่สอนทั้งหมด <?= $totalCourses; ?> รายวิชา
                </p>
            </div>
        </div>
        
        <div class="flex flex-wrap gap-3">
            <a href="../index.php" class="inline-flex items-center gap-1.5 px-4 py-2.5 border border-slate-200 hover:bg-slate-50 text-slate-600 text-xs font-bold rounded-xl transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
                <span>หน้าแรกพอร์ทัล</span>
            </a>
        </div>
    </div>

    <?php if ($errorMessage): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl p-4 text-xs sm:text-sm font-bold mb-8 flex items-center gap-2.5 shadow-sm">
            <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span><?= e($errorMessage); ?></span>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['success_flash'])): ?>
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-2xl p-4 text-xs sm:text-sm font-bold mb-8 flex items-center gap-2.5 shadow-sm">
            <svg class="w-5 h-5 text-green-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span><?= e($_SESSION['success_flash']); ?></span>
            <?php unset($_SESSION['success_flash']); ?>
        </div>
    <?php endif; ?>

    <!-- Systems Status Summary Grid -->
    <div class="grid gap-6 md:grid-cols-3 mb-10">
        
        <!-- Syllabus Card -->
        <div class="bg-gradient-to-br from-indigo-50/80 to-indigo-100/30 border border-indigo-200/60 rounded-3xl p-6 shadow-sm hover:shadow-md transition flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-4">
                    <span class="p-2.5 rounded-xl bg-indigo-50 text-indigo-650 border border-indigo-150 shadow-sm">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                    </span>
                    <span class="text-xs font-bold text-slate-400">ระบบส่งโครงการสอน</span>
                </div>
                <h3 class="text-base font-extrabold text-slate-800 mb-2">โครงการสอน (Syllabus)</h3>
                <div class="mb-4">
                    <div class="flex justify-between items-center text-xs text-slate-500 mb-1.5">
                        <span>ความคืบหน้าการส่ง</span>
                        <span class="font-bold text-indigo-700"><?= $syllabusSubmitted; ?> / <?= $totalCourses; ?> วิชา (<?= $syllabusPercent; ?>%)</span>
                    </div>
                    <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                        <div class="bg-indigo-650 h-full rounded-full transition-all duration-300" style="width: <?= $syllabusPercent; ?>%;"></div>
                    </div>
                </div>
            </div>
            <div class="pt-4 border-t border-slate-100 flex flex-col gap-2">
                <a href="submit.php?system_type=course_syllabus" 
                   class="w-full inline-flex items-center justify-center gap-1.5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-xl transition shadow-sm shadow-indigo-600/10">
                    <span>ยื่นส่งโครงการสอน</span>
                </a>
                
                <?php if ($syllabusApproved > 0): ?>
                    <a href="print_memorandum.php"
                       class="w-full inline-flex items-center justify-center gap-1.5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl border border-slate-200 transition">
                        <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.82l-.24-.24c-1.89-1.89-1.89-4.97 0-6.86l2.1-2.1c1.89-1.89 4.97-1.89 6.86 0l1.1 1.1m-11.8 1.1l1.1 1.1m10.7-1.1h.01m-2.1-2.1v.01M6.72 13.82c1.89 1.89 4.97 1.89 6.86 0l2.1-2.1c1.89-1.89 1.89-4.97 0-6.86l-1.1-1.1m-11.8 1.1h.01M16.5 16.5h.01m-2.1-2.1v.01M12 18.75c-3.728 0-6.75-3.022-6.75-6.75h13.5c0 3.728-3.022 6.75-6.75 6.75z"/></svg>
                        <span>พิมพ์บันทึกข้อความรวม (<?= $syllabusApproved; ?> วิชา)</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Lesson Plan Card -->
        <div class="bg-gradient-to-br from-amber-50/80 to-amber-100/30 border border-amber-200/60 rounded-3xl p-6 shadow-sm hover:shadow-md transition flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-4">
                    <span class="p-2.5 rounded-xl bg-amber-50 text-amber-600 border border-amber-100 shadow-sm">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </span>
                    <span class="text-xs font-bold text-slate-400">ระบบส่งแผนการจัดการเรียนรู้</span>
                </div>
                <h3 class="text-base font-extrabold text-slate-800 mb-2">แผนการจัดการเรียนรู้ (Lesson Plan)</h3>
                <div class="mb-4 flex flex-col gap-2">
                    <div class="flex justify-between items-center text-xs text-slate-500">
                        <span>เกณฑ์ขั้นต่ำ: <b>ส่งอย่างน้อย 1 รายวิชา</b></span>
                    </div>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-xs text-slate-500">สถานะของครู:</span>
                        <?php if ($planCompliant): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-green-50 text-green-700 border border-green-200">ผ่านเกณฑ์ (ส่งแล้ว <?= $planSubmitted; ?> วิชา)</span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200">ค้างส่ง (ยังไม่ส่งวิชาใดเลย)</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="pt-4 border-t border-slate-100">
                <a href="submit.php?system_type=lesson_plan" 
                   class="w-full inline-flex items-center justify-center gap-1.5 py-2.5 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold rounded-xl transition shadow-sm shadow-amber-600/10">
                    <span>ยื่นส่งแผนการเรียนรู้</span>
                </a>
            </div>
        </div>

        <!-- Teaching Materials Card -->
        <div class="bg-gradient-to-br from-emerald-50/80 to-emerald-100/30 border border-emerald-200/60 rounded-3xl p-6 shadow-sm hover:shadow-md transition flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-4">
                    <span class="p-2.5 rounded-xl bg-teal-50 text-teal-700 border border-teal-150 shadow-sm">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/></svg>
                    </span>
                    <span class="text-xs font-bold text-slate-400">ระบบส่งสื่อการสอน</span>
                </div>
                <h3 class="text-base font-extrabold text-slate-800 mb-2">สื่อการเรียนการสอน (Materials)</h3>
                <div class="mb-4 flex flex-col gap-2">
                    <div class="flex justify-between items-center text-xs text-slate-500">
                        <span>เกณฑ์ขั้นต่ำ: <b>ส่งอย่างน้อย 1 รายวิชา</b></span>
                    </div>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-xs text-slate-500">สถานะของครู:</span>
                        <?php if ($matCompliant): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-green-50 text-green-700 border border-green-200">ผ่านเกณฑ์ (ส่งแล้ว <?= $matSubmitted; ?> วิชา)</span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200">ค้างส่ง (ยังไม่ส่งวิชาใดเลย)</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="pt-4 border-t border-slate-100">
                <a href="submit.php?system_type=teaching_materials" 
                   class="w-full inline-flex items-center justify-center gap-1.5 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold rounded-xl transition shadow-sm shadow-slate-900/10">
                    <span>ยื่นส่งสื่อการสอน</span>
                </a>
            </div>
        </div>

    </div>

    <!-- Courses Table Card -->
    <div class="bg-white border border-slate-200 rounded-[32px] overflow-hidden shadow-sm">
        <div class="p-6 sm:p-8 border-b border-slate-200 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 class="text-base sm:text-lg font-black text-slate-800">ตารางรายวิชาที่สอนในภาคเรียนที่ <?= e($semester['semester_name']); ?></h2>
                <p class="text-xs text-slate-400 font-medium mt-1">เลือกวิชาและประเภทงานเพื่อยื่นส่งหรือติดตามผลการประเมินจากผู้รับผิดชอบงานวิชาการ</p>
            </div>
            <div>
                <button type="button" onclick="toggleAddCourseForm()"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-black rounded-xl transition shadow-md shadow-slate-900/10">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    <span>+ เพิ่มวิชาสอนด้วยตนเอง</span>
                </button>
            </div>
        </div>
        
        <!-- Collapsible Add Course Form Container -->
        <div id="add_course_form" class="hidden p-6 border-b border-slate-100 bg-slate-50/20">
            <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">เพิ่มรายวิชาที่รับผิดชอบทำแผนการสอนด้วยตนเอง</h3>
            <form method="post" action="dashboard.php" class="grid gap-4 sm:grid-cols-3 items-end">
                <input type="hidden" name="csrf_token" value="<?= e(create_csrf_token()); ?>">
                <input type="hidden" name="action" value="add_course">
                
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2" for="course_code_input">รหัสวิชา</label>
                    <input type="text" name="course_code" id="course_code_input" placeholder="ตัวอย่างเช่น: 20001-2001"
                           class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-700 focus:outline-none focus:border-teal-700 transition"
                           required>
                </div>
                
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2" for="course_name_input">ชื่อวิชาสอน</label>
                    <input type="text" name="course_name" id="course_name_input" placeholder="ตัวอย่างเช่น: คอมพิวเตอร์และสารสนเทศเพื่องานอาชีพ"
                           class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-700 focus:outline-none focus:border-teal-700 transition"
                           required>
                </div>
                
                <div class="flex gap-2">
                    <button type="submit" 
                            class="flex-grow py-3 text-xs font-black text-white bg-slate-900 hover:bg-slate-800 rounded-xl transition shadow-sm">
                        บันทึกวิชาสอน
                    </button>
                    <button type="button" onclick="toggleAddCourseForm()"
                            class="px-4 py-3 text-xs font-bold text-slate-500 border border-slate-200 hover:bg-slate-50 rounded-xl transition">
                        ยกเลิก
                    </button>
                </div>
            </form>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-400 bg-slate-50/20">
                        <th class="py-4 px-6">รหัสวิชา</th>
                        <th class="py-4 px-6">ชื่อรายวิชา</th>
                        <th class="py-4 px-6 text-center">โครงการสอน (Syllabus)</th>
                        <th class="py-4 px-6 text-center">แผนการจัดการเรียนรู้ (Plan)</th>
                        <th class="py-4 px-6 text-center">สื่อการเรียนการสอน (Materials)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-xs sm:text-sm">
                    <?php if ($totalCourses === 0): ?>
                        <tr>
                            <td colspan="5" class="py-12 text-center text-slate-400 font-medium bg-slate-50/10">
                                ไม่พบบัญชีรายวิชาการสอนของคุณในภาคเรียนนี้ กรุณาติดต่อฝ่ายทะเบียน / ผู้ดูแลระบบเพื่อนำเข้าข้อมูล
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($courses as $c): ?>
                            <tr class="hover:bg-slate-50/50 transition">
                                <td class="py-4 px-6 font-bold text-slate-700 tracking-wide">
                                    <div class="flex items-center gap-3">
                                        <span><?= e($c['course_code']); ?></span>
                                        <form method="post" action="dashboard.php" class="inline delete-course-form" data-course-code="<?= e($c['course_code']); ?>" data-course-name="<?= e($c['course_name']); ?>">
                                            <input type="hidden" name="csrf_token" value="<?= e(create_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="delete_course">
                                            <input type="hidden" name="course_id" value="<?= $c['id']; ?>">
                                            <button type="submit" class="text-slate-350 hover:text-rose-600 transition" title="ลบรายวิชานี้">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                                <td class="py-4 px-6 font-semibold text-slate-800"><?= e($c['course_name']); ?></td>
                                
                                <!-- Syllabus Status Column -->
                                <td class="py-4 px-6 text-center">
                                    <div class="flex flex-col items-center gap-1.5">
                                        <?= renderStatusBadge($c['syllabus_status'], $c['syllabus_timing']); ?>
                                        <a href="submit.php?course_id=<?= $c['id']; ?>&system_type=course_syllabus" 
                                           class="text-[10px] font-bold text-teal-700 hover:text-teal-900 underline">
                                            <?= $c['syllabus_status'] ? 'ส่งซ้ำ / แก้ไข' : 'ยื่นส่งเอกสาร'; ?>
                                        </a>
                                    </div>
                                </td>

                                <!-- Lesson Plan Status Column -->
                                <td class="py-4 px-6 text-center">
                                    <div class="flex flex-col items-center gap-1.5">
                                        <?= renderStatusBadge($c['plan_status'], $c['plan_timing']); ?>
                                        <a href="submit.php?course_id=<?= $c['id']; ?>&system_type=lesson_plan" 
                                           class="text-[10px] font-bold text-emerald-700 hover:text-emerald-900 underline">
                                            <?= $c['plan_status'] ? 'ส่งซ้ำ / แก้ไข' : 'ยื่นส่งเอกสาร'; ?>
                                        </a>
                                    </div>
                                </td>

                                <!-- Teaching Materials Status Column -->
                                <td class="py-4 px-6 text-center">
                                    <div class="flex flex-col items-center gap-1.5">
                                        <?= renderStatusBadge($c['mat_status'], $c['mat_timing']); ?>
                                        <a href="submit.php?course_id=<?= $c['id']; ?>&system_type=teaching_materials" 
                                           class="text-[10px] font-bold text-cyan-700 hover:text-cyan-900 underline">
                                            <?= $c['mat_status'] ? 'ส่งซ้ำ / แก้ไข' : 'ยื่นส่งเอกสาร'; ?>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<footer class="py-8 bg-white border-t border-slate-200 mt-16">
    <div class="max-w-7xl mx-auto px-4 text-center text-xs text-slate-400 font-medium">
        &copy; <?= date('Y'); ?> <?= htmlspecialchars($branding['college_name']); ?> &middot; ฝ่ายวิชาการ &middot; สงวนลิขสิทธิ์
    </div>
</footer>

<script>
    function toggleAddCourseForm() {
        const form = document.getElementById('add_course_form');
        if (form.classList.contains('hidden')) {
            form.classList.remove('hidden');
            document.getElementById('course_code_input').focus();
        } else {
            form.classList.add('hidden');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        // 1. Success Message popup
        <?php if ($successMessage): ?>
        Swal.fire({
            title: 'สำเร็จ!',
            text: <?= json_encode($successMessage); ?>,
            icon: 'success',
            confirmButtonColor: '#0f766e', // Teal 700
            confirmButtonText: 'ตกลง',
            customClass: {
                popup: 'rounded-3xl border border-slate-200 shadow-xl font-thai'
            }
        });
        <?php endif; ?>

        // 2. Error Message popup
        <?php if ($errorMessage): ?>
        Swal.fire({
            title: 'เกิดข้อผิดพลาด!',
            text: <?= json_encode($errorMessage); ?>,
            icon: 'error',
            confirmButtonColor: '#e11d48', // Rose 600
            confirmButtonText: 'ตกลง',
            customClass: {
                popup: 'rounded-3xl border border-slate-200 shadow-xl font-thai'
            }
        });
        <?php endif; ?>

        // 3. Intercept Course Deletion Form
        const deleteCourseForms = document.querySelectorAll('.delete-course-form');
        deleteCourseForms.forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const code = this.dataset.courseCode || '';
                const name = this.dataset.courseName || 'รายวิชา';
                Swal.fire({
                    title: '⚠️ ยืนยันการลบรายวิชาสอน?',
                    html: `คุณครูแน่ใจหรือไม่ว่าต้องการลบวิชา <strong>${code} - ${name}</strong> ออกจากรายการสอนในภาคเรียนนี้?<br><br><span class="text-rose-600 font-bold">⚠️ ประวัติและไฟล์เอกสารการยื่นส่งทั้งหมดของวิชานี้จะถูกลบทิ้งอย่างถาวรทันที!</span>`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#e11d48', // Rose 600
                    cancelButtonColor: '#64748b',  // Slate 500
                    confirmButtonText: 'ใช่, ลบรายวิชาออก',
                    cancelButtonText: 'ยกเลิก',
                    customClass: {
                        popup: 'rounded-3xl border border-slate-200 shadow-xl font-thai'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        this.submit();
                    }
                });
            });
        });
    });
</script>
</body>
</html>
