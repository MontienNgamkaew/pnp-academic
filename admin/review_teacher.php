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

$teacherId = isset($_GET['teacher_id']) ? (int) $_GET['teacher_id'] : 0;
if (!$teacherId) {
    redirect_to('overview.php');
}

// 1. Get active semester
$semester = $pdo->query('SELECT id, semester_name FROM semesters WHERE is_active = 1 LIMIT 1')->fetch();
if (!$semester) {
    exit('ไม่พบภาคเรียนที่กำลังเปิดใช้งานในระบบ');
}

// 2. Fetch teacher details
$stmt = $pdo->prepare("SELECT id, username, fullname, department FROM users WHERE id = :teacher_id AND role = 'teacher' LIMIT 1");
$stmt->execute(['teacher_id' => $teacherId]);
$teacher = $stmt->fetch();

if (!$teacher) {
    exit('ไม่พบข้อมูลคุณครูท่านนี้ในระบบวิชาการ');
}

// 3. Fetch courses assigned to this teacher in the active semester, with their submissions
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

// Helper to render badge
function renderReviewStatusBadge(?string $status, ?string $timing = null): string
{
    if (!$status) {
        return '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-455 border border-slate-200">ยังไม่ส่ง</span>';
    }
    $lateBadge = ($timing === 'late') ? ' <span class="text-[10px] text-amber-600 font-extrabold bg-amber-50 px-1.5 py-0.5 rounded border border-amber-200 ml-1 shrink-0">ส่งช้า</span>' : '';
    switch ($status) {
        case 'pending':
            return '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200/70"><span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>รอตรวจ</span>' . $lateBadge;
        case 'approved':
            return '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-green-50 text-green-700 border border-green-200">อนุมัติแล้ว</span>' . $lateBadge;
        case 'rejected':
            return '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200">ส่งแก้ไข</span>' . $lateBadge;
        default:
            return '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-455 border border-slate-200">ยังไม่ส่ง</span>';
    }
}
$branding = get_branding_settings();
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ภารกิจสอนครู: <?= e($teacher['fullname']); ?> | <?= htmlspecialchars($branding['system_name']); ?></title>
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
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
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
            
            <a href="overview.php"
               class="inline-flex items-center justify-center gap-2 px-4 py-2 border border-slate-200 hover:bg-slate-50 text-slate-600 hover:text-slate-800 text-xs font-semibold rounded-xl transition">
                กลับหน้าภาพรวมแอดมิน
            </a>
        </div>
    </div>
</header>

<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-10">
    
    <!-- Header Banner -->
    <div class="bg-white border border-slate-200 rounded-[28px] p-6 sm:p-8 mb-8 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6 shadow-sm">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-2xl bg-teal-50 text-teal-700 border border-teal-100 flex items-center justify-center text-xl font-bold">
                ครู
            </div>
            <div>
                <h1 class="text-lg sm:text-xl font-black text-slate-800">วิเคราะห์ผลงานและสถิติการยื่นส่งงานครูรายบุคคล</h1>
                <p class="text-xs text-slate-400 font-medium mt-1">
                    ครูผู้สอน: <span class="text-indigo-650 font-bold"><?= e($teacher['fullname']); ?></span> (@<?= e($teacher['username']); ?><?= !empty($teacher['department']) ? ' &middot; แผนกวิชา' . e($teacher['department']) : ''; ?>) &middot; วิชาในความดูแลทั้งหมด <?= count($courses); ?> วิชา
                </p>
            </div>
        </div>
    </div>

    <!-- Courses detailed status grid -->
    <div class="bg-white border border-slate-200 rounded-[32px] overflow-hidden shadow-sm">
        <div class="p-6 sm:p-8 border-b border-slate-200 bg-slate-50/50">
            <h2 class="text-base sm:text-lg font-black text-slate-800">ตารางรายวิชาทั้งหมดพร้อมลิงก์ตรวจงานโดยตรง</h2>
            <p class="text-xs text-slate-400 font-medium mt-1">
                คลิกลิงก์ "เข้าตรวจผลงาน" ถัดจาก Badges เพื่อเข้าไปประเมินผล ให้คำแนะนำ และปรับผลการประเมิน
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-400 bg-slate-50/20">
                        <th class="py-4 px-6">รหัสวิชา</th>
                        <th class="py-4 px-6">ชื่อวิชาสอน</th>
                        <th class="py-4 px-6 text-center">โครงการสอน (Syllabus)</th>
                        <th class="py-4 px-6 text-center">แผนการจัดการเรียนรู้ (Plan)</th>
                        <th class="py-4 px-6 text-center">สื่อการเรียนการสอน (Materials)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-xs sm:text-sm">
                    <?php if (count($courses) === 0): ?>
                        <tr>
                            <td colspan="5" class="py-12 text-center text-slate-400 font-medium bg-slate-50/10">
                                คุณครูท่านนี้ยังไม่ได้รับมอบหมายรายวิชาสอนใดๆ ในภาคเรียนนี้
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($courses as $c): ?>
                            <tr class="hover:bg-slate-50/50 transition">
                                <td class="py-4 px-6 font-bold text-slate-700 tracking-wide font-outfit"><?= e($c['course_code']); ?></td>
                                <td class="py-4 px-6 font-semibold text-slate-800"><?= e($c['course_name']); ?></td>
                                
                                <!-- Syllabus Status and Action -->
                                <td class="py-4 px-6 text-center">
                                    <div class="flex flex-col items-center gap-1.5">
                                        <?= renderReviewStatusBadge($c['syllabus_status'], $c['syllabus_timing']); ?>
                                        <?php if ($c['syllabus_sub_id']): ?>
                                            <a href="review.php?submission_id=<?= $c['syllabus_sub_id']; ?>" 
                                               class="text-[10px] font-bold text-teal-700 hover:text-teal-900 underline">
                                                เข้าตรวจผลงาน &rarr;
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Lesson Plan Status and Action -->
                                <td class="py-4 px-6 text-center">
                                    <div class="flex flex-col items-center gap-1.5">
                                        <?= renderReviewStatusBadge($c['plan_status'], $c['plan_timing']); ?>
                                        <?php if ($c['plan_sub_id']): ?>
                                            <a href="review.php?submission_id=<?= $c['plan_sub_id']; ?>" 
                                               class="text-[10px] font-bold text-emerald-700 hover:text-emerald-900 underline">
                                                เข้าตรวจผลงาน &rarr;
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Materials Status and Action -->
                                <td class="py-4 px-6 text-center">
                                    <div class="flex flex-col items-center gap-1.5">
                                        <?= renderReviewStatusBadge($c['mat_status'], $c['mat_timing']); ?>
                                        <?php if ($c['mat_sub_id']): ?>
                                            <a href="review.php?submission_id=<?= $c['mat_sub_id']; ?>" 
                                               class="text-[10px] font-bold text-cyan-700 hover:text-cyan-900 underline">
                                                เข้าตรวจผลงาน &rarr;
                                            </a>
                                        <?php endif; ?>
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

</body>
</html>
