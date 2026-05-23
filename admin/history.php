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

$errorMessage = '';
$successMessage = '';



// 1. Get semesters for history view
$semestersList = $pdo->query('SELECT id, semester_name, is_active FROM semesters ORDER BY id DESC')->fetchAll();
$selectedSemId = isset($_GET['sem_id']) ? (int)$_GET['sem_id'] : 0;

$semester = null;
if ($selectedSemId > 0) {
    foreach ($semestersList as $s) {
        if ((int)$s['id'] === $selectedSemId) {
            $semester = $s;
            break;
        }
    }
}
if (!$semester) {
    $semester = $semestersList[0] ?? null;
}
if (!$semester) {
    exit('ไม่พบข้อมูลภาคเรียนในระบบ');
}

// 2. Fetch all teachers
$stmt = $pdo->prepare("SELECT id, username, fullname, department FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY fullname ASC");
$stmt->execute();
$teachers = $stmt->fetchAll();

// 3. For each teacher, calculate compliance statuses
$teacherData = [];
foreach ($teachers as $t) {
    $tId = (int)$t['id'];
    
    // Fetch courses assigned to this teacher in active semester
    $stmt = $pdo->prepare('SELECT id, course_code, course_name FROM courses WHERE teacher_id = :teacher_id AND semester_id = :semester_id');
    $stmt->execute(['teacher_id' => $tId, 'semester_id' => $semester['id']]);
    $teacherCourses = $stmt->fetchAll();
    $courseCount = count($teacherCourses);
    
    // Default statuses
    $syllabusStatus = 'missing'; // 'missing', 'pending', 'approved', 'rejected', 'incomplete'
    $planStatus = 'missing';
    $matStatus = 'missing';
    
    $syllabusIsLate = false;
    $planIsLate = false;
    $matIsLate = false;
    
    $syllabusSubIds = [];
    $planSubIds = [];
    $matSubIds = [];
    
    if ($courseCount > 0) {
        $cIds = array_map(fn($c) => (int)$c['id'], $teacherCourses);
        $inQuery = implode(',', $cIds);
        
        // Latest syllabus submissions
        $syllabuses = $pdo->query("
            SELECT s1.* FROM submissions s1
            INNER JOIN (
                SELECT MAX(id) as max_id FROM submissions WHERE system_type = 'course_syllabus' AND course_id IN ($inQuery) GROUP BY course_id
            ) s2 ON s1.id = s2.max_id
        ")->fetchAll();
        
        // Latest lesson plan submissions
        $plans = $pdo->query("
            SELECT s1.* FROM submissions s1
            INNER JOIN (
                SELECT MAX(id) as max_id FROM submissions WHERE system_type = 'lesson_plan' AND course_id IN ($inQuery) GROUP BY course_id
            ) s2 ON s1.id = s2.max_id
        ")->fetchAll();
        
        // Latest teaching materials submissions
        $mats = $pdo->query("
            SELECT s1.* FROM submissions s1
            INNER JOIN (
                SELECT MAX(id) as max_id FROM submissions WHERE system_type = 'teaching_materials' AND course_id IN ($inQuery) GROUP BY course_id
            ) s2 ON s1.id = s2.max_id
        ")->fetchAll();
        
        // Map sub IDs for quick links
        foreach ($syllabuses as $s) { $syllabusSubIds[$s['course_id']] = $s; }
        foreach ($plans as $p) { $planSubIds[$p['course_id']] = $p; }
        foreach ($mats as $m) { $matSubIds[$m['course_id']] = $m; }
        
        // Calculate Syllabus compliance: Must submit for ALL courses
        $syllabusAppCount = 0;
        $syllabusPendCount = 0;
        $syllabusRejCount = 0;
        foreach ($syllabuses as $s) {
            if ($s['status'] === 'approved') $syllabusAppCount++;
            if ($s['status'] === 'pending') $syllabusPendCount++;
            if ($s['status'] === 'rejected') $syllabusRejCount++;
        }
        
        $syllabusIsLate = false;
        if ($syllabusAppCount === $courseCount) {
            $syllabusStatus = 'approved';
            foreach ($syllabuses as $s) {
                if ($s['status'] === 'approved' && $s['submission_timing'] === 'late') {
                    $syllabusIsLate = true;
                    break;
                }
            }
        } elseif ($syllabusPendCount > 0) {
            $syllabusStatus = 'pending';
            foreach ($syllabuses as $s) {
                if ($s['submission_timing'] === 'late') {
                    $syllabusIsLate = true;
                    break;
                }
            }
        } elseif ($syllabusRejCount > 0) {
            $syllabusStatus = 'rejected';
        } elseif (count($syllabuses) > 0) {
            $syllabusStatus = 'incomplete';
        }
        
        // Plan compliance: At least 1 course
        $planAppCount = 0;
        $planPendCount = 0;
        $planRejCount = 0;
        $planApprovedSubs = [];
        $planPendingSubs = [];
        foreach ($plans as $p) {
            if ($p['status'] === 'approved') {
                $planAppCount++;
                $planApprovedSubs[] = $p;
            }
            if ($p['status'] === 'pending') {
                $planPendCount++;
                $planPendingSubs[] = $p;
            }
            if ($p['status'] === 'rejected') $planRejCount++;
        }
        
        $planIsLate = false;
        if ($planAppCount >= 1) {
            $planStatus = 'approved';
            $allLate = true;
            foreach ($planApprovedSubs as $p) {
                if ($p['submission_timing'] !== 'late') {
                    $allLate = false;
                    break;
                }
            }
            $planIsLate = $allLate;
        } elseif ($planPendCount >= 1) {
            $planStatus = 'pending';
            $allLate = true;
            foreach ($planPendingSubs as $p) {
                if ($p['submission_timing'] !== 'late') {
                    $allLate = false;
                    break;
                }
            }
            $planIsLate = $allLate;
        } elseif ($planRejCount >= 1) {
            $planStatus = 'rejected';
        } elseif (count($plans) >= 1) {
            $planStatus = 'incomplete';
        }
        
        // Materials compliance: At least 1 course
        $matAppCount = 0;
        $matPendCount = 0;
        $matRejCount = 0;
        $matApprovedSubs = [];
        $matPendingSubs = [];
        foreach ($mats as $m) {
            if ($m['status'] === 'approved') {
                $matAppCount++;
                $matApprovedSubs[] = $m;
            }
            if ($m['status'] === 'pending') {
                $matPendCount++;
                $matPendingSubs[] = $m;
            }
            if ($m['status'] === 'rejected') $matRejCount++;
        }
        
        $matIsLate = false;
        if ($matAppCount >= 1) {
            $matStatus = 'approved';
            $allLate = true;
            foreach ($matApprovedSubs as $m) {
                if ($m['submission_timing'] !== 'late') {
                    $allLate = false;
                    break;
                }
            }
            $matIsLate = $allLate;
        } elseif ($matPendCount >= 1) {
            $matStatus = 'pending';
            $allLate = true;
            foreach ($matPendingSubs as $m) {
                if ($m['submission_timing'] !== 'late') {
                    $allLate = false;
                    break;
                }
            }
            $matIsLate = $allLate;
        } elseif ($matRejCount >= 1) {
            $matStatus = 'rejected';
        } elseif (count($mats) >= 1) {
            $matStatus = 'incomplete';
        }
    }
    
    // Calculate submitted and missing counts for sorting
    $submittedSystemsCount = 0;
    $missingSystemsCount = 0;
    
    if (in_array($syllabusStatus, ['approved', 'pending'])) {
        $submittedSystemsCount++;
    } else {
        $missingSystemsCount++;
    }
    
    if (in_array($planStatus, ['approved', 'pending'])) {
        $submittedSystemsCount++;
    } else {
        $missingSystemsCount++;
    }
    
    if (in_array($matStatus, ['approved', 'pending'])) {
        $submittedSystemsCount++;
    } else {
        $missingSystemsCount++;
    }
    
    $teacherData[] = [
        'teacher' => $t,
        'courses' => $teacherCourses,
        'course_count' => $courseCount,
        'syllabus_status' => $syllabusStatus,
        'syllabus_is_late' => $syllabusIsLate,
        'plan_status' => $planStatus,
        'plan_is_late' => $planIsLate,
        'mat_status' => $matStatus,
        'mat_is_late' => $matIsLate,
        'syllabuses' => $syllabusSubIds,
        'plans' => $planSubIds,
        'mats' => $matSubIds,
        'submitted_systems_count' => $submittedSystemsCount,
        'missing_systems_count' => $missingSystemsCount
    ];
}

// 3.5 Sorting logic for $teacherData
$sortBy = $_GET['sort_by'] ?? 'name';
$sortDir = $_GET['sort_dir'] ?? 'asc';

if (!in_array($sortBy, ['name', 'department', 'submitted', 'missing', 'courses'])) {
    $sortBy = 'name';
}
if (!in_array($sortDir, ['asc', 'desc'])) {
    $sortDir = ($sortBy === 'name') ? 'asc' : 'desc';
}

usort($teacherData, function($a, $b) use ($sortBy, $sortDir) {
    if ($sortBy === 'name') {
        $valA = $a['teacher']['fullname'];
        $valB = $b['teacher']['fullname'];
        $cmp = strcmp($valA, $valB);
        if ($cmp === 0) return 0;
        return ($sortDir === 'asc') ? $cmp : -$cmp;
    } elseif ($sortBy === 'department') {
        $valA = $a['teacher']['department'] ?? '';
        $valB = $b['teacher']['department'] ?? '';
        $cmp = strcmp($valA, $valB);
        if ($cmp === 0) return 0;
        return ($sortDir === 'asc') ? $cmp : -$cmp;
    } elseif ($sortBy === 'submitted') {
        $valA = $a['submitted_systems_count'];
        $valB = $b['submitted_systems_count'];
        if ($valA === $valB) {
            return strcmp($a['teacher']['fullname'], $b['teacher']['fullname']);
        }
        return ($sortDir === 'asc') ? ($valA <=> $valB) : ($valB <=> $valA);
    } elseif ($sortBy === 'missing') {
        $valA = $a['missing_systems_count'];
        $valB = $b['missing_systems_count'];
        if ($valA === $valB) {
            return strcmp($a['teacher']['fullname'], $b['teacher']['fullname']);
        }
        return ($sortDir === 'asc') ? ($valA <=> $valB) : ($valB <=> $valA);
    } elseif ($sortBy === 'courses') {
        $valA = $a['course_count'];
        $valB = $b['course_count'];
        if ($valA === $valB) {
            return strcmp($a['teacher']['fullname'], $b['teacher']['fullname']);
        }
        return ($sortDir === 'asc') ? ($valA <=> $valB) : ($valB <=> $valA);
    }
    return 0;
});

// 4. Calculate high-level admin metrics
$totalTeachersCount = count($teachers);
$fullyCompliantCount = 0;
$pendingReviewCount = 0;

foreach ($teacherData as $td) {
    if ($td['syllabus_status'] === 'approved' && $td['plan_status'] === 'approved' && $td['mat_status'] === 'approved') {
        $fullyCompliantCount++;
    }
    
    // Check if there are any pending reviews for this teacher's items
    foreach ($td['syllabuses'] as $s) { if ($s['status'] === 'pending') $pendingReviewCount++; }
    foreach ($td['plans'] as $p) { if ($p['status'] === 'pending') $pendingReviewCount++; }
    foreach ($td['mats'] as $m) { if ($m['status'] === 'pending') $pendingReviewCount++; }
}

// 5. Group and calculate Departmental metrics
$departmentData = [];
foreach ($teacherData as $td) {
    $dept = trim($td['teacher']['department'] ?? '');
    if ($dept === '') {
        $dept = 'ไม่ระบุแผนกวิชา';
    }
    
    if (!isset($departmentData[$dept])) {
        $departmentData[$dept] = [
            'name' => $dept,
            'total_teachers' => 0,
            'compliant_teachers' => 0,
            'total_courses' => 0,
            'syllabus_approved' => 0,
            'syllabus_pending' => 0,
            'syllabus_missing' => 0,
            'plan_approved_teachers' => 0,
            'materials_approved_teachers' => 0,
        ];
    }
    
    $departmentData[$dept]['total_teachers']++;
    $departmentData[$dept]['total_courses'] += $td['course_count'];
    
    // Check if fully compliant
    if ($td['syllabus_status'] === 'approved' && $td['plan_status'] === 'approved' && $td['mat_status'] === 'approved') {
        $departmentData[$dept]['compliant_teachers']++;
    }
    
    // Check plan approved
    if ($td['plan_status'] === 'approved') {
        $departmentData[$dept]['plan_approved_teachers']++;
    }
    
    // Check materials approved
    if ($td['mat_status'] === 'approved') {
        $departmentData[$dept]['materials_approved_teachers']++;
    }
    
    // Calculate syllabus approved courses
    foreach ($td['courses'] as $c) {
        $sub = $td['syllabuses'][$c['id']] ?? null;
        if ($sub && $sub['status'] === 'approved') {
            $departmentData[$dept]['syllabus_approved']++;
        } elseif ($sub && $sub['status'] === 'pending') {
            $departmentData[$dept]['syllabus_pending']++;
        } else {
            $departmentData[$dept]['syllabus_missing']++;
        }
    }
}
ksort($departmentData);

$viewTab = $_GET['view_tab'] ?? 'individual';
if ($viewTab !== 'individual' && $viewTab !== 'department') {
    $viewTab = 'individual';
}
$branding = get_branding_settings();

// Status badge helper for compliance overview
function renderComplianceBadge(string $status, string $labelType, bool $isLate = false): string
{
    switch ($status) {
        case 'approved':
            if ($isLate) {
                return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-800 border border-amber-200">ส่งครบ (ไม่ตามกำหนดเวลา)</span>';
            }
            return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-green-50 text-green-700 border border-green-200">ส่งครบถ้วน</span>';
        case 'pending':
            if ($isLate) {
                return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200"><span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>รอตรวจสอบ (ส่งช้า)</span>';
            }
            return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200"><span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>รอตรวจสอบ</span>';
        case 'rejected':
            return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200">ต้องปรับปรุง</span>';
        case 'incomplete':
            $incompleteText = ($labelType === 'syllabus') ? 'ยังส่งไม่ครบ' : 'ไม่ถึงเกณฑ์';
            return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-orange-50 text-orange-700 border border-orange-200">' . $incompleteText . '</span>';
        case 'missing':
        default:
            return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-450 border border-slate-200">ค้างส่ง</span>';
    }
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ประวัติภาคเรียนย้อนหลัง | <?= htmlspecialchars($branding['system_name']); ?></title>
    <?php if (!empty($branding['logo_path'])): ?>
        <link rel="icon" type="image/png" href="../<?= htmlspecialchars($branding['logo_path']); ?>">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            
            <nav class="hidden md:flex items-center gap-6 text-xs font-bold text-slate-500">
                <a href="overview.php" class="text-teal-700 font-black">ภาพรวมความคืบหน้า</a>
                <a href="settings.php" class="hover:text-slate-800">ตั้งค่าเดดไลน์ระบบ</a>
                <a href="import.php" class="hover:text-slate-800">นำเข้าและจัดการข้อมูล</a>
            </nav>
            
            <div class="flex items-center gap-4">
                <div class="hidden sm:flex flex-col text-right">
                    <span class="text-xs font-bold text-slate-800"><?= e(current_user_fullname()); ?></span>
                    <span class="text-[10px] font-medium text-slate-400">สิทธิ์: ผู้ดูแลระบบ &middot; ภาคเรียน <?= e($semester['semester_name']); ?></span>
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
    
    <!-- Admin Hero Welcome Section -->
    <div class="bg-gradient-to-r from-teal-50/50 via-cyan-50/50 to-blue-50/50 border border-teal-100 rounded-[28px] p-6 sm:p-8 mb-8 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6 shadow-sm">
        <div>
            <h1 class="text-lg sm:text-xl font-black text-slate-800">แดชบอร์ดติดตามภารกิจวิชาการ</h1>
            <p class="text-xs text-slate-400 font-medium mt-1">
                ภาคเรียนที่ <span class="text-indigo-650 font-bold"><?= e($semester['semester_name']); ?></span> &middot; ตรวจจับข้อมูลครู ส่งเอกสาร ตรวจประเมิน อนุมัติ และพิมพ์รายงานผลลัพธ์
            </p>
        </div>
        
        <div class="flex flex-wrap gap-2.5">
            <a href="print_report.php" class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold rounded-xl transition shadow-md shadow-slate-900/10">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.82l-.24-.24c-1.89-1.89-1.89-4.97 0-6.86l2.1-2.1c1.89-1.89 4.97-1.89 6.86 0l1.1 1.1m-11.8 1.1l1.1 1.1m10.7-1.1h.01m-2.1-2.1v.01M6.72 13.82c1.89 1.89 4.97 1.89 6.86 0l2.1-2.1c1.89-1.89 1.89-4.97 0-6.86l-1.1-1.1m-11.8 1.1h.01M16.5 16.5h.01m-2.1-2.1v.01M12 18.75c-3.728 0-6.75-3.022-6.75-6.75h13.5c0 3.728-3.022 6.75-6.75 6.75z"/></svg>
                <span>จัดพิมพ์รายงาน A4</span>
            </a>
            <a href="settings.php" class="inline-flex items-center gap-1.5 px-4 py-2.5 border border-slate-200 hover:bg-slate-50 text-slate-600 text-xs font-bold rounded-xl transition">
                <span>จัดการเดดไลน์ระบบ</span>
            </a>
        </div>
    </div>

    <!-- Metrics Cards Row -->
    <div class="grid gap-6 grid-cols-2 md:grid-cols-4 mb-8">
        
        <!-- Total Teachers -->
        <div class="bg-gradient-to-br from-indigo-50 to-indigo-100 border border-indigo-200/60 rounded-3xl p-5 shadow-sm">
            <span class="text-xs font-bold text-slate-500 uppercase tracking-widest block mb-1">ครูผู้สอนทั้งหมด</span>
            <span class="text-4xl font-black text-indigo-900 block leading-none mb-1"><?= $totalTeachersCount; ?></span>
            <span class="text-[10px] text-slate-500 font-medium">บัญชีมีผลในเทอมนี้</span>
        </div>

        <!-- Fully Compliant Teachers -->
        <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 border border-emerald-200/60 rounded-3xl p-5 shadow-sm">
            <span class="text-xs font-bold text-slate-500 uppercase tracking-widest block mb-1">ส่งครบ 3 ภารกิจ</span>
            <span class="text-4xl font-black text-emerald-700 block leading-none mb-1"><?= $fullyCompliantCount; ?></span>
            <span class="text-[10px] text-slate-500 font-medium">คิดเป็น <?= $totalTeachersCount > 0 ? round(($fullyCompliantCount / $totalTeachersCount) * 100) : 0; ?>% ของครูทั้งหมด</span>
        </div>

        <!-- Pending Review Items -->
        <div class="bg-gradient-to-br from-amber-50 to-amber-100 border border-amber-200/60 rounded-3xl p-5 shadow-sm">
            <span class="text-xs font-bold text-slate-500 uppercase tracking-widest block mb-1">เอกสารรอนุมัติ</span>
            <span class="text-4xl font-black text-amber-700 block leading-none mb-1"><?= $pendingReviewCount; ?></span>
            <span class="text-[10px] text-slate-500 font-medium">ต้องเร่งเข้าตรวจสอบ</span>
        </div>

        <!-- Semester ID -->
        <div class="bg-gradient-to-br from-slate-50 to-slate-100 border border-slate-200/60 rounded-3xl p-5 shadow-sm">
            <span class="text-xs font-bold text-slate-500 uppercase tracking-widest block mb-1">สถานะระบบ</span>
            <span class="text-2xl font-black text-slate-800 block leading-tight mb-1 truncate">เปิดรับเอกสารปกติ</span>
            <span class="text-[10px] text-slate-500 font-medium">เดดไลน์ทำงานอัตโนมัติ</span>
        </div>

    </div>
    
    <!-- Deep Dive Analytics Charts Section -->
    <?php
    // Calculate global status for each system
    $sysSyllabus = ['approved' => 0, 'pending' => 0, 'missing' => 0];
    $sysPlan = ['approved' => 0, 'pending' => 0, 'missing' => 0];
    $sysMat = ['approved' => 0, 'pending' => 0, 'missing' => 0];

    foreach ($teacherData as $td) {
        if (in_array($td['syllabus_status'], ['approved'])) $sysSyllabus['approved']++;
        elseif ($td['syllabus_status'] === 'pending') $sysSyllabus['pending']++;
        else $sysSyllabus['missing']++;
        
        if (in_array($td['plan_status'], ['approved'])) $sysPlan['approved']++;
        elseif ($td['plan_status'] === 'pending') $sysPlan['pending']++;
        else $sysPlan['missing']++;
        
        if (in_array($td['mat_status'], ['approved'])) $sysMat['approved']++;
        elseif ($td['mat_status'] === 'pending') $sysMat['pending']++;
        else $sysMat['missing']++;
    }
    ?>
    <div class="bg-gradient-to-br from-indigo-50/40 to-fuchsia-50/40 border border-indigo-100 rounded-[32px] p-6 sm:p-8 mb-8 shadow-sm">
        <h2 class="text-lg font-black text-slate-800 mb-6">ข้อมูลเชิงลึกการยื่นส่งงานวิชาการ 3 ระบบ</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="flex flex-col items-center">
                <span class="text-sm font-bold text-slate-600 mb-4">โครงการสอน (Syllabus)</span>
                <div class="w-48 h-48 relative">
                    <canvas id="chartSyllabus"></canvas>
                </div>
            </div>
            <div class="flex flex-col items-center">
                <span class="text-sm font-bold text-slate-600 mb-4">แผนการจัดการเรียนรู้ (Lesson Plan)</span>
                <div class="w-48 h-48 relative">
                    <canvas id="chartPlan"></canvas>
                </div>
            </div>
            <div class="flex flex-col items-center">
                <span class="text-sm font-bold text-slate-600 mb-4">สื่อการเรียนการสอน (Materials)</span>
                <div class="w-48 h-48 relative">
                    <canvas id="chartMat"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const createChart = (ctxId, data) => {
            const ctx = document.getElementById(ctxId).getContext('2d');
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['ตรวจผ่านแล้ว', 'รอตรวจสอบ', 'ค้างส่ง/ไม่ผ่าน'],
                    datasets: [{
                        data: data,
                        backgroundColor: ['#10b981', '#f59e0b', '#f1f5f9'],
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { font: { family: '"IBM Plex Sans Thai", sans-serif', size: 10 } } }
                    },
                    cutout: '70%'
                }
            });
        };
        
        createChart('chartSyllabus', [<?= $sysSyllabus['approved']; ?>, <?= $sysSyllabus['pending']; ?>, <?= $sysSyllabus['missing']; ?>]);
        createChart('chartPlan', [<?= $sysPlan['approved']; ?>, <?= $sysPlan['pending']; ?>, <?= $sysPlan['missing']; ?>]);
        createChart('chartMat', [<?= $sysMat['approved']; ?>, <?= $sysMat['pending']; ?>, <?= $sysMat['missing']; ?>]);
    });
    </script>

    <!-- Dynamic Premium Tabs Switcher -->
    <div class="flex border border-slate-200 mb-6 bg-white/60 p-1.5 rounded-2xl max-w-md shadow-sm">
        <a href="?view_tab=individual" 
           class="flex-1 text-center py-2.5 text-xs font-black rounded-xl transition <?= $viewTab === 'individual' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>">
            ความคืบหน้ารายบุคคล
        </a>
        <a href="?view_tab=department" 
           class="flex-1 text-center py-2.5 text-xs font-black rounded-xl transition <?= $viewTab === 'department' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>">
            สรุปภาพรวมรายแผนกวิชา
        </a>
    </div>

    <?php if ($viewTab === 'individual'): ?>
        <!-- Tab 1: Individual compliance progress grid -->
        <div class="bg-gradient-to-br from-slate-50/50 to-slate-100/50 border border-slate-200 rounded-[32px] overflow-hidden shadow-sm">
            <div class="p-6 sm:p-8 border-b border-slate-200 bg-slate-50/50">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base sm:text-lg font-black text-slate-800">ตารางวิเคราะห์ความพร้อมและการยื่นส่งเอกสารวิชาการรายบุคคล</h2>
                        <p class="text-xs text-slate-400 font-medium mt-1">
                            คลิกวิชาเดี่ยวๆ ในรายการเพื่อตรวจสอบประวัติรายละเอียดลิงก์ เอกสารประทับเวลาส่งล่าช้า อนุมัติผลงาน หรือส่งคำสั่งกลับแก้ไข
                        </p>
                    </div>
                    
                    <!-- Sorting control bar -->
                    <div class="flex flex-wrap items-center gap-2 bg-slate-100 p-1.5 rounded-xl border border-slate-200/60 shadow-inner shrink-0 self-start md:self-auto">
                        <span class="text-[10px] font-bold text-slate-500 px-2 font-thai">จัดเรียงตาม:</span>
                        
                        <!-- Sort by Department -->
                        <a href="?view_tab=individual&sort_by=department&sort_dir=<?= ($sortBy === 'department' && $sortDir === 'asc') ? 'desc' : 'asc' ?>" 
                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-black transition-all <?= $sortBy === 'department' ? 'bg-white text-slate-800 shadow-sm border border-slate-200' : 'text-slate-500 hover:text-slate-800' ?>"
                           title="เรียงตามแผนกวิชา">
                            <span>แผนกวิชา</span>
                            <?php if ($sortBy === 'department'): ?>
                                <svg class="w-3 h-3 text-slate-800" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $sortDir === 'asc' ? 'M4.5 15.75l7.5-7.5 7.5 7.5' : 'M19.5 8.25l-7.5 7.5-7.5-7.5' ?>"/></svg>
                            <?php endif; ?>
                        </a>
                        <!-- Sort by Name -->
                        <a href="?view_tab=individual&sort_by=name&sort_dir=<?= ($sortBy === 'name' && $sortDir === 'asc') ? 'desc' : 'asc' ?>" 
                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-black transition-all <?= $sortBy === 'name' ? 'bg-white text-slate-800 shadow-sm border border-slate-200' : 'text-slate-500 hover:text-slate-800' ?>"
                           title="เรียงตามชื่อครูผู้สอน">
                            <span>ชื่อครู</span>
                            <?php if ($sortBy === 'name'): ?>
                                <span class="text-[9px]"><?= $sortDir === 'asc' ? '▲' : '▼' ?></span>
                            <?php endif; ?>
                        </a>
                        
                        <!-- Sort by Submitted -->
                        <a href="?view_tab=individual&sort_by=submitted&sort_dir=<?= ($sortBy === 'submitted' && $sortDir === 'desc') ? 'asc' : 'desc' ?>" 
                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-black transition-all <?= $sortBy === 'submitted' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>"
                           title="เรียงตามผู้ส่งงานครบถ้วนที่สุด (คนส่ง)">
                            <span>คนส่ง</span>
                            <?php if ($sortBy === 'submitted'): ?>
                                <span class="text-[9px]"><?= $sortDir === 'desc' ? '▼' : '▲' ?></span>
                            <?php endif; ?>
                        </a>
                        
                        <!-- Sort by Missing -->
                        <a href="?view_tab=individual&sort_by=missing&sort_dir=<?= ($sortBy === 'missing' && $sortDir === 'desc') ? 'asc' : 'desc' ?>" 
                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-black transition-all <?= $sortBy === 'missing' ? 'bg-rose-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800' ?>"
                           title="เรียงตามผู้ค้างส่งงานมากที่สุด (ค้างส่ง)">
                            <span>ค้างส่ง</span>
                            <?php if ($sortBy === 'missing'): ?>
                                <span class="text-[9px]"><?= $sortDir === 'desc' ? '▼' : '▲' ?></span>
                            <?php endif; ?>
                        </a>
                        
                        <!-- Sort by Courses Count -->
                        <a href="?view_tab=individual&sort_by=courses&sort_dir=<?= ($sortBy === 'courses' && $sortDir === 'desc') ? 'asc' : 'desc' ?>" 
                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-black transition-all <?= $sortBy === 'courses' ? 'bg-white text-slate-800 shadow-sm border border-slate-200' : 'text-slate-500 hover:text-slate-800' ?>"
                           title="เรียงตามจำนวนวิชาที่จัดสอน">
                            <span>วิชาสอน</span>
                            <?php if ($sortBy === 'courses'): ?>
                                <span class="text-[9px]"><?= $sortDir === 'desc' ? '▼' : '▲' ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-400 bg-slate-50/20">
                            <th class="py-4 px-6">
                                <a href="?view_tab=individual&sort_by=name&sort_dir=<?= ($sortBy === 'name' && $sortDir === 'asc') ? 'desc' : 'asc' ?>" class="hover:text-slate-800 inline-flex items-center gap-1">
                                    <span>ชื่อครูผู้สอน</span>
                                    <?php if ($sortBy === 'name'): ?>
                                        <span class="text-[9px]"><?= $sortDir === 'asc' ? '▲' : '▼' ?></span>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="py-4 px-6 text-center">
                                <a href="?view_tab=individual&sort_by=courses&sort_dir=<?= ($sortBy === 'courses' && $sortDir === 'desc') ? 'asc' : 'desc' ?>" class="hover:text-slate-800 inline-flex items-center gap-1 justify-center w-full">
                                    <span>จำนวนวิชาสอน</span>
                                    <?php if ($sortBy === 'courses'): ?>
                                        <span class="text-[9px]"><?= $sortDir === 'desc' ? '▼' : '▲' ?></span>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="py-4 px-6 text-center">
                                <a href="?view_tab=individual&sort_by=submitted&sort_dir=<?= ($sortBy === 'submitted' && $sortDir === 'desc') ? 'asc' : 'desc' ?>" class="hover:text-slate-800 inline-flex items-center gap-1 justify-center w-full">
                                    <span>โครงการสอน (Syllabus)</span>
                                    <?php if ($sortBy === 'submitted'): ?>
                                        <span class="text-[9px]"><?= $sortDir === 'desc' ? '▼' : '▲' ?></span>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="py-4 px-6 text-center">
                                <a href="?view_tab=individual&sort_by=submitted&sort_dir=<?= ($sortBy === 'submitted' && $sortDir === 'desc') ? 'asc' : 'desc' ?>" class="hover:text-slate-800 inline-flex items-center gap-1 justify-center w-full">
                                    <span>แผนการจัดการเรียนรู้ (Plan)</span>
                                    <?php if ($sortBy === 'submitted'): ?>
                                        <span class="text-[9px]"><?= $sortDir === 'desc' ? '▼' : '▲' ?></span>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="py-4 px-6 text-center">
                                <a href="?view_tab=individual&sort_by=submitted&sort_dir=<?= ($sortBy === 'submitted' && $sortDir === 'desc') ? 'asc' : 'desc' ?>" class="hover:text-slate-800 inline-flex items-center gap-1 justify-center w-full">
                                    <span>สื่อการเรียนการสอน (Materials)</span>
                                    <?php if ($sortBy === 'submitted'): ?>
                                        <span class="text-[9px]"><?= $sortDir === 'desc' ? '▼' : '▲' ?></span>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th class="py-4 px-6 text-center text-slate-400">ตัวเลือก/จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs sm:text-sm">
                        <?php if ($totalTeachersCount === 0): ?>
                            <tr>
                                <td colspan="6" class="py-12 text-center text-slate-400 font-medium bg-slate-50/10">
                                    ยังไม่มีข้อมูลบัญชีคุณครูเข้าระบบ กรุณานำเข้ารายชื่อผ่านฟังก์ชันนำเข้า CSV
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($teacherData as $td): ?>
                                <tr class="hover:bg-slate-50/50 transition">
                                    <td class="py-4 px-6">
                                        <div class="flex flex-col">
                                            <span class="font-bold text-slate-800"><?= e($td['teacher']['fullname']); ?></span>
                                            <span class="text-[10px] text-slate-400 font-medium">@<?= e($td['teacher']['username']); ?><?= !empty($td['teacher']['department']) ? ' &middot; ' . e($td['teacher']['department']) : ''; ?></span>
                                        </div>
                                    </td>
                                    
                                    <td class="py-4 px-6 text-center font-bold text-slate-500">
                                        <?= $td['course_count']; ?> วิชา
                                    </td>
                                    
                                    <!-- Syllabus Status -->
                                    <td class="py-4 px-6 text-center">
                                        <div class="flex flex-col items-center gap-1">
                                            <?= renderComplianceBadge($td['syllabus_status'], 'syllabus', $td['syllabus_is_late']); ?>
                                            <div class="flex gap-1.5 flex-wrap justify-center mt-1">
                                                <?php foreach ($td['courses'] as $c): ?>
                                                    <?php $sub = $td['syllabuses'][$c['id']] ?? null; ?>
                                                    <?php if ($sub): ?>
                                                        <a href="review.php?submission_id=<?= $sub['id']; ?>" 
                                                           title="วิชา <?= e($c['course_code']); ?>: <?= $sub['status'] === 'approved' ? 'อนุมัติแล้ว' : ($sub['status'] === 'pending' ? 'รอตรวจ' : 'ส่งกลับแก้ไข'); ?><?= $sub['submission_timing'] === 'late' ? ' (ส่งช้า)' : ''; ?>"
                                                           class="w-5 h-5 rounded-md flex items-center justify-center text-[9px] font-black <?= $sub['status'] === 'approved' ? 'bg-green-100 text-green-700' : ($sub['status'] === 'pending' ? 'bg-amber-100 text-amber-700 animate-pulse' : 'bg-rose-100 text-rose-700'); ?> <?= $sub['submission_timing'] === 'late' ? 'border border-amber-500' : ''; ?>">
                                                            <?= $sub['status'] === 'approved' ? '✓' : ($sub['status'] === 'pending' ? '?' : '✗'); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span title="วิชา <?= e($c['course_code']); ?>: ค้างส่ง"
                                                              class="w-5 h-5 rounded-md flex items-center justify-center text-[9px] font-black bg-slate-100 text-slate-450">
                                                            -
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <!-- Lesson Plan Status -->
                                    <td class="py-4 px-6 text-center">
                                        <div class="flex flex-col items-center gap-1">
                                            <?= renderComplianceBadge($td['plan_status'], 'plan', $td['plan_is_late']); ?>
                                            <div class="flex gap-1.5 flex-wrap justify-center mt-1">
                                                <?php foreach ($td['courses'] as $c): ?>
                                                    <?php $sub = $td['plans'][$c['id']] ?? null; ?>
                                                    <?php if ($sub): ?>
                                                        <a href="review.php?submission_id=<?= $sub['id']; ?>" 
                                                           title="วิชา <?= e($c['course_code']); ?>: <?= $sub['status'] === 'approved' ? 'อนุมัติแล้ว' : ($sub['status'] === 'pending' ? 'รอตรวจ' : 'ส่งกลับแก้ไข'); ?><?= $sub['submission_timing'] === 'late' ? ' (ส่งช้า)' : ''; ?>"
                                                           class="w-5 h-5 rounded-md flex items-center justify-center text-[9px] font-black <?= $sub['status'] === 'approved' ? 'bg-green-100 text-green-700' : ($sub['status'] === 'pending' ? 'bg-amber-100 text-amber-700 animate-pulse' : 'bg-rose-100 text-rose-700'); ?> <?= $sub['submission_timing'] === 'late' ? 'border border-amber-500' : ''; ?>">
                                                            <?= $sub['status'] === 'approved' ? '✓' : ($sub['status'] === 'pending' ? '?' : '✗'); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span title="วิชา <?= e($c['course_code']); ?>: ค้างส่ง"
                                                              class="w-5 h-5 rounded-md flex items-center justify-center text-[9px] font-black bg-slate-100 text-slate-455">
                                                            -
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <!-- Teaching Materials Status -->
                                    <td class="py-4 px-6 text-center">
                                        <div class="flex flex-col items-center gap-1">
                                            <?= renderComplianceBadge($td['mat_status'], 'materials', $td['mat_is_late']); ?>
                                            <div class="flex gap-1.5 flex-wrap justify-center mt-1">
                                                <?php foreach ($td['courses'] as $c): ?>
                                                    <?php $sub = $td['mats'][$c['id']] ?? null; ?>
                                                    <?php if ($sub): ?>
                                                        <a href="review.php?submission_id=<?= $sub['id']; ?>" 
                                                           title="วิชา <?= e($c['course_code']); ?>: <?= $sub['status'] === 'approved' ? 'อนุมัติแล้ว' : ($sub['status'] === 'pending' ? 'รอตรวจ' : 'ส่งกลับแก้ไข'); ?><?= $sub['submission_timing'] === 'late' ? ' (ส่งช้า)' : ''; ?>"
                                                           class="w-5 h-5 rounded-md flex items-center justify-center text-[9px] font-black <?= $sub['status'] === 'approved' ? 'bg-green-100 text-green-700' : ($sub['status'] === 'pending' ? 'bg-amber-100 text-amber-700 animate-pulse' : 'bg-rose-100 text-rose-700'); ?> <?= $sub['submission_timing'] === 'late' ? 'border border-amber-500' : ''; ?>">
                                                            <?= $sub['status'] === 'approved' ? '✓' : ($sub['status'] === 'pending' ? '?' : '✗'); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span title="วิชา <?= e($c['course_code']); ?>: ค้างส่ง"
                                                              class="w-5 h-5 rounded-md flex items-center justify-center text-[9px] font-black bg-slate-100 text-slate-455">
                                                            -
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <!-- Actions Column -->
                                    <td class="py-4 px-6 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <a href="review_teacher.php?teacher_id=<?= $td['teacher']['id']; ?>" 
                                                class="inline-flex items-center gap-1 px-3 py-1.5 border border-slate-200 hover:bg-slate-50 text-[11px] font-bold rounded-lg transition text-slate-600">
                                                ประเมินผลงาน
                                            </a>
                                            <form method="post" action="overview.php" class="inline" onsubmit="return confirm('⚠️ คุณแน่ใจหรือไม่ว่าต้องการลบข้อมูลของ <?= e($td['teacher']['fullname']); ?>? \n\nการลบนี้จะยกเลิกการจัดสอนและลบเอกสาร/ประวัติงานวิชาการทั้งหมดของคุณครูรายนี้อย่างถาวรและไม่สามารถเรียกคืนได้!');">
                                                <input type="hidden" name="csrf_token" value="<?= e(create_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="delete_teacher">
                                                <input type="hidden" name="teacher_id" value="<?= $td['teacher']['id']; ?>">
                                                <button type="submit" 
                                                        class="inline-flex items-center justify-center w-8 h-8 bg-rose-50 hover:bg-rose-100 active:bg-rose-200 text-rose-600 hover:text-rose-700 rounded-lg transition border border-rose-200 shadow-sm"
                                                        title="ลบข้อมูลคุณครู">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <!-- Tab 2: Department-wise compliance overview -->
        <div class="bg-gradient-to-br from-slate-50/50 to-slate-100/50 border border-slate-200 rounded-[32px] overflow-hidden shadow-sm">
            <div class="p-6 sm:p-8 border-b border-slate-200 bg-slate-50/50">
                <h2 class="text-base sm:text-lg font-black text-slate-800">ตารางรายงานความคืบหน้าการส่งงานวิชาการรายแผนกวิชา</h2>
                <p class="text-xs text-slate-400 font-medium mt-1">
                    สรุปสถิติสัดส่วนความพร้อม อัตราการส่งงานครบถ้วนตามเกณฑ์การประเมินแยกตามแผนกวิชาในภาคเรียนปัจจุบัน
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-400 bg-slate-50/20">
                            <th class="py-4 px-6">ชื่อแผนกวิชา</th>
                            <th class="py-4 px-6 text-center">จำนวนครูผู้สอน</th>
                            <th class="py-4 px-6 text-center">จำนวนวิชาจัดสอน</th>
                            <th class="py-4 px-6 text-center">อัตราการส่งครบ 100% (KPI)</th>
                            <th class="py-4 px-6 text-center">โครงการสอน (Syllabus)</th>
                            <th class="py-4 px-6 text-center">แผนการสอน (Plan)</th>
                            <th class="py-4 px-6 text-center">สื่อการสอน (Materials)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs sm:text-sm">
                        <?php if (count($departmentData) === 0): ?>
                            <tr>
                                <td colspan="7" class="py-12 text-center text-slate-400 font-medium bg-slate-50/10">
                                    ยังไม่มีข้อมูลประวัติแผนกวิชาจัดสารในระบบ
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($departmentData as $deptName => $deptInfo): ?>
                                <?php 
                                $compliancePercent = $deptInfo['total_teachers'] > 0 ? round(($deptInfo['compliant_teachers'] / $deptInfo['total_teachers']) * 100) : 0;
                                $syllabusPercent = $deptInfo['total_courses'] > 0 ? round(($deptInfo['syllabus_approved'] / $deptInfo['total_courses']) * 100) : 0;
                                $planPercent = $deptInfo['total_teachers'] > 0 ? round(($deptInfo['plan_approved_teachers'] / $deptInfo['total_teachers']) * 100) : 0;
                                $matPercent = $deptInfo['total_teachers'] > 0 ? round(($deptInfo['materials_approved_teachers'] / $deptInfo['total_teachers']) * 100) : 0;
                                ?>
                                <tr class="hover:bg-slate-50/50 transition">
                                    <td class="py-4 px-6">
                                        <div class="flex flex-col">
                                            <span class="font-bold text-slate-800"><?= e($deptName); ?></span>
                                            <span class="text-[10px] text-slate-400 font-medium"><?= htmlspecialchars($branding['college_name']); ?></span>
                                        </div>
                                    </td>
                                    
                                    <td class="py-4 px-6 text-center font-bold text-slate-650">
                                        <?= $deptInfo['total_teachers']; ?> ท่าน
                                    </td>
                                    
                                    <td class="py-4 px-6 text-center font-bold text-slate-500">
                                        <?= $deptInfo['total_courses']; ?> วิชา
                                    </td>
                                    
                                    <!-- Compliance Rate Progress Bar -->
                                    <td class="py-4 px-6">
                                        <div class="flex flex-col items-center justify-center w-full max-w-[120px] mx-auto">
                                            <div class="flex items-center justify-between w-full text-[10px] font-bold text-slate-500 mb-1">
                                                <span class="<?= $compliancePercent === 100 ? 'text-green-600' : '' ?>"><?= $deptInfo['compliant_teachers']; ?> / <?= $deptInfo['total_teachers']; ?> คน</span>
                                                <span class="<?= $compliancePercent === 100 ? 'text-green-600 font-extrabold' : '' ?>"><?= $compliancePercent; ?>%</span>
                                            </div>
                                            <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden border border-slate-200/50 shadow-inner">
                                                <div class="h-full rounded-full transition-all duration-500 <?= $compliancePercent === 100 ? 'bg-green-500' : ($compliancePercent >= 50 ? 'bg-teal-600' : 'bg-orange-500') ?>" 
                                                     style="width: <?= $compliancePercent; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Syllabus Dept Completion -->
                                    <td class="py-4 px-6 text-center">
                                        <div class="flex flex-col items-center">
                                            <span class="text-xs font-bold text-slate-700"><?= $deptInfo['syllabus_approved']; ?> / <?= $deptInfo['total_courses']; ?> วิชา</span>
                                            <span class="text-[10px] text-slate-400 font-medium mt-0.5">(<?= $syllabusPercent; ?>%)</span>
                                        </div>
                                    </td>

                                    <!-- Lesson Plan Dept Compliance -->
                                    <td class="py-4 px-6 text-center">
                                        <div class="flex flex-col items-center">
                                            <span class="text-xs font-bold text-slate-700"><?= $deptInfo['plan_approved_teachers']; ?> / <?= $deptInfo['total_teachers']; ?> คน</span>
                                            <span class="text-[10px] text-slate-400 font-medium mt-0.5">(<?= $planPercent; ?>%)</span>
                                        </div>
                                    </td>

                                    <!-- Materials Dept Compliance -->
                                    <td class="py-4 px-6 text-center">
                                        <div class="flex flex-col items-center">
                                            <span class="text-xs font-bold text-slate-700"><?= $deptInfo['materials_approved_teachers']; ?> / <?= $deptInfo['total_teachers']; ?> คน</span>
                                            <span class="text-[10px] text-slate-400 font-medium mt-0.5">(<?= $matPercent; ?>%)</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</main>

<footer class="py-8 bg-white border-t border-slate-200 mt-16">
    <div class="max-w-7xl mx-auto px-4 text-center text-xs text-slate-400 font-medium">
        &copy; <?= date('Y'); ?> <?= htmlspecialchars($branding['college_name']); ?> &middot; ฝ่ายวิชาการ &middot; สงวนลิขสิทธิ์
    </div>
</footer>

</body>
</html>
