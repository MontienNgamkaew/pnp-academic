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

$submissionId = isset($_GET['submission_id']) ? (int) $_GET['submission_id'] : 0;
if (!$submissionId) {
    redirect_to('overview.php');
}

// Fetch submission details with course and teacher information
$stmt = $pdo->prepare("
    SELECT s.*, c.course_code, c.course_name, u.fullname AS teacher_fullname, u.username AS teacher_username
    FROM submissions s
    INNER JOIN courses c ON s.course_id = c.id
    INNER JOIN users u ON c.teacher_id = u.id
    WHERE s.id = :submission_id
    LIMIT 1
");
$stmt->execute(['submission_id' => $submissionId]);
$submission = $stmt->fetch();

if (!$submission) {
    exit('ไม่พบข้อมูลการยื่นส่งเอกสารนี้ในระบบ');
}

$errorMessage = '';
$successMessage = '';

// Handle review feedback submission (Approve / Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verify_csrf_token($csrfToken)) {
        $errorMessage = 'CSRF token ไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $action = $_POST['action'] ?? '';
        $feedback = trim($_POST['feedback'] ?? '');
        
        if ($action !== 'approve' && $action !== 'reject') {
            $errorMessage = 'คำดำเนินการไม่ถูกต้อง';
        } elseif ($action === 'reject' && $feedback === '') {
            $errorMessage = 'กรุณาระบุข้อเสนอแนะ/เหตุผลในการส่งกลับไปแก้ไขเพื่อเป็นแนวทางให้คุณครูครับ';
        } else {
            $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
            
            $stmt = $pdo->prepare("
                UPDATE submissions
                SET status = :status, feedback = :feedback
                WHERE id = :submission_id
            ");
            $stmt->execute([
                'status' => $newStatus,
                'feedback' => $feedback !== '' ? $feedback : null,
                'submission_id' => $submissionId
            ]);
            
            // Set success flash and redirect back to overview
            $_SESSION['success_flash'] = 'บันทึกการประเมินเอกสารเรียบร้อยแล้ว';
            redirect_to('overview.php');
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
    <title>ตรวจประเมินผลงานครู | <?= htmlspecialchars($branding['system_name']); ?></title>
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
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
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

<?php
$isPdf = $submission['file_path'] && strtolower(pathinfo($submission['file_path'], PATHINFO_EXTENSION)) === 'pdf';
$containerClass = $isPdf ? 'max-w-7xl' : 'max-w-5xl';
?>
<main class="flex-1 <?= $containerClass; ?> w-full mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="mb-8">
        <h1 class="text-xl sm:text-2xl font-black text-slate-800">พิจารณาประเมินตรวจรับเอกสาร</h1>
        <p class="text-xs text-slate-400 font-medium mt-1">
            ตรวจรับผลงานของ <span class="text-indigo-650 font-bold"><?= e($submission['teacher_fullname']); ?></span> &middot; ตรวจทานความถูกต้องพร้อมระบุผลลัพธ์และข้อเสนอแนะเพิ่มเติม
        </p>
    </div>

    <?php if ($errorMessage): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-2xl p-4 text-xs sm:text-sm font-bold mb-6 flex items-center gap-2">
            <svg class="w-5 h-5 text-rose-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span><?= e($errorMessage); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($isPdf): ?>
        <!-- Premium Split-Screen Layout for PDF viewing -->
        <div class="grid gap-8 md:grid-cols-3 items-start">
            
            <!-- Left Column: Details & Evaluation (1 Column) -->
            <div class="md:col-span-1 space-y-6">
                <!-- Evaluation Form & Info Card -->
                <div class="bg-white border border-slate-200 rounded-[32px] p-6 sm:p-8 shadow-sm">
                    <h3 class="text-sm font-black text-slate-800 mb-6 pb-4 border-b border-slate-100 flex items-center gap-2">
                        <span class="p-1 rounded bg-teal-50 text-teal-700">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </span>
                        <span>แบบประเมินผลงาน</span>
                    </h3>
                    
                    <div class="space-y-4 mb-6">
                        <div>
                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest block">รายวิชา</span>
                            <span class="text-xs font-bold text-slate-800 block mt-0.5">[<?= e($submission['course_code']); ?>] <?= e($submission['course_name']); ?></span>
                        </div>
                        <div>
                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest block">ประเภทเอกสาร</span>
                            <span class="text-xs font-bold text-teal-700 block mt-0.5"><?= $systemTypeLabels[$submission['system_type']] ?? ''; ?></span>
                        </div>
                        <div>
                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest block">ครูผู้สอน</span>
                            <span class="text-xs font-bold text-slate-800 block mt-0.5"><?= e($submission['teacher_fullname']); ?> (@<?= e($submission['teacher_username']); ?>)</span>
                        </div>
                        <div>
                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest block">เวลาส่งงาน</span>
                            <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5 mt-1">
                                <?= date('d/m/Y H:i', strtotime($submission['submitted_at'])); ?> น.
                                <?php if ($submission['submission_timing'] === 'late'): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[9px] font-bold bg-orange-50 text-orange-600 border border-orange-200 leading-none">ส่งล่าช้า</span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded-full text-[9px] font-bold bg-green-50 text-green-600 border border-green-200 leading-none">ตรงเวลา</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                    <!-- Evaluator form -->
                    <form method="post" action="review.php?submission_id=<?= $submissionId; ?>" class="space-y-6 pt-4 border-t border-slate-100">
                        <input type="hidden" name="csrf_token" value="<?= e(create_csrf_token()); ?>">
                        
                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2" for="feedback">ข้อเสนอแนะจากงานวิชาการ</label>
                            <textarea name="feedback" id="feedback" rows="4" placeholder="ระบุเหตุผลในการส่งกลับแก้ไข หรือคำแนะนำประกอบการอนุมัติ..."
                                      class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-semibold text-slate-700 focus:outline-none focus:border-teal-700 focus:bg-white transition"><?= e($submission['feedback'] ?? ''); ?></textarea>
                        </div>

                        <div class="flex flex-col gap-2.5">
                            <button type="submit" name="action" value="approve"
                                    class="w-full py-3 text-xs font-black text-white bg-slate-900 hover:bg-slate-800 rounded-xl transition active:scale-[0.98] shadow-md shadow-slate-900/10">
                                อนุมัติเอกสาร (Approve)
                            </button>
                            <button type="submit" name="action" value="reject"
                                    class="w-full py-3 text-xs font-black text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 rounded-xl transition active:scale-[0.98]">
                                ส่งกลับไปแก้ไข (Reject)
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Download & Backup Links Card -->
                <div class="bg-white border border-slate-200 rounded-[32px] p-6 shadow-sm">
                    <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest text-center mb-4">ช่องทางดาวน์โหลด / ลิงก์สำรอง</h3>
                    <div class="space-y-3">
                        <a href="../<?= e($submission['file_path']); ?>" download
                           class="w-full inline-flex items-center justify-center gap-2 py-2.5 bg-slate-50 hover:bg-slate-100 border border-slate-200 text-slate-750 text-xs font-bold rounded-xl transition no-underline">
                            <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                            <span>ดาวน์โหลดไฟล์ลงเครื่อง</span>
                        </a>

                        <?php if ($submission['drive_link']): ?>
                            <a href="<?= e($submission['drive_link']); ?>" target="_blank"
                               class="w-full inline-flex items-center justify-center gap-2 py-2.5 bg-blue-50 hover:bg-blue-100 border border-blue-200 text-blue-700 text-xs font-bold rounded-xl transition no-underline">
                                <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                                <span>เปิดใน Google Drive ↗</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Full Screen Inline PDF Preview (2 Columns) -->
            <div class="md:col-span-2">
                <div class="bg-white border border-slate-200 rounded-[32px] p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-4 pb-4 border-b border-slate-100">
                        <h3 class="text-sm font-black text-slate-800 flex items-center gap-2">
                            <span class="p-1 rounded bg-red-50 text-red-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </span>
                            <span>เอกสารหลักฐาน (Live Inline Preview)</span>
                        </h3>
                        <span class="text-[10px] font-bold text-slate-400">ตรวจสอบโดยไม่ต้องดาวน์โหลด</span>
                    </div>
                    
                    <!-- Full height embedded iframe PDF viewer -->
                    <iframe src="../<?= e($submission['file_path']); ?>" 
                            class="w-full h-[650px] rounded-2xl border border-slate-150 shadow-inner bg-slate-100" 
                            style="background-color: #fff;">
                    </iframe>
                </div>
            </div>

        </div>
    <?php else: ?>
        <!-- Standard Layout for Non-PDF/Only Link submissions -->
        <div class="grid gap-8 md:grid-cols-3">
            
            <!-- Left: Review Form (2 Columns) -->
            <div class="bg-white border border-slate-200 rounded-[32px] p-6 sm:p-8 shadow-sm md:col-span-2 flex flex-col justify-between">
                <div>
                    <h3 class="text-base font-black text-slate-800 mb-6 pb-4 border-b border-slate-100">รายละเอียดเอกสารและแบบประเมิน</h3>
                    
                    <div class="grid gap-4 sm:grid-cols-2 mb-6">
                        <div>
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest block mb-1">รายวิชา</span>
                            <span class="text-xs sm:text-sm font-bold text-slate-800">[<?= e($submission['course_code']); ?>] <?= e($submission['course_name']); ?></span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest block mb-1">ประเภทเอกสาร</span>
                            <span class="text-xs sm:text-sm font-bold text-teal-700"><?= $systemTypeLabels[$submission['system_type']] ?? ''; ?></span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest block mb-1">ครูผู้สอน</span>
                            <span class="text-xs sm:text-sm font-bold text-slate-800"><?= e($submission['teacher_fullname']); ?> (@<?= e($submission['teacher_username']); ?>)</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest block mb-1">เวลาส่งงาน</span>
                            <span class="text-xs sm:text-sm font-bold text-slate-800 flex items-center gap-1.5 mt-0.5">
                                <?= date('d/m/Y H:i', strtotime($submission['submitted_at'])); ?> น.
                                <?php if ($submission['submission_timing'] === 'late'): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[9px] font-bold bg-orange-50 text-orange-600 border border-orange-200 leading-none">ส่งล่าช้า</span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded-full text-[9px] font-bold bg-green-50 text-green-600 border border-green-200 leading-none">ตรงเวลา</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                    <!-- Evaluator form -->
                    <form method="post" action="review.php?submission_id=<?= $submissionId; ?>" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?= e(create_csrf_token()); ?>">
                        
                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-2" for="feedback">ข้อเสนอแนะจากงานวิชาการ</label>
                            <textarea name="feedback" id="feedback" rows="4" placeholder="กรอกข้อความแนะนำกรณีส่งกลับไปแก้ไข หรือให้ความเห็นชื่นชมประกอบการอนุมัติ..."
                                      class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs sm:text-sm font-semibold text-slate-700 focus:outline-none focus:border-teal-700 focus:bg-white transition"><?= e($submission['feedback'] ?? ''); ?></textarea>
                        </div>

                        <div class="pt-4 flex items-center gap-3">
                            <button type="submit" name="action" value="reject"
                                    class="flex-1 py-3 text-xs font-black text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 rounded-xl transition active:scale-[0.98] shadow-sm">
                                ส่งกลับไปแก้ไข (Reject)
                            </button>
                            <button type="submit" name="action" value="approve"
                                    class="flex-1 py-3 text-xs font-black text-white bg-slate-900 hover:bg-slate-800 rounded-xl transition active:scale-[0.98] shadow-md shadow-slate-900/10">
                                อนุมัติเอกสาร (Approve)
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right: File Attachment Preview / Open link (1 Column) -->
            <div class="space-y-6">
                
                <!-- Link / File card -->
                <div class="bg-white border border-slate-200 rounded-[32px] p-6 shadow-sm text-center">
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-6">ไฟล์แนบ / ช่องทางตรวจงาน</h3>
                    
                    <?php if ($submission['file_path']): ?>
                        <div class="w-16 h-16 rounded-2xl bg-teal-50 text-teal-700 border border-teal-100 flex items-center justify-center text-xl font-bold mx-auto mb-4">
                            FILE
                        </div>
                        <h4 class="text-sm font-black text-slate-800 mb-1">อัปโหลดไฟล์จากระบบ</h4>
                        <p class="text-[10px] text-slate-400 font-medium mb-6">อัปโหลดผ่านเซิร์ฟเวอร์ <?= htmlspecialchars($branding['system_name']); ?> โดยตรง</p>
                        
                        <a href="../<?= e($submission['file_path']); ?>" target="_blank"
                           class="w-full inline-flex items-center justify-center gap-2 py-3 bg-slate-100 hover:bg-slate-200 border border-slate-200 text-slate-700 text-xs font-bold rounded-xl transition no-underline">
                            <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                            <span>เปิดอ่าน / ดาวน์โหลดไฟล์</span>
                        </a>
                    <?php endif; ?>

                    <?php if ($submission['drive_link']): ?>
                        <?php if ($submission['file_path']): ?>
                            <div class="h-[1px] bg-slate-100 my-6"></div>
                        <?php endif; ?>
                        
                        <div class="w-16 h-16 rounded-2xl bg-blue-50 text-blue-600 border border-blue-100 flex items-center justify-center text-xl font-bold mx-auto mb-4">
                            DRIVE
                        </div>
                        <h4 class="text-sm font-black text-slate-800 mb-1">ลิงก์แนบ Google Drive</h4>
                        <p class="text-[10px] text-slate-400 font-medium mb-6">ครูบันทึกแชร์ผ่านพื้นที่คลาวด์ภายนอก</p>
                        
                        <a href="<?= e($submission['drive_link']); ?>" target="_blank"
                           class="w-full inline-flex items-center justify-center gap-2 py-3 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl transition shadow-md shadow-blue-600/10 no-underline">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                            <span>เปิดไฟล์ใน Google Drive ↗</span>
                        </a>
                    <?php endif; ?>
                </div>

            </div>

        </div>
    <?php endif; ?>
</main>

<footer class="py-8 bg-white border-t border-slate-200 mt-16">
    <div class="max-w-5xl mx-auto px-4 text-center text-xs text-slate-400 font-medium">
        &copy; <?= date('Y'); ?> <?= htmlspecialchars($branding['college_name']); ?> &middot; ฝ่ายวิชาการ &middot; สงวนลิขสิทธิ์
    </div>
</footer>

</body>
</html>
