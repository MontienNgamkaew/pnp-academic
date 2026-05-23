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

// 2. Fetch approved courses for Syllabus
$stmt = $pdo->prepare("
    SELECT c.*, s_sub.submitted_at 
    FROM courses c
    INNER JOIN (
        SELECT s1.* FROM submissions s1
        INNER JOIN (
            SELECT MAX(id) as max_id FROM submissions WHERE system_type = 'course_syllabus' GROUP BY course_id
        ) s2 ON s1.id = s2.max_id
    ) s_sub ON c.id = s_sub.course_id
    WHERE c.teacher_id = :teacher_id AND c.semester_id = :semester_id AND s_sub.status = 'approved'
    ORDER BY c.course_code ASC
");
$stmt->execute([
    'teacher_id' => $teacherId,
    'semester_id' => $semester['id']
]);
$approvedCourses = $stmt->fetchAll();

if (count($approvedCourses) === 0) {
    exit('ยังไม่มีรายวิชาใดที่โครงการสอน (Syllabus) ได้รับการอนุมัติ จึงยังไม่สามารถจัดพิมพ์บันทึกข้อความได้');
}

// Format Thai Date Helper
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
    $year = (int)date('Y', $time) + 543; // convert to Buddhist Era (B.E.)
    return "{$day} {$month} พ.ศ. {$year}";
}

// Convert numbers to Thai numerals
function toThaiNumerals($num): string
{
    $arabic = ['0','1','2','3','4','5','6','7','8','9'];
    $thai = ['๐','๑','๒','๓','๔','๕','๖','๗','๘','๙'];
    return str_replace($arabic, $thai, (string)$num);
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>บันทึกข้อความ - ขออนุมัติส่งโครงการสอน</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Outfit:wght@400;600;850&display=swap" rel="stylesheet">
    <style>
        @page {
            size: A4;
            margin: 2.5cm 2cm 2cm 2.5cm; /* Official Thai Government Margins (Top 2.5, Bottom 2, Right 2, Left 2.5) */
        }
        body {
            font-family: 'TH Sarabun New', 'Sarabun', sans-serif;
            font-size: 15pt;
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
        /* Top Garuda Area */
        .garuda-container {
            text-align: left;
            margin-bottom: 5px;
            position: relative;
        }
        .garuda-logo {
            width: 1.5in; /* Standard Garuda height in official docs is 1.5 inches (approx 3.8 cm) */
            height: auto;
            display: block;
            margin: 0 auto 0 0; /* Left align standard for print memo */
        }
        /* Page Title */
        .memo-title {
            font-size: 29pt; /* Standard memo title size is 29pt */
            font-weight: bold;
            text-align: center;
            margin-top: -30px;
            margin-bottom: 25px;
            letter-spacing: 0.5px;
        }
        /* Metadata Header Fields */
        .metadata-section {
            border-bottom: 3px double #000;
            padding-bottom: 10px;
            margin-bottom: 25px;
        }
        .metadata-row {
            display: flex;
            margin-bottom: 8px;
        }
        .metadata-label {
            font-weight: bold;
            white-space: nowrap;
        }
        .metadata-value {
            padding-left: 8px;
            flex-grow: 1;
        }
        .half-row {
            width: 50%;
            display: inline-flex;
        }
        /* Content Area */
        .salutation {
            font-weight: bold;
            margin-bottom: 20px;
        }
        .paragraph {
            text-indent: 2.5cm; /* Standard paragraph indentation is 2.5 cm */
            text-align: justify;
            margin-bottom: 15px;
            text-justify: inter-word;
        }
        .course-list {
            margin-left: 2.5cm;
            margin-bottom: 20px;
            list-style: none;
            padding: 0;
        }
        .course-item {
            margin-bottom: 6px;
        }
        /* Signature Area */
        .signature-block {
            float: right;
            width: 320px;
            text-align: center;
            margin-top: 40px;
            margin-bottom: 40px;
        }
        .signature-line {
            margin-bottom: 12px;
        }
        .clearfix {
            clear: both;
        }
        /* Review and Approvals Section */
        .approvals-container {
            margin-top: 30px;
            border-top: 1px solid #000;
            padding-top: 20px;
        }
        .approval-grid {
            display: grid;
            grid-template-cols: 1fr 1fr;
            gap: 20px;
        }
        .approval-box {
            border: 1px solid #999;
            padding: 15px;
            border-radius: 8px;
            font-size: 11.5pt; /* Smaller font for approval forms to fit in A4 */
            line-height: 1.4;
        }
        .approval-header {
            font-weight: bold;
            border-bottom: 1px solid #ddd;
            padding-bottom: 4px;
            margin-bottom: 10px;
            text-align: center;
        }
        /* Web Print Toolbar */
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
            box-shadow: 0 6px 8px -1px rgba(17, 94, 89, 0.3);
        }
        .back-btn {
            color: #475569;
            text-decoration: none;
            font-family: 'Sarabun', sans-serif;
            font-size: 14px;
            font-weight: bold;
        }
        @media print {
            .no-print {
                display: none;
            }
            body {
                background-color: #fff;
                font-size: 16pt;
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
        <a href="dashboard.php" class="back-btn">&larr; ย้อนกลับไปแดชบอร์ด</a>
        <div>
            <span style="font-size: 13px; color: #64748b; margin-right: 15px; font-family: 'Sarabun', sans-serif;">แนะนำ: ตั้งค่าการพิมพ์ขอบกระดาษเป็น "ไม่มี" (None) และเปิดสีพื้นหลัง</span>
            <button onclick="window.print()" class="print-btn">สั่งพิมพ์เอกสาร (Ctrl+P)</button>
        </div>
    </div>

    <!-- 1. Garuda Logo -->
    <div class="garuda-container">
        <!-- SVG vector reproduction of the official Thai Garuda Logo -->
        <svg class="garuda-logo" viewBox="0 0 100 100" fill="#000" xmlns="http://www.w3.org/2000/svg">
            <path d="M50 0c-.5 0-1 .2-1.3.6C47.4 2 43.9 6.8 41 8.8c-1.3.9-2.2 2.3-2.6 3.8-.4 1.5-.2 3.1.5 4.5l1.6 3.1c-.8.8-1.5 1.7-2 2.7l-3.3-.9c-1.5-.4-3.1-.1-4.4.7-1.3.8-2.1 2.2-2.3 3.7-.4 2.8-.7 6.4.3 8.7.6 1.3 1.6 2.3 2.9 2.8l3.1 1.2c-.2.6-.3 1.2-.3 1.8 0 1.2.3 2.3.8 3.3L34 45.4c-.6.6-1.5 1-2.4 1-1 0-1.9-.4-2.5-1.1-1.3-1.5-2.7-3.1-4.2-4.5-.9-.8-2.1-1.2-3.3-1.1-1.2.1-2.3.8-2.9 1.8-1.3 2.2-2.7 5.1-3 7.8-.2 1.5.3 3 1.3 4.1l2.4 2.5c-.3 1-.5 2-.5 3 0 .7.1 1.4.3 2.1l-3 1.3c-1.4.6-2.4 1.8-2.7 3.3-.3 1.5.1 3 .1 4.5.3 2.8.9 5.6 2.3 8.1 1 1.8 3 2.8 5 2.5l3.8-.6c1 .8 2 1.5 3.2 2l-1.3 3.3c-.6 1.5-.4 3.1.5 4.4 1 1.3 2.5 2 4.1 1.8 2.8-.3 6.3-1 8.2-2.5 1.3-1 2-2.6 2-4.2v-3.2c1 .2 2 .3 3 .3 1.4 0 2.8-.2 4.1-.7l1.9 2.9c.9 1.3 2.3 2.1 3.9 2.1 1.6 0 3-.8 3.8-2.2 1.8-3 3.8-6.9 4.3-9.8.3-1.5-.1-3.1-1.1-4.3l-2.4-2.8c.8-1 1.4-2.2 1.8-3.4l3.1.5c1 .2 2-.1 2.8-.7.8-.6 1.3-1.5 1.4-2.5.3-2.8.7-6.3.3-8.6-.2-1.3-.9-2.4-2.1-2.9l-2.8-1.3c.4-.9.6-1.9.6-3s-.2-2.1-.6-3.1l2.8-.9c1.4-.4 2.5-1.5 2.9-2.9.4-1.5.1-3-.7-4.3-1.8-2.7-4.4-5.3-6.6-7-1.3-1-3-1.3-4.5-1l-3.3 1c-.3-1-.9-1.9-1.6-2.6l1.2-3.1c.6-1.5.4-3.1-.5-4.4-1-1.3-2.5-2-4.1-1.8-2.8.3-6.3 1-8.2 2.5-1.3 1-2 2.6-2 4.2v3.1c-.8-.2-1.7-.3-2.5-.3zm3.7 10.6c.5 0 .9.2 1.2.6.5.6.8 1.4.8 2.2 0 1.2-.7 2.3-1.8 2.7l-2 .8c-.2-.6-.5-1.2-.9-1.7l1.5-2.7c.3-.6.7-.9 1.2-.9zm-7.4 0c.5 0 .9.3 1.2.9l1.5 2.7c-.4.5-.7 1.1-.9 1.7l-2-.8C45 14 44.3 13 44.3 11.8c0-.8.3-1.6.8-2.2.3-.4.7-.6 1.2-.6z"/>
            <path d="M50 25c-5.5 0-10 4.5-10 10s4.5 10 10 10 10-4.5 10-10-4.5-10-10-10zm0 16c-3.3 0-6-2.7-6-6s2.7-6 6-6 6 2.7 6 6-2.7 6-6 6z"/>
            <path d="M50 49c-10.5 0-19 8.5-19 19 0 1.1.9 2 2 2h34c1.1 0 2-.9 2-2 0-10.5-8.5-19-19-19zm-14.8 17c1.3-6.2 6.8-11 13.3-11s12 4.8 13.3 11H35.2z"/>
            <path d="M50 74c-1.7 0-3 1.3-3 3v13c0 1.7 1.3 3 3 3s3-1.3 3-3V77c0-1.7-1.3-3-3-3z"/>
        </svg>
    </div>

    <!-- 2. Memorandum Title -->
    <div class="memo-title">บันทึกข้อความ</div>

    <!-- 3. Metadata Table -->
    <div class="metadata-section">
        <div class="metadata-row">
            <div class="half-row">
                <span class="metadata-label">ส่วนราชการ</span>
                <span class="metadata-value">วิทยาลัยการอาชีพพนมไพร ฝ่ายวิชาการ</span>
            </div>
            <div class="half-row">
                <span class="metadata-label">ที่</span>
                <span class="metadata-value">ฝว. / <?= toThaiNumerals(date('Y') + 543); ?></span>
            </div>
        </div>
        <div class="metadata-row">
            <div class="half-row">
                <span class="metadata-label">วันที่</span>
                <span class="metadata-value"><?= toThaiNumerals(thaiDate(date('Y-m-d'))); ?></span>
            </div>
        </div>
        <div class="metadata-row">
            <span class="metadata-label">เรื่อง</span>
            <span class="metadata-value">ขออนุมัติจัดส่งและยื่นโครงการสอน (Syllabus) ประจำภาคเรียนที่ <?= toThaiNumerals($semester['semester_name']); ?></span>
        </div>
    </div>

    <!-- 4. Recipient -->
    <div class="salutation">เรียน &nbsp;&nbsp;ผู้อำนวยการวิทยาลัยการอาชีพพนมไพร</div>

    <!-- 5. Body Context -->
    <div class="paragraph">
        ด้วยข้าพเจ้า <strong><?= e(current_user_fullname()); ?></strong> ตำแหน่ง ครูผู้สอน ได้รับมอบหมายให้ปฏิบัติหน้าที่จัดการเรียนการสอนในภาคเรียนที่ <?= toThaiNumerals($semester['semester_name']); ?> บัดนี้ ข้าพเจ้าได้ดำเนินการจัดเตรียมเอกสารและจัดทำโครงการสอน (Syllabus) เรียบร้อยแล้ว ซึ่งได้รับการตรวจสอบและมีมติเห็นชอบการประเมินผลอนุมัติผ่านเกณฑ์มาตรฐานความพร้อมทางวิชาการเรียบร้อยแล้ว จำนวน <strong><?= toThaiNumerals(count($approvedCourses)); ?></strong> รายวิชา ดังรายการต่อไปนี้:
    </div>

    <!-- 6. List of Courses -->
    <ul class="course-list">
        <?php $i = 1; foreach ($approvedCourses as $ac): ?>
            <li class="course-item">
                <?= toThaiNumerals($i); ?>. รหัสวิชา <strong><?= toThaiNumerals(e($ac['course_code'])); ?></strong> รายวิชา <strong><?= e($ac['course_name']); ?></strong>
            </li>
        <?php $i++; endforeach; ?>
    </ul>

    <div class="paragraph">
        จึงเรียนมาเพื่อโปรดทราบ และพิจารณาอนุมัติดำเนินการในส่วนที่เกี่ยวข้องต่อไป
    </div>

    <!-- 7. Teacher Signature Area -->
    <div class="signature-block">
        <div class="signature-line">ลงชื่อ .....................................................</div>
        <div>( <strong><?= e(current_user_fullname()); ?></strong> )</div>
        <div style="margin-top: 4px;">ครูผู้สอน</div>
    </div>
    
    <div class="clearfix"></div>

    <!-- 8. Approvals Section (Standard Form) -->
    <div class="approvals-container">
        <div class="approval-grid">
            
            <!-- Step 1: Head of Department -->
            <div class="approval-box">
                <div class="approval-header">๑. ความเห็นของหัวหน้าแผนกวิชา / ตรวจสอบ</div>
                <div style="margin-bottom: 20px;">
                    [ &nbsp; ] ตรวจสอบแล้ว ถูกต้อง ครบถ้วน เห็นควรอนุมัติ<br>
                    [ &nbsp; ] ควรปรับปรุงแก้ไข ...............................................................
                </div>
                <div style="text-align: center; margin-top: 25px;">
                    ลงชื่อ ....................................................................<br>
                    (....................................................................)<br>
                    ตำแหน่ง หัวหน้าแผนกวิชา<br>
                    วันที่ ...... / ................ / ...........
                </div>
            </div>

            <!-- Step 2: Deputy Director -->
            <div class="approval-box">
                <div class="approval-header">๒. ความเห็นของรองผู้อำนวยการฝ่ายวิชาการ</div>
                <div style="margin-bottom: 20px;">
                    [ &nbsp; ] เห็นควรอนุมัติเพื่อใช้ในการเรียนการสอนต่อไป<br>
                    [ &nbsp; ] อื่นๆ .................................................................................
                </div>
                <div style="text-align: center; margin-top: 25px;">
                    ลงชื่อ ....................................................................<br>
                    (....................................................................)<br>
                    ตำแหน่ง รองผู้อำนวยการฝ่ายวิชาการ<br>
                    วันที่ ...... / ................ / ...........
                </div>
            </div>

        </div>

        <div class="approval-grid" style="margin-top: 20px; grid-template-cols: 1fr;">
            
            <!-- Step 3: Director -->
            <div class="approval-box" style="width: 100%; box-sizing: border-box;">
                <div class="approval-header">๓. ผลการพิจารณาอนุมัติจากผู้อำนวยการวิทยาลัยการอาชีพพนมไพร</div>
                <div style="display: flex; justify-content: space-around; margin-bottom: 25px; margin-top: 15px;">
                    <div>[ &nbsp; ] ทราบและอนุมัติโครงการสอน</div>
                    <div>[ &nbsp; ] ไม่อนุมัติ เนื่องจาก ......................................................................................</div>
                </div>
                <div style="text-align: center; margin-top: 20px;">
                    ลงชื่อ ......................................................................................................<br>
                    (......................................................................................................)<br>
                    ผู้อำนวยการวิทยาลัยการอาชีพพนมไพร<br>
                    วันที่ ...... / ......................... / ...........
                </div>
            </div>

        </div>
    </div>

</div>

</body>
</html>
