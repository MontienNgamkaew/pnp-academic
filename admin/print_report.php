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

// 2. Fetch all active teachers
$stmt = $pdo->prepare("SELECT id, username, fullname FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY fullname ASC");
$stmt->execute();
$teachers = $stmt->fetchAll();

// 3. For each teacher, calculate compliance statuses
$teacherData = [];
$totalTeachersCount = count($teachers);
$fullyCompliantCount = 0;

$syllabusCompliantCount = 0;
$planCompliantCount = 0;
$matCompliantCount = 0;

foreach ($teachers as $t) {
    $tId = (int)$t['id'];
    
    // Fetch courses assigned to this teacher in active semester
    $stmt = $pdo->prepare('SELECT id, course_code, course_name FROM courses WHERE teacher_id = :teacher_id AND semester_id = :semester_id');
    $stmt->execute(['teacher_id' => $tId, 'semester_id' => $semester['id']]);
    $teacherCourses = $stmt->fetchAll();
    $courseCount = count($teacherCourses);
    
    $syllabusStatus = 'missing'; // 'missing', 'approved', 'incomplete'
    $planStatus = 'missing';
    $matStatus = 'missing';
    
    $syllabusIsLate = false;
    $planIsLate = false;
    $matIsLate = false;
    
    if ($courseCount > 0) {
        $cIds = array_map(fn($c) => (int)$c['id'], $teacherCourses);
        $inQuery = implode(',', $cIds);
        
        // Latest approved syllabus submissions
        $syllabuses = $pdo->query("
            SELECT s1.* FROM submissions s1
            INNER JOIN (
                SELECT MAX(id) as max_id FROM submissions WHERE system_type = 'course_syllabus' AND course_id IN ($inQuery) GROUP BY course_id
            ) s2 ON s1.id = s2.max_id
            WHERE s1.status = 'approved'
        ")->fetchAll();
        
        // Latest approved lesson plan submissions
        $plans = $pdo->query("
            SELECT s1.* FROM submissions s1
            INNER JOIN (
                SELECT MAX(id) as max_id FROM submissions WHERE system_type = 'lesson_plan' AND course_id IN ($inQuery) GROUP BY course_id
            ) s2 ON s1.id = s2.max_id
            WHERE s1.status = 'approved'
        ")->fetchAll();
        
        // Latest approved teaching materials submissions
        $mats = $pdo->query("
            SELECT s1.* FROM submissions s1
            INNER JOIN (
                SELECT MAX(id) as max_id FROM submissions WHERE system_type = 'teaching_materials' AND course_id IN ($inQuery) GROUP BY course_id
            ) s2 ON s1.id = s2.max_id
            WHERE s1.status = 'approved'
        ")->fetchAll();
        
        $syllabusIsLate = false;
        // Syllabus compliance: 100% of courses approved
        if (count($syllabuses) === $courseCount) {
            $syllabusStatus = 'approved';
            $syllabusCompliantCount++;
            
            // Check if any approved syllabus was submitted late
            foreach ($syllabuses as $s) {
                if ($s['submission_timing'] === 'late') {
                    $syllabusIsLate = true;
                    break;
                }
            }
        } elseif (count($syllabuses) > 0) {
            $syllabusStatus = 'incomplete';
        }
        
        $planIsLate = false;
        // Plan compliance: At least 1 approved
        if (count($plans) >= 1) {
            $planStatus = 'approved';
            $planCompliantCount++;
            
            // Late only if all approved plans are late
            $allLate = true;
            foreach ($plans as $p) {
                if ($p['submission_timing'] !== 'late') {
                    $allLate = false;
                    break;
                }
            }
            $planIsLate = $allLate;
        }
        
        $matIsLate = false;
        // Materials compliance: At least 1 approved
        if (count($mats) >= 1) {
            $matStatus = 'approved';
            $matCompliantCount++;
            
            // Late only if all approved materials are late
            $allLate = true;
            foreach ($mats as $m) {
                if ($m['submission_timing'] !== 'late') {
                    $allLate = false;
                    break;
                }
            }
            $matIsLate = $allLate;
        }
    }
    
    if ($syllabusStatus === 'approved' && $planStatus === 'approved' && $matStatus === 'approved') {
        $fullyCompliantCount++;
    }
    
    $teacherData[] = [
        'teacher' => $t,
        'course_count' => $courseCount,
        'syllabus_status' => $syllabusStatus,
        'syllabus_is_late' => $syllabusIsLate,
        'plan_status' => $planStatus,
        'plan_is_late' => $planIsLate,
        'mat_status' => $matStatus,
        'mat_is_late' => $matIsLate
    ];
}

// Convert numbers to Thai numerals helper
function toThaiNumerals($num): string
{
    $arabic = ['0','1','2','3','4','5','6','7','8','9'];
    $thai = ['๐','๑','๒','๓','๔','๕','๖','๗','๘','๙'];
    return str_replace($arabic, $thai, (string)$num);
}

// Date formatter helper
function thaiDate(string $timeStr): string
{
    $months = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
        5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
        9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
    ];
    $time = strtotime($timeStr);
    $day = date('j', $time);
    $month = $months[(int)date('n', $time)];
    $year = (int)date('Y', $time) + 543;
    return "{$day} {$month} พ.ศ. {$year}";
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>รายงานความก้าวหน้าการจัดส่งภารกิจวิชาการ - ภาคเรียนที่ <?= e($semester['semester_name']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @page {
            size: A4;
            margin: 2.5cm 2cm 2cm 2cm;
        }
        body {
            font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
            font-size: 14pt;
            line-height: 1.25;
            color: #000;
            background-color: #fff;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        /* Printable Toolbar */
        .no-print {
            background-color: #f1f5f9;
            border-bottom: 1px solid #e2e8f0;
            padding: 15px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-radius: 12px;
        }
        .print-btn {
            background-color: #0f766e;
            color: #fff;
            border: none;
            padding: 10px 22px;
            font-family: 'Sarabun', sans-serif;
            font-size: 14px;
            font-weight: bold;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 4px 6px -1px rgba(15, 118, 110, 0.2);
            transition: all 0.2s;
        }
        .print-btn:hover {
            background-color: #115e59;
        }
        .back-btn {
            color: #475569;
            text-decoration: none;
            font-family: 'Sarabun', sans-serif;
            font-size: 14px;
            font-weight: bold;
        }
        /* Official Header layout */
        .garuda-container {
            text-align: center;
            margin-bottom: 15px;
        }
        .garuda-logo {
            width: 1.2in;
            height: auto;
            display: block;
            margin: 0 auto 10px auto;
        }
        .header-title {
            font-size: 18pt;
            font-weight: bold;
            text-align: center;
            margin-bottom: 5px;
        }
        .header-subtitle {
            font-size: 15pt;
            text-align: center;
            margin-bottom: 25px;
        }
        /* General styling */
        .paragraph {
            text-indent: 1.5cm;
            text-align: justify;
            margin-bottom: 15px;
        }
        /* Report table */
        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13pt;
            margin-bottom: 30px;
            margin-top: 15px;
        }
        .report-table th, .report-table td {
            border: 1px solid #000;
            padding: 6px 10px;
            vertical-align: middle;
        }
        .report-table th {
            font-weight: bold;
            text-align: center;
            background-color: #f9f9f9;
        }
        .text-center {
            text-align: center;
        }
        .bold {
            font-weight: bold;
        }
        /* Signature Area */
        .signoff-section {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
            page-break-inside: avoid;
        }
        .signoff-box {
            width: 45%;
            text-align: center;
        }
        .signoff-line {
            margin-bottom: 8px;
        }
        @media print {
            .no-print {
                display: none;
            }
            body {
                background-color: #fff;
                font-size: 15pt;
            }
            .container {
                padding: 0;
            }
        }
    </style>
</head>
<body>

<div class="container">
    
    <!-- Printable Toolbar (Hidden on actual print) -->
    <div class="no-print">
        <a href="overview.php" class="back-btn">&larr; ย้อนกลับไปแดชบอร์ด</a>
        <div>
            <button onclick="window.print()" class="print-btn">พิมพ์เอกสารรายงานสรุป (Ctrl+P)</button>
        </div>
    </div>

    <!-- 1. Garuda Logo -->
    <div class="garuda-container">
        <svg class="garuda-logo" viewBox="0 0 100 100" fill="#000" xmlns="http://www.w3.org/2000/svg">
            <path d="M50 0c-.5 0-1 .2-1.3.6C47.4 2 43.9 6.8 41 8.8c-1.3.9-2.2 2.3-2.6 3.8-.4 1.5-.2 3.1.5 4.5l1.6 3.1c-.8.8-1.5 1.7-2 2.7l-3.3-.9c-1.5-.4-3.1-.1-4.4.7-1.3.8-2.1 2.2-2.3 3.7-.4 2.8-.7 6.4.3 8.7.6 1.3 1.6 2.3 2.9 2.8l3.1 1.2c-.2.6-.3 1.2-.3 1.8 0 1.2.3 2.3.8 3.3L34 45.4c-.6.6-1.5 1-2.4 1-1 0-1.9-.4-2.5-1.1-1.3-1.5-2.7-3.1-4.2-4.5-.9-.8-2.1-1.2-3.3-1.1-1.2.1-2.3.8-2.9 1.8-1.3 2.2-2.7 5.1-3 7.8-.2 1.5.3 3 1.3 4.1l2.4 2.5c-.3 1-.5 2-.5 3 0 .7.1 1.4.3 2.1l-3 1.3c-1.4.6-2.4 1.8-2.7 3.3-.3 1.5.1 3 .1 4.5.3 2.8.9 5.6 2.3 8.1 1 1.8 3 2.8 5 2.5l3.8-.6c1 .8 2 1.5 3.2 2l-1.3 3.3c-.6 1.5-.4 3.1.5 4.4 1 1.3 2.5 2 4.1 1.8 2.8-.3 6.3-1 8.2-2.5 1.3-1 2-2.6 2-4.2v-3.2c1 .2 2 .3 3 .3 1.4 0 2.8-.2 4.1-.7l1.9 2.9c.9 1.3 2.3 2.1 3.9 2.1 1.6 0 3-.8 3.8-2.2 1.8-3 3.8-6.9 4.3-9.8.3-1.5-.1-3.1-1.1-4.3l-2.4-2.8c.8-1 1.4-2.2 1.8-3.4l3.1.5c1 .2 2-.1 2.8-.7.8-.6 1.3-1.5 1.4-2.5.3-2.8.7-6.3.3-8.6-.2-1.3-.9-2.4-2.1-2.9l-2.8-1.3c.4-.9.6-1.9.6-3s-.2-2.1-.6-3.1l2.8-.9c1.4-.4 2.5-1.5 2.9-2.9.4-1.5.1-3-.7-4.3-1.8-2.7-4.4-5.3-6.6-7-1.3-1-3-1.3-4.5-1l-3.3 1c-.3-1-.9-1.9-1.6-2.6l1.2-3.1c.6-1.5.4-3.1-.5-4.4-1-1.3-2.5-2-4.1-1.8-2.8.3-6.3 1-8.2 2.5-1.3 1-2 2.6-2 4.2v3.1c-.8-.2-1.7-.3-2.5-.3zm3.7 10.6c.5 0 .9.2 1.2.6.5.6.8 1.4.8 2.2 0 1.2-.7 2.3-1.8 2.7l-2 .8c-.2-.6-.5-1.2-.9-1.7l1.5-2.7c.3-.6.7-.9 1.2-.9zm-7.4 0c.5 0 .9.3 1.2.9l1.5 2.7c-.4.5-.7 1.1-.9 1.7l-2-.8C45 14 44.3 13 44.3 11.8c0-.8.3-1.6.8-2.2.3-.4.7-.6 1.2-.6z"/>
            <path d="M50 25c-5.5 0-10 4.5-10 10s4.5 10 10 10 10-4.5 10-10-4.5-10-10-10zm0 16c-3.3 0-6-2.7-6-6s2.7-6 6-6 6 2.7 6 6-2.7 6-6 6z"/>
            <path d="M50 49c-10.5 0-19 8.5-19 19 0 1.1.9 2 2 2h34c1.1 0 2-.9 2-2 0-10.5-8.5-19-19-19zm-14.8 17c1.3-6.2 6.8-11 13.3-11s12 4.8 13.3 11H35.2z"/>
            <path d="M50 74c-1.7 0-3 1.3-3 3v13c0 1.7 1.3 3 3 3s3-1.3 3-3V77c0-1.7-1.3-3-3-3z"/>
        </svg>
    </div>

    <!-- 2. Header titles -->
    <div class="header-title">รายงานสรุปความพร้อมการจัดส่งเอกสารและภารกิจการจัดการเรียนการสอน</div>
    <div class="header-subtitle">วิทยาลัยการอาชีพพนมไพร ฝ่ายวิชาการ ภาคเรียนที่ <?= toThaiNumerals($semester['semester_name']); ?></div>

    <!-- 3. Context Context -->
    <div class="paragraph">
        ด้วย งานพัฒนาหลักสูตรการเรียนการสอน ฝ่ายวิชาการ ได้ดำเนินการติดตามและวิเคราะห์ความพร้อมของการจัดเตรียมหลักสูตร ประจำภาคเรียนที่ <?= toThaiNumerals($semester['semester_name']); ?> ประกอบด้วย (๑) โครงการสอน (Syllabus) (๒) แผนการจัดการเรียนรู้ (Lesson Plan) และ (๓) สื่อการจัดการเรียนรู้ของครูผู้สอน บัดนี้ ฝ่ายวิชาการได้ทำการรวบรวมสถานะความก้าวหน้าเรียบร้อยแล้ว ณ วันที่ <?= toThaiNumerals(thaiDate(date('Y-m-d'))); ?> ซึ่งมีบุคลากรครูที่ได้รับมอบหมายงานทั้งสิ้นจำนวน <strong><?= toThaiNumerals($totalTeachersCount); ?></strong> ราย มีคุณครูส่งเอกสารครบเกณฑ์ประเมินอนุมัติ <strong><?= toThaiNumerals($fullyCompliantCount); ?></strong> ราย รายละเอียดผลงานรายบุคคลดังตารางต่อไปนี้:
    </div>

    <!-- 4. Main Compliance Data Table -->
    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 8%;" class="text-center">ลำดับ</th>
                <th style="width: 32%;">ชื่อ-นามสกุลครูผู้สอน</th>
                <th style="width: 20%;" class="text-center">โครงการสอน (Syllabus)</th>
                <th style="width: 20%;" class="text-center">แผนการจัดการเรียนรู้</th>
                <th style="width: 20%;" class="text-center">สื่อการสอน</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach ($teacherData as $td): ?>
                <tr>
                    <td class="text-center"><?= toThaiNumerals($i); ?>.</td>
                    <td class="bold"><?= e($td['teacher']['fullname']); ?></td>
                    
                    <!-- Syllabus compliance display -->
                    <td class="text-center">
                        <?php if ($td['syllabus_status'] === 'approved'): ?>
                            <?= $td['syllabus_is_late'] ? 'ส่งครบ (ไม่ตามกำหนดเวลา)' : 'ผ่านการอนุมัติ'; ?>
                        <?php elseif ($td['syllabus_status'] === 'incomplete'): ?>
                            ยังส่งไม่ครบ
                        <?php else: ?>
                            ยังไม่ส่ง
                        <?php endif; ?>
                    </td>
                    
                    <!-- Plan compliance display -->
                    <td class="text-center">
                        <?php if ($td['plan_status'] === 'approved'): ?>
                            <?= $td['plan_is_late'] ? 'ส่งครบ (ไม่ตามกำหนดเวลา)' : 'ผ่านเกณฑ์ขั้นต่ำ'; ?>
                        <?php else: ?>
                            ยังไม่ส่ง
                        <?php endif; ?>
                    </td>
                    
                    <!-- Materials compliance display -->
                    <td class="text-center">
                        <?php if ($td['mat_status'] === 'approved'): ?>
                            <?= $td['mat_is_late'] ? 'ส่งครบ (ไม่ตามกำหนดเวลา)' : 'ผ่านเกณฑ์ขั้นต่ำ'; ?>
                        <?php else: ?>
                            ยังไม่ส่ง
                        <?php endif; ?>
                    </td>
                </tr>
            <?php $i++; endforeach; ?>
        </tbody>
    </table>

    <!-- 5. General summary KPI stats -->
    <div style="font-size: 13pt; margin-bottom: 25px;">
        <span class="bold">สรุปสถิติจำนวนบุคลากรที่จัดส่งผลงานผ่านเกณฑ์:</span><br>
        - โครงการสอน (ส่งครบถ้วนทุกวิชา): ผ่านเกณฑ์จำนวน <strong><?= toThaiNumerals($syllabusCompliantCount); ?></strong> ราย<br>
        - แผนการจัดการเรียนรู้ (ส่งอย่างน้อย ๑ วิชา): ผ่านเกณฑ์จำนวน <strong><?= toThaiNumerals($planCompliantCount); ?></strong> ราย<br>
        - สื่อการสอน (ส่งอย่างน้อย ๑ วิชา): ผ่านเกณฑ์จำนวน <strong><?= toThaiNumerals($matCompliantCount); ?></strong> ราย
    </div>

    <!-- 6. Official Signatures block -->
    <div class="signoff-section">
        <div class="signoff-box">
            <div class="signoff-line">ลงชื่อ ..................................................... ผู้รายงาน</div>
            <div>( <strong>ผู้ดูแลระบบ งานพัฒนาหลักสูตร</strong> )</div>
            <div style="margin-top: 4px;">เจ้าหน้าที่งานพัฒนาหลักสูตรการเรียนการสอน</div>
            <div style="margin-top: 4px;">วันที่ ...... / ......................... / ...........</div>
        </div>

        <div class="signoff-box">
            <div class="signoff-line">ลงชื่อ ..................................................... ผู้พิจารณา</div>
            <div>( <strong>ผู้อำนวยการวิทยาลัยการอาชีพพนมไพร</strong> )</div>
            <div style="margin-top: 4px;">ผู้อำนวยการวิทยาลัยการอาชีพพนมไพร</div>
            <div style="margin-top: 4px;">วันที่ ...... / ......................... / ...........</div>
        </div>
    </div>

</div>

</body>
</html>
