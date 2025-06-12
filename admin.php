<?php
// =========================================
//  ملف: admin_dashboard.php
//  المهام: لوحة تحكم كاملة لإدارة الأطباء، المرضى، الإجازات، وسجل الاستعلامات
//  - إضافة/تعديل/حذف إجازات نشطة وأرشيف
//  - تنسيق الطابع الزمني بتوقيت مكة (12 ساعة)
//  - فرز متقدم، بحث، فلترة حسب التاريخ بدون إعادة تحميل الصفحة
//  - تصميم مُحسّن وأكثر عصرية
//  - حل لمشكلات bind_param أثناء التعديل
// =========================================

date_default_timezone_set('Asia/Riyadh');

// ==== 1. إعدادات الجلسة والأمان ====
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params([
  'lifetime' => 86400,
  'path' => '/',
  'httponly' => true,
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'samesite' => 'Lax'
]);
session_start();
if (empty($_SESSION['admin_id'])) {
  header("Location: login.php");
  exit;
}
if (!isset($_SESSION['last_regen']) || $_SESSION['last_regen'] < time() - 300) {
  session_regenerate_id(true);
  $_SESSION['last_regen'] = time();
}

// ==== 2. حمايات CSRF ====
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
function csrf_input()
{
  return '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
}
function check_csrf()
{
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'message' => 'فشل التحقق الأمني (CSRF)']);
      exit;
    }
  }
}
check_csrf();

// ==== 3. اتصال MySQL (قاعدة البيانات الرئيسية) ====
$db_host = 'ocvwlym0zv3tcn68.cbetxkdyhwsb.us-east-1.rds.amazonaws.com';
$db_user = 'smrg7ak77778emkb';
$db_pass = 'fw69cuijof4ahuhb';
$db_name = 'ygscnjzq8ueid5yz';
$db_port = 3306;
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
$conn->set_charset('utf8mb4');
if ($conn->connect_errno) {
  die("<div style='text-align:center;margin-top:50px;color:#c00;'>فشل الاتصال بقاعدة البيانات</div>");
}

// ==== 4. إنشاء الجداول إذا لم تكن موجودة ====
$conn->query("CREATE TABLE IF NOT EXISTS patients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  identity_number VARCHAR(20) NOT NULL UNIQUE
) ENGINE=InnoDB CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS doctors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  title VARCHAR(100) NOT NULL,
  note TEXT DEFAULT NULL
) ENGINE=InnoDB CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS sick_leaves (
  id INT AUTO_INCREMENT PRIMARY KEY,
  service_code VARCHAR(30) NOT NULL UNIQUE,
  patient_id INT NOT NULL,
  doctor_id INT NOT NULL,
  issue_date DATE NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  days_count INT NOT NULL,
  is_companion TINYINT(1) NOT NULL DEFAULT 0,
  companion_name VARCHAR(100) DEFAULT NULL,
  companion_relation VARCHAR(100) DEFAULT NULL,
  is_paid TINYINT(1) NOT NULL DEFAULT 0,
  payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  FOREIGN KEY(doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS leave_queries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  leave_id INT NOT NULL,
  queried_at DATETIME NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'external',
  FOREIGN KEY(leave_id) REFERENCES sick_leaves(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('query','payment') NOT NULL,
  leave_id INT DEFAULT NULL,
  message VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  remind_at DATETIME DEFAULT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY(leave_id) REFERENCES sick_leaves(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4");

// ==== 5. دوال مساعده لإدارة المرضى والأطباء ====
function get_or_add_patient($conn, $name, $ident)
{
  $pid = null;
  $stmt = $conn->prepare("SELECT id FROM patients WHERE identity_number=?");
  $stmt->bind_param("s", $ident);
  $stmt->execute();
  $stmt->bind_result($pid);
  if ($stmt->fetch()) {
    $stmt->close();
    return $pid;
  }
  $stmt->close();
  $stmt = $conn->prepare("INSERT INTO patients (name, identity_number) VALUES (?, ?)");
  $stmt->bind_param("ss", $name, $ident);
  $stmt->execute();
  $pid = $stmt->insert_id;
  $stmt->close();
  return $pid;
}
function get_or_add_doctor($conn, $name, $title, $note=null)
{
  $did = null;
  $stmt = $conn->prepare("SELECT id FROM doctors WHERE name=? AND title=?");
  $stmt->bind_param("ss", $name, $title);
  $stmt->execute();
  $stmt->bind_result($did);
  if ($stmt->fetch()) {
    $stmt->close();
    return $did;
  }
  $stmt->close();
  $stmt = $conn->prepare("INSERT INTO doctors (name, title, note) VALUES (?, ?, ?)");
  $stmt->bind_param("sss", $name, $title, $note);
  $stmt->execute();
  $did = $stmt->insert_id;
  $stmt->close();
  return $did;
}

// ==== 6. معالجة طلبات AJAX ====
header("Cache-Control: no-cache, must-revalidate");
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $action = $_POST['action'];

  // ==== 6.1 إدارة الأطباء ====
  if ($action === 'add_doctor') {
    $dname = trim($_POST['doctor_name']);
    $dtitle = trim($_POST['doctor_title']);
    $dnote = isset($_POST['doctor_note']) ? trim($_POST['doctor_note']) : null;
    if (!$dname || !$dtitle) {
      echo json_encode(['success' => false, 'message' => 'أدخل اسم الطبيب والمسمى الوظيفي']);
      exit;
    }
    $did = get_or_add_doctor($conn, $dname, $dtitle, $dnote);
    $row = $conn->query("SELECT * FROM doctors WHERE id=$did")->fetch_assoc();
    echo json_encode(['success' => true, 'doctor' => $row]);
    exit;
  }
  if ($action === 'edit_doctor') {
    $did = intval($_POST['doctor_id']);
    $dname = trim($_POST['doctor_name']);
    $dtitle = trim($_POST['doctor_title']);
    $dnote = isset($_POST['doctor_note']) ? trim($_POST['doctor_note']) : null;
    $stmt = $conn->prepare("UPDATE doctors SET name=?, title=?, note=? WHERE id=?");
    $stmt->bind_param("sssi", $dname, $dtitle, $dnote, $did);
    $stmt->execute();
    $stmt->close();
    $row = $conn->query("SELECT * FROM doctors WHERE id=$did")->fetch_assoc();
    echo json_encode(['success' => true, 'doctor' => $row]);
    exit;
  }
  if ($action === 'delete_doctor') {
    $did = intval($_POST['doctor_id']);
    $conn->query("DELETE FROM doctors WHERE id=$did");
    echo json_encode(['success' => true]);
    exit;
  }

  // ==== 6.2 إدارة المرضى ====
  if ($action === 'add_patient') {
    $pname = trim($_POST['patient_name']);
    $pident = trim($_POST['identity_number']);
    if (!$pname || !$pident) {
      echo json_encode(['success' => false, 'message' => 'أدخل اسم المريض ورقم الهوية']);
      exit;
    }
    $pid = get_or_add_patient($conn, $pname, $pident);
    $row = $conn->query("SELECT * FROM patients WHERE id=$pid")->fetch_assoc();
    echo json_encode(['success' => true, 'patient' => $row]);
    exit;
  }
  if ($action === 'edit_patient') {
    $pid = intval($_POST['patient_id']);
    $pname = trim($_POST['patient_name']);
    $pident = trim($_POST['identity_number']);
    $stmt = $conn->prepare("UPDATE patients SET name=?, identity_number=? WHERE id=?");
    $stmt->bind_param("ssi", $pname, $pident, $pid);
    $stmt->execute();
    $stmt->close();
    $row = $conn->query("SELECT * FROM patients WHERE id=$pid")->fetch_assoc();
    echo json_encode(['success' => true, 'patient' => $row]);
    exit;
  }
  if ($action === 'delete_patient') {
    $pid = intval($_POST['patient_id']);
    $conn->query("DELETE FROM patients WHERE id=$pid");
    echo json_encode(['success' => true]);
    exit;
  }

  // ==== 6.3 إضافة إجازة جديدة (مع رمز تلقائي مُحسن) ====
  if ($action === 'add_leave') {
    // ===== 6.3.1 حصلنا على المريض =====
    if ($_POST['patient_select'] === 'manual') {
      $pm_name = trim($_POST['patient_manual_name']);
      $pm_id = trim($_POST['patient_manual_id']);
      if (!$pm_name || !$pm_id) {
        echo json_encode(['success' => false, 'message' => 'أدخل اسم المريض ورقم هويته']);
        exit;
      }
      $pid = get_or_add_patient($conn, $pm_name, $pm_id);
    } else {
      $pid = intval($_POST['patient_select']);
      if (!$pid) {
        echo json_encode(['success' => false, 'message' => 'اختر مريضًا أو أدخله يدويًا']);
        exit;
      }
    }

    // ===== 6.3.2 حصلنا على الطبيب =====
    if ($_POST['doctor_select'] === 'manual') {
      $dm_name = trim($_POST['doctor_manual_name']);
      $dm_title = trim($_POST['doctor_manual_title']);
      $dm_note = isset($_POST['doctor_manual_note']) ? trim($_POST['doctor_manual_note']) : null;
      if (!$dm_name || !$dm_title) {
        echo json_encode(['success' => false, 'message' => 'أدخل اسم الطبيب ومسمّاه الوظيفي']);
        exit;
      }
      $did = get_or_add_doctor($conn, $dm_name, $dm_title, $dm_note);
    } else {
      $did = intval($_POST['doctor_select']);
      if (!$did) {
        echo json_encode(['success' => false, 'message' => 'اختر طبيبًا أو ادخله يدويًا']);
        exit;
      }
      $noteUpd = isset($_POST['doctor_note']) ? trim($_POST['doctor_note']) : null;
      if ($noteUpd !== null && $noteUpd !== '') {
        $stmt = $conn->prepare("UPDATE doctors SET note=? WHERE id=?");
        $stmt->bind_param('si', $noteUpd, $did);
        $stmt->execute();
        $stmt->close();
      }
    }

   // ===== 6.3.3 توليد أو جلب رمز الخدمة (تاريخ = issue_date، والسّـفكس يزيد مع كل إجازة) =====
date_default_timezone_set('Asia/Aden');

$prefix_whitelist = ['GSL', 'PSL'];
$issue_date_raw   = trim($_POST['issue_date'] ?? '');

if ($issue_date_raw === '') {
    echo json_encode(['success' => false, 'message' => 'يجب تحديد تاريخ الإصدار.']); exit;
}

try {
    $issue_dt = new DateTime($issue_date_raw, new DateTimeZone('Asia/Aden'));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'صيغة تاريخ الإصدار غير صحيحة.']); exit;
}

$issue_ymd = $issue_dt->format('ymd'); // YYMMDD

/* ========== إدخال يدوي ========== */
if (trim($_POST['service_code_manual']) !== '') {

    $service_code = strtoupper(trim($_POST['service_code_manual']));
    if (!preg_match('/^(GSL|PSL)\d{6}\d{5}$/', $service_code)) {
        echo json_encode(['success' => false, 'message' => 'صيغة رمز الخدمة غير صحيحة.']); exit;
    }

    $chk = $conn->prepare("SELECT COUNT(*) FROM sick_leaves WHERE service_code=?");
    $chk->bind_param("s", $service_code);
    $chk->execute(); $chk->bind_result($cnt); $chk->fetch(); $chk->close();

    if ($cnt > 0) {
        echo json_encode(['success' => false, 'message' => 'رمز الخدمة مستخدم مسبقًا، اختر رمزًا آخر.']); exit;
    }

/* ========== توليد تلقائي ========== */
} else {

    $prefix = strtoupper($_POST['service_prefix'] ?? '');
    if (!in_array($prefix, $prefix_whitelist)) {
        echo json_encode(['success' => false, 'message' => 'اختر بادئة صحيحة (GSL أو PSL) أو أدخل الرمز يدويًا.']); exit;
    }

    // جلب آخر رمز لنفس البادئة (بصرف النظر عن التاريخ) للحصول على آخر suffix مُستخدَم
    $like_pattern = $prefix . '%';
    $stmt = $conn->prepare("
        SELECT service_code
        FROM   sick_leaves
        WHERE  service_code LIKE ?
        ORDER  BY service_code DESC
        LIMIT  1
    ");
    $stmt->bind_param("s", $like_pattern);
    $stmt->execute(); $stmt->bind_result($last_code); $stmt->fetch(); $stmt->close();

    if ($last_code) {
        $last_suffix = (int) substr($last_code, -5); // آخر خمسة أرقام
        $new_suffix  = $last_suffix + 1;
        if ($new_suffix > 99999) {                // إعادة الضبط إذا تجاوز الحد
            $new_suffix = 1;
        }
    } else {
        $new_suffix = 1; // أول رمز على الإطلاق لهذه البادئة
    }

    // تركيب الرمز النهائي: بادئة + تاريخ الإصدار + السّفكس المرقّم
    $service_code = $prefix . $issue_ymd . str_pad($new_suffix, 5, '0', STR_PAD_LEFT);

    // فحص أخير لعدم التكرار (احتياطًا للتزامن)
    $chk2 = $conn->prepare("SELECT COUNT(*) FROM sick_leaves WHERE service_code=?");
    $chk2->bind_param("s", $service_code);
    $chk2->execute(); $chk2->bind_result($cnt2); $chk2->fetch(); $chk2->close();

    if ($cnt2 > 0) {
        echo json_encode(['success' => false, 'message' => 'حدث تضارب في توليد الرمز، حاول مرة أخرى.']); exit;
    }
}

/* الآن $service_code جاهز للإدراج في قاعدة البيانات */

    // ===== 6.3.4 التواريخ وعدد الأيام =====
    $issue_date = $_POST['issue_date'];
    $start = $_POST['start_date'];
    $end = $_POST['end_date'];
    if (!$start || !$end || strtotime($end) < strtotime($start)) {
      echo json_encode(['success' => false, 'message' => 'تاريخ البداية يجب أن يكون قبل تاريخ النهاية']);
      exit;
    }
    $days_count = (isset($_POST['days_manual']) && $_POST['days_manual'] === '1')
      ? max(1, intval($_POST['days_count']))
      : (floor((strtotime($end) - strtotime($start)) / 86400) + 1);

    $is_comp = isset($_POST['is_companion']) && $_POST['is_companion'] === '1' ? 1 : 0;
    $comp_name = $is_comp ? trim($_POST['companion_name']) : null;
    $comp_rel = $is_comp ? trim($_POST['companion_relation']) : null;
    $is_paid = isset($_POST['is_paid']) && $_POST['is_paid'] === '1' ? 1 : 0;
    $payment_amount = isset($_POST['payment_amount']) ? floatval($_POST['payment_amount']) : 0;

    $created_at = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("INSERT INTO sick_leaves
      (service_code, patient_id, doctor_id, issue_date, start_date, end_date, days_count, is_companion, companion_name, companion_relation, is_paid, payment_amount, created_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("siisssiissids",
      $service_code, $pid, $did, $issue_date, $start, $end, $days_count, $is_comp, $comp_name, $comp_rel, $is_paid, $payment_amount, $created_at);
    $stmt->execute();
    $new_id = $stmt->insert_id;
    $stmt->close();

    $lv = $conn->query("SELECT sl.id, sl.patient_id, sl.service_code, sl.issue_date, sl.start_date, sl.end_date, sl.days_count,
                               sl.is_companion, sl.companion_name, sl.companion_relation,
                               sl.is_paid, sl.payment_amount,
                               DATE_FORMAT(sl.created_at, '%Y-%m-%d %r') AS created_at,
                               p.name AS patient_name, p.identity_number, d.name AS doctor_name, d.title AS doctor_title, d.note AS doctor_note,
                               (SELECT COUNT(*) FROM leave_queries WHERE leave_id=sl.id) AS queries_count
                        FROM sick_leaves sl
                        JOIN patients p ON sl.patient_id=p.id
                        JOIN doctors d ON sl.doctor_id=d.id
                        WHERE sl.id=$new_id")->fetch_assoc();

    echo json_encode(['success' => true, 'message' => 'تمت إضافة الإجازة بنجاح', 'leave' => $lv]);
    exit;
  }

  // ==== 6.4 تعديل إجازة (هنا أصلحنا bind_param “ssssiisssi”) ====
  if ($action === 'edit_leave') {
    $lid = intval($_POST['leave_id_edit']);
    $service_code = strtoupper(trim($_POST['service_code_edit']));
    $issue_date = $_POST['issue_date_edit'];
    $start = $_POST['start_date_edit'];
    $end = $_POST['end_date_edit'];
    if (!$start || !$end || strtotime($end) < strtotime($start)) {
      echo json_encode(['success' => false, 'message' => 'تاريخ البداية يجب أن يكون قبل تاريخ النهاية']);
      exit;
    }

    $days_count = (isset($_POST['days_manual_edit']) && $_POST['days_manual_edit'] === '1')
      ? max(1, intval($_POST['days_count_edit']))
      : (floor((strtotime($end) - strtotime($start)) / 86400) + 1);

    $is_comp = isset($_POST['is_companion_edit']) && $_POST['is_companion_edit'] === '1' ? 1 : 0;
    $comp_name = $is_comp ? trim($_POST['companion_name_edit']) : null;
    $comp_rel = $is_comp ? trim($_POST['companion_relation_edit']) : null;
    $is_paid = isset($_POST['is_paid_edit']) && $_POST['is_paid_edit'] === '1' ? 1 : 0;
    $payment_amount = isset($_POST['payment_amount_edit']) ? floatval($_POST['payment_amount_edit']) : 0;

    $updated_at = date('Y-m-d H:i:s');

    // منع تكرار رمز الخدمة على إجازة أخرى
    $chk2 = $conn->prepare("SELECT COUNT(*) FROM sick_leaves WHERE service_code=? AND id<>?");
    $chk2->bind_param("si", $service_code, $lid);
    $chk2->execute();
    $chk2->bind_result($cnt2);
    $chk2->fetch();
    $chk2->close();
    if ($cnt2 > 0) {
      echo json_encode(['success' => false, 'message' => 'رمز الخدمة مستخدم مسبقًا، اختر رمزًا آخر.']);
      exit;
    }

    // هنا أصلحنا bind_param ليكون "ssssiisssi" بدون مسافات
    $stmt = $conn->prepare("UPDATE sick_leaves SET
      service_code=?, issue_date=?, start_date=?, end_date=?, days_count=?, is_companion=?, companion_name=?, companion_relation=?, is_paid=?, payment_amount=?, updated_at=?
      WHERE id=?");
    $stmt->bind_param("ssssiissidsi",
      $service_code, $issue_date, $start, $end,
      $days_count, $is_comp,
      $comp_name, $comp_rel,
      $is_paid, $payment_amount,
      $updated_at, $lid
    );
    $stmt->execute();
    $stmt->close();

    $lv = $conn->query("SELECT sl.id, sl.patient_id, sl.service_code, sl.issue_date, sl.start_date, sl.end_date, sl.days_count,
                               sl.is_companion, sl.companion_name, sl.companion_relation,
                               sl.is_paid, sl.payment_amount,
                               DATE_FORMAT(sl.updated_at, '%Y-%m-%d %r') AS updated_at,
                               p.name AS patient_name, p.identity_number, d.name AS doctor_name, d.title AS doctor_title, d.note AS doctor_note,
                               (SELECT COUNT(*) FROM leave_queries WHERE leave_id=sl.id) AS queries_count
                        FROM sick_leaves sl
                        JOIN patients p ON sl.patient_id=p.id
                        JOIN doctors d ON sl.doctor_id=d.id
                        WHERE sl.id=$lid")->fetch_assoc();

    echo json_encode(['success' => true, 'message' => 'تم تعديل الإجازة بنجاح', 'leave' => $lv]);
    exit;
  }

  // ==== 6.5 أرشفة إجازة ====
  if ($action === 'delete_leave') {
    $lid = intval($_POST['leave_id']);
    $deleted_at = date('Y-m-d H:i:s');
    $conn->query("UPDATE sick_leaves SET is_deleted=1, deleted_at='$deleted_at' WHERE id=$lid");
    // نرجع التاريخ بصيغة 12 ساعة
    $deleted_formatted = date('Y-m-d h:i A', strtotime($deleted_at));
    echo json_encode(['success' => true, 'message' => 'تم نقل الإجازة إلى الأرشيف', 'deleted_at' => $deleted_formatted]);
    exit;
  }

  // ==== 6.6 استرجاع إجازة من الأرشيف ====
  if ($action === 'restore_leave') {
    $lid = intval($_POST['leave_id']);
    $conn->query("UPDATE sick_leaves SET is_deleted=0, deleted_at=NULL WHERE id=$lid");
    echo json_encode(['success' => true, 'message' => 'تمت استعادة الإجازة من الأرشيف']);
    exit;
  }

  // ==== 6.7 حذف نهائي إجازة ====
  if ($action === 'force_delete_leave') {
    $lid = intval($_POST['leave_id']);
    $conn->query("DELETE FROM sick_leaves WHERE id=$lid");
    echo json_encode(['success' => true, 'message' => 'تم الحذف النهائي للإجازة']);
    exit;
  }

  // ==== 6.8 حذف جميع الإجازات المؤرشفة ====
  if ($action === 'force_delete_all_archived') {
    $conn->query("DELETE FROM sick_leaves WHERE is_deleted=1");
    echo json_encode(['success' => true, 'message' => 'تم حذف جميع الإجازات المؤرشفة نهائيًا']);
    exit;
  }

  // ==== 6.9 تسجيل استعلام جديد (نشطة) ====
  if ($action === 'add_query') {
    $lid = intval($_POST['leave_id']);
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO leave_queries (leave_id, queried_at) VALUES (?, ?)");
    $stmt->bind_param("is", $lid, $now);
    $stmt->execute();
    $stmt->close();
    $cntRes = $conn->query("SELECT COUNT(*) AS c FROM leave_queries WHERE leave_id=$lid");
    $newCount = $cntRes->fetch_assoc()['c'];
    echo json_encode(['success' => true, 'message' => 'تم تسجيل الاستعلام', 'new_count' => $newCount]);
    exit;
  }

  // ==== 6.10 حذف سجل استعلام واحد ====
  if ($action === 'delete_query') {
    $qid = intval($_POST['query_id']);
    $conn->query("DELETE FROM leave_queries WHERE id=$qid");
    echo json_encode(['success' => true, 'message' => 'تم حذف سجل الاستعلام']);
    exit;
  }

  // ==== 6.11 حذف جميع سجلات الاستعلام ====
  if ($action === 'delete_all_queries') {
    $conn->query("DELETE FROM leave_queries");
    echo json_encode(['success' => true, 'message' => 'تم حذف جميع سجلات الاستعلام']);
    exit;
  }

  // ==== 6.12 جلب تفاصيل استعلامات إجازة معينة ====
  if ($action === 'fetch_queries') {
    $lid = intval($_POST['leave_id']);
    $stmt = $conn->prepare("SELECT id, DATE_FORMAT(DATE_ADD(queried_at, INTERVAL 3 HOUR), '%Y-%m-%d %r') AS queried_at
                             FROM leave_queries
                             WHERE leave_id=?
                             ORDER BY queried_at DESC");
    $stmt->bind_param("i", $lid);
    $stmt->execute();
    $res = $stmt->get_result();
    $arr = [];
    while ($r = $res->fetch_assoc()) {
      $arr[] = ['id' => $r['id'], 'queried_at' => $r['queried_at']];
    }
    $stmt->close();
    echo json_encode(['success' => true, 'queries' => $arr]);
    exit;
  }

  if ($action === 'delete_notification') {
    $nid = intval($_POST['notification_id']);
    $conn->query("DELETE FROM notifications WHERE id=$nid");
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'delete_all_notifications') {
    $type = $conn->real_escape_string($_POST['n_type']);
    $conn->query("DELETE FROM notifications WHERE type='$type'");
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'get_leave_amount') {
    $lid = intval($_POST['leave_id']);
    $res = $conn->query("SELECT payment_amount FROM sick_leaves WHERE id=$lid");
    $amt = $res->fetch_assoc()['payment_amount'] ?? 0;
    echo json_encode(['success' => true, 'amount' => $amt]);
    exit;
  }

  if ($action === 'mark_leave_paid') {
    $lid = intval($_POST['leave_id']);
    $amount = floatval($_POST['amount']);
    $conn->query("UPDATE sick_leaves SET is_paid=1, payment_amount=$amount WHERE id=$lid");
    $conn->query("DELETE FROM notifications WHERE type='payment' AND leave_id=$lid");
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'fetch_notifications') {
    $arr = [];
    $res = $conn->query("SELECT id, message, created_at, leave_id FROM notifications WHERE type='payment' ORDER BY created_at DESC");
    while ($row = $res->fetch_assoc()) { $arr[] = $row; }
    echo json_encode(['success' => true, 'data' => $arr]);
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'إجراء غير معروف']);
  exit;
}

// ==== 7. جلب الإحصائيات العامة ====
$stats = [
  'total' => 0,
  'active' => 0,
  'archived' => 0,
  'doctors' => 0,
  'patients' => 0,
  'paid' => 0,
  'unpaid' => 0,
  'paid_amount' => 0,
  'unpaid_amount' => 0
];
$res = $conn->query("SELECT COUNT(*) as c FROM sick_leaves WHERE is_deleted=0");
$stats['active'] = $res->fetch_assoc()['c'];
$res = $conn->query("SELECT COUNT(*) as c FROM sick_leaves WHERE is_deleted=1");
$stats['archived'] = $res->fetch_assoc()['c'];
$res = $conn->query("SELECT COUNT(*) as c FROM sick_leaves WHERE is_deleted=0 AND is_paid=1");
$stats['paid'] = $res->fetch_assoc()['c'];
$res = $conn->query("SELECT COUNT(*) as c FROM sick_leaves WHERE is_deleted=0 AND is_paid=0");
$stats['unpaid'] = $res->fetch_assoc()['c'];
$res = $conn->query("SELECT IFNULL(SUM(payment_amount),0) AS amt FROM sick_leaves WHERE is_deleted=0 AND is_paid=1");
$stats['paid_amount'] = $res->fetch_assoc()['amt'];
$res = $conn->query("SELECT IFNULL(SUM(payment_amount),0) AS amt FROM sick_leaves WHERE is_deleted=0 AND is_paid=0");
$stats['unpaid_amount'] = $res->fetch_assoc()['amt'];
$res = $conn->query("SELECT COUNT(*) as c FROM sick_leaves");
$stats['total'] = $res->fetch_assoc()['c'];
$res = $conn->query("SELECT COUNT(*) as c FROM doctors");
$stats['doctors'] = $res->fetch_assoc()['c'];
$res = $conn->query("SELECT COUNT(*) as c FROM patients");
$stats['patients'] = $res->fetch_assoc()['c'];

// ==== 8. جلب قوائم المرضى والأطباء لعرضها في <select> ====
$patients = [];
$res = $conn->query("SELECT id, name, identity_number FROM patients ORDER BY name ASC");
while ($row = $res->fetch_assoc()) {
  $patients[] = $row;
}

$doctors = [];
$res = $conn->query("SELECT id, name, title, note FROM doctors ORDER BY name ASC");
while ($row = $res->fetch_assoc()) {
  $doctors[] = $row;
}

// ==== 8.1 إحصائيات المدفوعات لكل مريض ====
$payments = [];
$res = $conn->query("SELECT p.id, p.name,
       COUNT(sl.id) AS total,
       SUM(CASE WHEN sl.is_paid=1 THEN 1 ELSE 0 END) AS paid_count,
       SUM(CASE WHEN sl.is_paid=0 THEN 1 ELSE 0 END) AS unpaid_count,
       IFNULL(SUM(CASE WHEN sl.is_paid=1 THEN sl.payment_amount ELSE 0 END),0) AS paid_amount,
       IFNULL(SUM(CASE WHEN sl.is_paid=0 THEN sl.payment_amount ELSE 0 END),0) AS unpaid_amount
    FROM patients p
    LEFT JOIN sick_leaves sl ON sl.patient_id=p.id AND sl.is_deleted=0
    GROUP BY p.id ORDER BY p.name ASC");
while ($row = $res->fetch_assoc()) {
  $payments[] = $row;
}

// ==== 9. جلب بيانات الإجازات النشطة (للجدول الرئيسي) ====
$leaves = [];
$res = $conn->query("SELECT sl.id, sl.patient_id, sl.service_code, sl.issue_date, sl.start_date, sl.end_date, sl.days_count,
                             sl.is_companion, sl.companion_name, sl.companion_relation,
                             sl.is_paid, sl.payment_amount,
                             DATE_FORMAT(sl.created_at, '%Y-%m-%d %r') AS created_at,
                             p.name AS patient_name, p.identity_number, d.name AS doctor_name, d.title AS doctor_title, d.note AS doctor_note,
                             (SELECT COUNT(*) FROM leave_queries WHERE leave_id=sl.id) AS queries_count
                      FROM sick_leaves sl
                      JOIN patients p ON sl.patient_id=p.id
                      JOIN doctors d ON sl.doctor_id=d.id
                      WHERE sl.is_deleted=0
                      ORDER BY sl.created_at DESC");
while ($row = $res->fetch_assoc()) {
  $leaves[] = $row;
}

// ==== 10. جلب بيانات الأرشيف (الإجازات المحذوفة) ====
$archived = [];
$res = $conn->query("SELECT sl.id, sl.patient_id, sl.service_code, sl.issue_date, sl.start_date, sl.end_date, sl.days_count,
                             sl.is_companion, sl.companion_name, sl.companion_relation,
                             sl.is_paid, sl.payment_amount,
                             DATE_FORMAT(sl.deleted_at, '%Y-%m-%d %r') AS deleted_at,
                             p.name AS patient_name, p.identity_number, d.name AS doctor_name, d.title AS doctor_title, d.note AS doctor_note,
                             (SELECT COUNT(*) FROM leave_queries WHERE leave_id=sl.id) AS queries_count
                      FROM sick_leaves sl
                      JOIN patients p ON sl.patient_id=p.id
                      JOIN doctors d ON sl.doctor_id=d.id
                      WHERE sl.is_deleted=1
                      ORDER BY sl.deleted_at DESC");
while ($row = $res->fetch_assoc()) {
  $archived[] = $row;
}

// ==== 11. جلب سجلات الاستعلامات للإجازات النشطة (للجدول الفرعي) ====
$queries = [];
$res = $conn->query("SELECT lq.id AS qid, lq.leave_id, sl.service_code, p.name AS patient_name, p.identity_number, 
                           DATE_FORMAT(DATE_ADD(lq.queried_at, INTERVAL 3 HOUR), '%Y-%m-%d %r') AS queried_at
                     FROM leave_queries lq
                     JOIN sick_leaves sl ON lq.leave_id=sl.id AND sl.is_deleted=0
                     JOIN patients p ON sl.patient_id=p.id
                     ORDER BY lq.queried_at DESC");
while ($row = $res->fetch_assoc()) {
  $queries[] = $row;
}

// ==== 12. فحص الإجازات غير المدفوعة وإنشاء إشعار عند الحاجة ====
$res = $conn->query("SELECT id, service_code, created_at FROM sick_leaves WHERE is_paid=0 AND is_deleted=0");
$now = time();
while ($r = $res->fetch_assoc()) {
  if (strtotime($r['created_at']) < $now - 300) {
    $svc = $r['service_code'];
    $lid = $r['id'];
    $msg = 'إجازة غير مدفوعة ' . $svc;
    $conn->query("INSERT INTO notifications (type, leave_id, message, created_at) SELECT 'payment',$lid,'$msg',NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE type='payment' AND leave_id=$lid)");
  }
}

// ==== 13. جلب إشعارات المدفوعات ====
$notifications_payment = [];
$res = $conn->query("SELECT id, message, created_at, leave_id FROM notifications WHERE type='payment' ORDER BY created_at DESC");
while ($row = $res->fetch_assoc()) { $notifications_payment[] = $row; }
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>لوحة تحكم الإجازات – موقع صحتي</title>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    /* ======================== متغيرات Light & Dark ======================== */
    :root {
      --bg-color: #f0f7fa;
      --card-bg: #ffffff;
      --text-color: #000000;
      --primary-color: #0288d1;
      --secondary-color: #64b5f6;
      --shadow-color: rgba(179, 229, 252, 0.6);
      --danger-color: #d32f2f;
      --warning-color: #fbc02d;
      --success-color: #2e7d32;
      --toast-bg: rgba(0, 0, 0, 0.7);
      --toast-text: #ffffff;
      --border-radius: 12px;
      --transition-speed: 0.3s;
      --font-main: 'Cairo', sans-serif;
    }

    .dark-mode {
      --bg-color: #121212;
      --card-bg: #1f1f1f;
      --text-color: #e0e0e0;
      --primary-color: #90caf9;
      --secondary-color: #1565c0;
      --shadow-color: rgba(0, 0, 0, 0.6);
      --danger-color: #ef5350;
      --warning-color: #ffa726;
      --success-color: #66bb6a;
      --toast-bg: rgba(255, 255, 255, 0.1);
      --toast-text: #ffffff;
    }

    /* ======================== التنسيقات العامة ======================== */
    body {
      background: var(--bg-color);
      color: var(--text-color);
      font-family: var(--font-main);
      min-height: 100vh;
      transition: background var(--transition-speed), color var(--transition-speed);
    }

    h5 {
      font-weight: 600;
      color: var(--primary-color);
      border-bottom: 2px solid var(--secondary-color);
      padding-bottom: 6px;
      margin-bottom: 1rem;
      animation: fadeIn 0.5s ease-in-out;
    }

    .card-custom {
      background: var(--card-bg);
      border-radius: var(--border-radius);
      box-shadow: 0 4px 18px var(--shadow-color);
      margin-bottom: 2rem;
      transition: background var(--transition-speed), color var(--transition-speed), box-shadow var(--transition-speed);
      animation: fadeIn 0.5s ease-in-out;
    }

    .btn-gradient {
      background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
      color: #fff;
      border-radius: 50px;
      font-weight: bold;
      border: none;
      transition: background var(--transition-speed), transform 0.2s;
      box-shadow: 0 2px 6px var(--shadow-color);
    }

    .btn-gradient:hover {
      background: linear-gradient(90deg, var(--primary-color), #01579b);
      transform: scale(1.03);
    }

    .btn-danger-custom {
      background: var(--danger-color);
      color: #fff;
      border: none;
      border-radius: 8px;
      transition: background var(--transition-speed), transform 0.2s;
      box-shadow: 0 2px 6px var(--shadow-color);
    }

    .btn-danger-custom:hover {
      background: #c62828;
      transform: scale(1.03);
    }

    .btn-warning-custom {
      background: var(--warning-color);
      color: #000;
      border: none;
      border-radius: 8px;
      transition: background var(--transition-speed), transform 0.2s;
      box-shadow: 0 2px 6px var(--shadow-color);
    }

    .btn-warning-custom:hover {
      background: #f57f17;
      transform: scale(1.03);
    }

    .btn-success-custom {
      background: var(--success-color);
      color: #fff;
      border: none;
      border-radius: 8px;
      transition: background var(--transition-speed), transform 0.2s;
      box-shadow: 0 2px 6px var(--shadow-color);
    }

    .btn-success-custom:hover {
      background: #2e7d32;
      transform: scale(1.03);
    }

    .table-responsive {
      border-radius: var(--border-radius);
      box-shadow: 0 2px 10px var(--shadow-color);
      background: var(--card-bg);
      max-height: 400px;
      overflow-y: auto;
      transition: background var(--transition-speed);
      animation: fadeIn 0.5s ease-in-out;
    }

    .table th,
    .table td {
      vertical-align: middle;
    }

    .table-hover tbody tr:hover {
      background: rgba(2, 136, 209, 0.1);
    }

    label {
      font-weight: bold;
      color: var(--primary-color);
    }

    .modal-content {
      border-radius: var(--border-radius);
      background: var(--card-bg);
      color: var(--text-color);
      transition: background var(--transition-speed), color var(--transition-speed);
      animation: fadeIn 0.3s ease-in-out;
    }

    .modal-backdrop.show {
      opacity: 0.5;
    }

    .stats-box {
      background: var(--primary-color);
      color: #fff;
      border-radius: var(--border-radius);
      padding: 12px 6px;
      text-align: center;
      font-size: 1rem;
      transition: background var(--transition-speed);
      animation: fadeIn 0.5s ease-in-out;
      box-shadow: 0 2px 6px var(--shadow-color);
    }

    .search-input {
      margin-bottom: 0.5rem;
    }

    #darkModeToggle {
      position: fixed;
      bottom: 1rem;
      left: 1rem;
      z-index: 3000;
      background: var(--primary-color);
      color: #fff;
      border: none;
      padding: 0.5rem 1rem;
      border-radius: 30px;
      font-size: 0.9rem;
      box-shadow: 0 2px 10px var(--shadow-color);
      transition: background var(--transition-speed), color var(--transition-speed);
    }

    #alert-container {
      position: fixed;
      top: 1rem;
      right: 1rem;
      z-index: 2500;
      width: 320px;
      transition: right var(--transition-speed);
    }

    #alert-container .toast {
      background: var(--toast-bg);
      color: var(--toast-text);
      border-radius: var(--border-radius);
      box-shadow: 0 2px 12px var(--shadow-color);
      margin-bottom: 0.5rem;
      animation: fadeIn 0.5s ease-in-out;
    }

    .loading-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.4);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 4000;
      display: none;
    }

    .loading-overlay.show {
      display: flex;
      opacity: 1;
      animation: fadeIn 0.3s ease-in-out;
    }

    .no-results {
      text-align: center;
      font-style: italic;
      color: #666;
      padding: 0.5rem 0;
    }

    .action-btn {
      font-size: 0.9rem;
      margin: 0 2px;
      padding: 4px 8px;
      border-radius: 6px;
      transition: transform 0.2s;
    }

    .action-btn:hover {
      transform: scale(1.1);
    }

    .hidden-field {
      display: none;
    }

    /* حركة FadeIn خفيفة */
    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* تنسيقات للشاشات الصغيرة */
    @media(max-width:600px) {
      .stats-box {
        font-size: 13px;
        padding: 10px 3px;
      }
      .card-custom {
        margin-bottom: 10px;
      }
      .modal-dialog {
        max-width: 98vw !important;
      }
    }
  </style>
</head>
<body>
  <!-- زر تبديل الوضع الداكن/الفاتح -->
  <button id="darkModeToggle"><i class="bi bi-moon-fill"></i> داكن</button>

  <!-- حاوية الإشعارات (Toast-like) -->
  <div id="alert-container"></div>

  <!-- شاشة التحميل -->
  <div class="loading-overlay" id="loadingOverlay">
    <div class="spinner-border text-light" role="status" style="width:4rem; height:4rem;">
      <span class="visually-hidden">جاري التحميل...</span>
    </div>
  </div>

  <!-- نافذة التأكيد العامة -->
  <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">تأكيد الإجراء</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
        </div>
        <div class="modal-body">
          <p id="confirmMessage">هل أنت متأكد؟</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
          <button type="button" class="btn btn-danger-custom" id="confirmYesBtn">تأكيد</button>
        </div>
      </div>
    </div>
  </div>

  <!-- نافذة عرض تفاصيل استعلامات الإجازة -->
  <div class="modal fade" id="viewQueriesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">تفاصيل استعلامات الإجازة</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
        </div>
        <div class="modal-body">
          <div class="d-flex justify-content-end mb-2">
            <button class="btn btn-danger-custom btn-sm me-2" id="btn-delete-all-queries">
              <i class="bi bi-trash3-fill"></i> حذف كل الاستعلامات
            </button>
            <button class="btn btn-light btn-sm me-2" id="sortQueriesDetailNewest">
              <i class="bi bi-arrow-down-circle"></i> الأحدث
            </button>
            <button class="btn btn-light btn-sm me-2" id="sortQueriesDetailOldest">
              <i class="bi bi-arrow-up-circle"></i> الأقدم
            </button>
            <button class="btn btn-light btn-sm" id="sortQueriesDetailReset">
              <i class="bi bi-arrow-repeat"></i> الافتراضي
            </button>
          </div>
          <div id="queriesDetailsContainer">
            <p class="text-center">جارٍ جلب البيانات...</p>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
        </div>
      </div>
    </div>
  </div>

  <div class="container-fluid py-2">
    <!-- إحصائيات سريعة -->
    <div class="row g-2 mb-3">
      <div class="col stats-box">إجمالي الإجازات<br><?= $stats['total'] ?></div>
      <div class="col stats-box">نشطة<br><?= $stats['active'] ?></div>
      <div class="col stats-box">أرشيف<br><?= $stats['archived'] ?></div>
      <div class="col stats-box">المرضى<br><?= $stats['patients'] ?></div>
      <div class="col stats-box">الأطباء<br><?= $stats['doctors'] ?></div>
    </div>

    <!-- زر إشعارات المدفوعات -->
    <div class="mb-3 text-end">
      <button class="btn btn-gradient btn-sm" data-bs-toggle="modal" data-bs-target="#paymentNotifModal" id="btn-payment-notifs">
        <i class="bi bi-bell"></i> إشعارات المدفوعات
      </button>
    </div>

    <!-- أزرار الوصول السريع (الأطباء، المرضى، سجل الاستعلامات) -->
    <div class="mb-2 d-flex gap-2 justify-content-end flex-wrap">
      <button class="btn btn-gradient btn-sm" data-bs-toggle="modal" data-bs-target="#doctorsModal">
        <i class="bi bi-person-badge-fill"></i> إدارة الأطباء
      </button>
      <button class="btn btn-gradient btn-sm" data-bs-toggle="modal" data-bs-target="#patientsModal">
        <i class="bi bi-person-lines-fill"></i> إدارة المرضى
      </button>
      <button class="btn btn-gradient btn-sm" data-bs-toggle="collapse" data-bs-target="#queriesSection">
        <i class="bi bi-journal-text"></i> سجل الاستعلامات
      </button>
    </div>

    <!-- بطاقة إضافة إجازة مرضية -->
    <div class="card card-custom p-3" id="addLeaveSection">
      <h5>إضافة إجازة مرضية</h5>
      <form id="leaveForm" class="row g-2 align-items-end needs-validation" novalidate>
        <?= csrf_input(); ?>

        <!-- بادئة أو رمز الخدمة يدوي -->
        <div class="col-md-3">
          <label>بادئة رمز الخدمة</label>
          <select name="service_prefix" id="service_prefix" class="form-select">
            <option value="">اختر بادئة</option>
            <option value="GSL">GSL (مستشفى)</option>
            <option value="PSL">PSL (مركز)</option>
          </select>
        </div>
        <div class="col-md-3">
          <label>رمز الخدمة يدوي</label>
          <input type="text" name="service_code_manual" id="service_code_manual" class="form-control"
            placeholder="أدخل رمز الخدمة يدويًا">
          <div class="form-text">إذا تُركت هذه الخانة فارغة، سيتم التوليد تلقائيًا.</div>
        </div>
        <div class="col-md-6"></div>

        <!-- حقل البحث واختيار/إدخال المريض -->
        <div class="col-md-6">
          <label>ابحث عن مريض</label>
          <div class="input-group mb-1">
            <input type="text" id="searchPatient" class="form-control" placeholder="ابحث بالاسم أو الهوية">
            <button class="btn btn-primary" type="button" id="btn-search-patient"><i class="bi bi-search"></i>
              بحث</button>
          </div>
          <select name="patient_select" id="patient_select" class="form-select" required>
            <option value="">اختر مريضًا</option>
            <?php foreach ($patients as $p): ?>
              <option value="<?= $p['id'] ?>" data-name="<?= htmlspecialchars(strtolower($p['name'])) ?>"
                data-identity="<?= htmlspecialchars(strtolower($p['identity_number'])) ?>">
                <?= htmlspecialchars($p['name'] . ' (' . $p['identity_number'] . ')') ?>
              </option>
            <?php endforeach; ?>
            <option value="manual">إدخال يدوي</option>
          </select>
          <div class="invalid-feedback">اختر مريضًا أو أدخله يدويًا.</div>
          <input type="text" name="patient_manual_name" id="patient_manual_name" class="form-control mt-2 hidden-field"
            placeholder="اسم جديد">
          <input type="text" name="patient_manual_id" id="patient_manual_id" class="form-control mt-1 hidden-field"
            placeholder="رقم الهوية">
          <div class="invalid-feedback">أدخل اسم المريض ورقم هويته.</div>
          <div id="noPatientResult" class="no-results mt-1" style="display:none;">
            لم يتم العثور على مريض مطابق.
          </div>
        </div>

        <!-- حقل البحث واختيار/إدخال الطبيب -->
        <div class="col-md-6">
          <label>ابحث عن طبيب</label>
          <div class="input-group mb-1">
            <input type="text" id="searchDoctor" class="form-control" placeholder="ابحث بالاسم أو المسمى">
            <button class="btn btn-primary" type="button" id="btn-search-doctor"><i class="bi bi-search"></i>
              بحث</button>
          </div>
          <select name="doctor_select" id="doctor_select" class="form-select" required>
            <option value="">اختر طبيبًا</option>
            <?php foreach ($doctors as $d): ?>
              <option value="<?= $d['id'] ?>" data-name="<?= htmlspecialchars(strtolower($d['name'])) ?>"
                data-title="<?= htmlspecialchars(strtolower($d['title'])) ?>" data-note="<?= htmlspecialchars(strtolower($d['note'])) ?>">
                <?= htmlspecialchars($d['name'] . ' - ' . $d['title']) ?>
              </option>
            <?php endforeach; ?>
            <option value="manual">إدخال يدوي</option>
          </select>
          <div class="invalid-feedback">اختر طبيبًا أو ادخله يدويًا.</div>
          <input type="text" name="doctor_manual_name" id="doctor_manual_name" class="form-control mt-2 hidden-field"
            placeholder="اسم الطبيب">
          <input type="text" name="doctor_manual_title" id="doctor_manual_title" class="form-control mt-1 hidden-field"
            placeholder="المسمى الوظيفي">
          <input type="text" name="doctor_manual_note" id="doctor_manual_note" class="form-control mt-1 hidden-field"
            placeholder="ملاحظة">
          <input type="text" id="doctor_saved_title" class="form-control mt-1 hidden-field" readonly
            placeholder="المسمى الوظيفي">
          <div class="invalid-feedback">أدخل اسم الطبيب ومسمّاه الوظيفي.</div>
          <div id="noDoctorResult" class="no-results mt-1" style="display:none;">
            لم يتم العثور على طبيب مطابق.
          </div>
        </div>

        <!-- التواريخ وعدد الأيام -->
        <div class="col-md-4">
          <label>تاريخ الإصدار</label>
          <input type="date" name="issue_date" class="form-control" required>
          <div class="invalid-feedback">اختر تاريخ الإصدار.</div>
        </div>
        <div class="col-md-4">
          <label>بداية الإجازة</label>
          <input type="date" name="start_date" id="start_date" class="form-control" required>
          <div class="invalid-feedback">اختر تاريخ بداية الإجازة.</div>
        </div>
        <div class="col-md-4">
          <label>نهاية الإجازة</label>
          <input type="date" name="end_date" id="end_date" class="form-control" required>
          <div class="invalid-feedback">اختر تاريخ نهاية الإجازة.</div>
        </div>

        <div class="col-md-4">
          <label>عدد الأيام</label>
          <input type="number" name="days_count" id="days_count" class="form-control" readonly required>
          <div class="form-check mt-1">
            <input class="form-check-input" type="checkbox" id="days_manual" name="days_manual" value="1">
            <label class="form-check-label" for="days_manual" style="font-size:13px">أدخل يدويًا</label>
          </div>
          <div class="invalid-feedback">حدد عدد الأيام أو ادخله يدويًا.</div>
        </div>

        <!-- مرافق أم لا -->
        <div class="col-md-2">
          <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="is_companion" id="is_companion" value="1">
            <label class="form-check-label" for="is_companion">إجازة مرافق</label>
          </div>
        </div>
        <div class="col-md-3 mt-2 companion-fields hidden-field">
          <label>اسم المرافق</label>
          <input type="text" name="companion_name" class="form-control">
          <div class="invalid-feedback">أدخل اسم المرافق.</div>
        </div>
        <div class="col-md-3 mt-2 companion-fields hidden-field">
          <label>صلة القرابة</label>
          <input type="text" name="companion_relation" class="form-control">
          <div class="invalid-feedback">أدخل صلة القرابة.</div>
        </div>

        <div class="col-md-2">
          <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="is_paid" id="is_paid" value="1">
            <label class="form-check-label" for="is_paid">مدفوعة</label>
          </div>
        </div>
        <div class="col-md-3">
          <label>المبلغ</label>
          <input type="number" step="0.01" name="payment_amount" id="payment_amount" class="form-control" value="0">
        </div>

        <div class="col-12 text-center mt-3">
          <button type="submit" class="btn btn-gradient w-100">
            <i class="bi bi-plus-circle"></i> إضافة الإجازة
          </button>
        </div>
      </form>
    </div>

    <!-- جدول الإجازات النشطة -->
    <div class="card card-custom mt-4" id="activeSection">
      <div class="card-header d-flex justify-content-between align-items-center"
        style="background: var(--secondary-color); color: #fff; border-radius: var(--border-radius) var(--border-radius) 0 0;">
        <span class="fw-bold">جميع الإجازات المرضية النشطة</span>
        <div class="d-flex gap-2">
          <button class="btn btn-light btn-sm" id="sortLeavesNewest">
            <i class="bi bi-arrow-down-circle"></i> الأحدث
          </button>
          <button class="btn btn-light btn-sm" id="sortLeavesOldest">
            <i class="bi bi-arrow-up-circle"></i> الأقدم
          </button>
          <button class="btn btn-light btn-sm" id="sortLeavesReset">
            <i class="bi bi-arrow-repeat"></i> افتراضي
          </button>
        </div>
      </div>
      <div class="card-body">
        <!-- فلترة حسب تاريخ الإضافة -->
        <div class="row g-2 mb-3">
          <div class="col-md-3">
            <input type="date" id="filter_from_date" class="form-control" placeholder="من تاريخ">
          </div>
          <div class="col-md-3">
            <input type="date" id="filter_to_date" class="form-control" placeholder="إلى تاريخ">
          </div>
          <div class="col-md-6 d-flex gap-2">
            <button class="btn btn-primary btn-sm" id="btn-filter-dates"><i class="bi bi-funnel"></i> فلترة</button>
            <button class="btn btn-light btn-sm" id="btn-reset-dates"><i class="bi bi-arrow-counterclockwise"></i> افتراضي</button>
          </div>
        </div>

        <div class="input-group mb-2">
          <input type="text" id="searchLeaves" class="form-control" placeholder="ابحث برمز الخدمة أو المريض أو الطبيب">
          <button class="btn btn-primary" type="button" id="btn-search-leaves"><i class="bi bi-search"></i> بحث</button>
        </div>
        <div class="mb-3 d-flex gap-2">
          <button class="btn btn-success btn-sm" id="showPaidLeaves">مدفوعة فقط</button>
          <button class="btn btn-warning btn-sm" id="showUnpaidLeaves">غير مدفوعة فقط</button>
          <button class="btn btn-light btn-sm" id="showAllLeaves">الكل</button>
        </div>
        <div class="table-responsive">
          <table class="table table-striped table-hover text-center" id="leavesTable">
            <thead class="table-light">
              <tr>
                <th>رقم</th>
                <th>رمز الخدمة</th>
                <th>المريض</th>
                <th>الهوية</th>
                <th>الطبيب</th>
                <th>المسمى</th>
                <th>ملاحظة</th>
                <th>ملاحظة</th>
                <th>تاريخ الإصدار</th>
                <th>من</th>
                <th>إلى</th>
                <th>الأيام</th>
                <th>نوع الإجازة</th>
                <th>عدد الاستعلامات</th>
                <th>تاريخ الإضافة</th>
                <th>مدفوعة؟</th>
                <th>المبلغ</th>
                <th style="min-width:300px;">تحكم</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($leaves as $idx => $lv): ?>
                <tr data-id="<?= $lv['id'] ?>" data-patient="<?= $lv['patient_id'] ?>"
                    data-comp-name="<?= htmlspecialchars($lv['companion_name']) ?>"
                    data-comp-rel="<?= htmlspecialchars($lv['companion_relation']) ?>">
                  <td class="row-num"></td>
                  <td class="cell-service"><?= htmlspecialchars(strtoupper($lv['service_code'])) ?></td>
                  <td class="cell-patient"><?= htmlspecialchars($lv['patient_name']) ?></td>
                  <td class="cell-identity"><?= htmlspecialchars($lv['identity_number']) ?></td>
                  <td class="cell-doctor"><?= htmlspecialchars($lv['doctor_name']) ?></td>
                  <td><?= htmlspecialchars($lv['doctor_title']) ?></td>
                  <td><?= htmlspecialchars($lv['doctor_note']) ?></td>
                  <td class="cell-issue"><?= htmlspecialchars($lv['issue_date']) ?></td>
                  <td><?= htmlspecialchars($lv['start_date']) ?></td>
                  <td><?= htmlspecialchars($lv['end_date']) ?></td>
                  <td><?= htmlspecialchars($lv['days_count']) ?></td>
                  <td>
                    <?= $lv['is_companion']
                      ? '<span class="badge bg-warning text-dark">مرافق</span>'
                      : '<span class="badge bg-info text-dark">أساسي</span>' ?>
                  </td>
                  <td class="cell-queries-count"><?= $lv['queries_count'] ?></td>
                  <td class="cell-created"><?= htmlspecialchars($lv['created_at']) ?></td>
                  <td><?= $lv['is_paid'] ? 'نعم' : 'لا' ?></td>
                  <td><?= number_format($lv['payment_amount'], 2) ?></td>
                  <td>
                    <button class="btn btn-info btn-sm action-btn btn-edit-leave"><i class="bi bi-pencil-square"></i>
                      تعديل</button>
                    <button class="btn btn-danger btn-sm action-btn btn-delete-leave"><i class="bi bi-trash-fill"></i>
                      أرشفة</button>
                    <button class="btn btn-warning btn-sm action-btn btn-view-queries"><i class="bi bi-journal-text"></i>
                      استعلامات</button>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($leaves)): ?>
                <tr class="no-results">
                  <td colspan="17">لا توجد إجازات نشطة حاليًا.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- جدول الأرشيف -->
    <div class="card card-custom mt-4 mb-5" id="archivedSection">
      <div class="card-header d-flex justify-content-between align-items-center"
        style="background: var(--danger-color); color: #fff; border-radius: var(--border-radius) var(--border-radius) 0 0;">
        <span class="fw-bold">الأرشيف (الإجازات المحذوفة)</span>
        <div class="d-flex gap-2">
          <button class="btn btn-light btn-sm" id="sortArchivedNewest">
            <i class="bi bi-arrow-down-circle"></i> الأحدث
          </button>
          <button class="btn btn-light btn-sm" id="sortArchivedOldest">
            <i class="bi bi-arrow-up-circle"></i> الأقدم
          </button>
          <button class="btn btn-light btn-sm" id="sortArchivedReset">
            <i class="bi bi-arrow-repeat"></i> افتراضي
          </button>
          <button class="btn btn-danger btn-sm" id="btn-delete-all-archived">
            <i class="bi bi-trash3-fill"></i> حذف الكل
          </button>
        </div>
      </div>
      <div class="card-body">
        <!-- فلترة حسب تاريخ الحذف -->
        <div class="row g-2 mb-3">
          <div class="col-md-3">
            <input type="date" id="filter_arch_from_date" class="form-control" placeholder="من تاريخ الحذف">
          </div>
          <div class="col-md-3">
            <input type="date" id="filter_arch_to_date" class="form-control" placeholder="إلى تاريخ الحذف">
          </div>
          <div class="col-md-6 d-flex gap-2">
            <button class="btn btn-primary btn-sm" id="btn-filter-arch-dates"><i class="bi bi-funnel"></i> فلترة</button>
            <button class="btn btn-light btn-sm" id="btn-reset-arch-dates"><i class="bi bi-arrow-counterclockwise"></i> افتراضي</button>
          </div>
        </div>

        <div class="input-group mb-2">
          <input type="text" id="searchArchived" class="form-control"
            placeholder="ابحث برمز الخدمة أو المريض أو الطبيب">
          <button class="btn btn-primary" type="button" id="btn-search-archived"><i class="bi bi-search"></i>
            بحث</button>
        </div>
        <div class="mb-3 d-flex gap-2">
          <button class="btn btn-success btn-sm" id="showPaidArchived">مدفوعة فقط</button>
          <button class="btn btn-warning btn-sm" id="showUnpaidArchived">غير مدفوعة فقط</button>
          <button class="btn btn-light btn-sm" id="showAllArchived">الكل</button>
        </div>
        <div class="table-responsive">
          <table class="table table-bordered table-hover text-center" id="archivedTable">
            <thead class="table-light">
              <tr>
                <th>رقم</th>
                <th>رمز الخدمة</th>
                <th>المريض</th>
                <th>الهوية</th>
                <th>الطبيب</th>
                <th>المسمى</th>
                <th>تاريخ الإصدار</th>
                <th>من</th>
                <th>إلى</th>
                <th>الأيام</th>
                <th>نوع الإجازة</th>
                <th>عدد الاستعلامات</th>
                <th>تاريخ الحذف</th>
                <th>مدفوعة؟</th>
                <th>المبلغ</th>
                <th style="min-width:260px;">تحكم</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($archived)): ?>
                <tr class="no-results">
                  <td colspan="17">لا توجد إجازات في الأرشيف.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($archived as $idx => $lv): ?>
                  <tr data-id="<?= $lv['id'] ?>" data-patient="<?= $lv['patient_id'] ?>"
                      data-comp-name="<?= htmlspecialchars($lv['companion_name']) ?>"
                      data-comp-rel="<?= htmlspecialchars($lv['companion_relation']) ?>">
                    <td class="row-num"></td>
                    <td class="cell-service"><?= htmlspecialchars(strtoupper($lv['service_code'])) ?></td>
                    <td class="cell-patient"><?= htmlspecialchars($lv['patient_name']) ?></td>
                    <td class="cell-identity"><?= htmlspecialchars($lv['identity_number']) ?></td>
                    <td class="cell-doctor"><?= htmlspecialchars($lv['doctor_name']) ?></td>
                    <td><?= htmlspecialchars($lv['doctor_title']) ?></td>
                    <td><?= htmlspecialchars($lv['doctor_note']) ?></td>
                    <td class="cell-issue"><?= htmlspecialchars($lv['issue_date']) ?></td>
                    <td><?= htmlspecialchars($lv['start_date']) ?></td>
                    <td><?= htmlspecialchars($lv['end_date']) ?></td>
                    <td><?= htmlspecialchars($lv['days_count']) ?></td>
                    <td>
                      <?= $lv['is_companion']
                        ? '<span class="badge bg-warning text-dark">مرافق</span>'
                        : '<span class="badge bg-info text-dark">أساسي</span>' ?>
                    </td>
                    <td class="cell-queries-count"><?= $lv['queries_count'] ?></td>
                   <td class="cell-deleted"><?= htmlspecialchars($lv['deleted_at']) ?></td>
                    <td><?= $lv['is_paid'] ? 'نعم' : 'لا' ?></td>
                    <td><?= number_format($lv['payment_amount'], 2) ?></td>
                    <td>
                      <button class="btn btn-success btn-sm action-btn btn-restore-leave"><i
                          class="bi bi-arrow-counterclockwise"></i> استعادة</button>
                      <button class="btn btn-danger btn-sm action-btn btn-force-delete-leave"><i class="bi bi-x-circle"></i>
                        حذف نهائي</button>
                      <button class="btn btn-warning btn-sm action-btn btn-view-queries"><i class="bi bi-journal-text"></i>
                        استعلامات</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  <!-- نافذة إشعارات المدفوعات -->
  <div class="modal fade" id="paymentNotifModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">إشعارات المدفوعات</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
        </div>
        <div class="modal-body">
          <div class="d-flex justify-content-end mb-2">
            <button class="btn btn-secondary btn-sm" id="refreshNotifs"><i class="bi bi-arrow-repeat"></i> تحديث</button>
          </div>
          <ul id="notifPayments" class="list-group">
            <?php foreach ($notifications_payment as $n): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center" data-leave="<?= $n['leave_id'] ?>" data-id="<?= $n['id'] ?>">
                <span><?= htmlspecialchars($n['message']) ?></span>
                <div class="btn-group">
                  <button class="btn btn-info btn-sm btn-view-leave" data-leave="<?= $n['leave_id'] ?>">تفاصيل</button>
                  <button class="btn btn-success btn-sm btn-pay-notif" data-leave="<?= $n['leave_id'] ?>">مدفوعة</button>
                  <button class="btn btn-danger btn-sm btn-del-notif" data-id="<?= $n['id'] ?>">حذف</button>
                </div>
              </li>
            <?php endforeach; ?>
            <?php if (empty($notifications_payment)): ?>
              <li class="list-group-item">لا إشعارات</li>
            <?php endif; ?>
          </ul>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
        </div>
      </div>
  </div>

  <!-- نافذة تأكيد مبلغ الدفع -->
  <div class="modal fade" id="confirmPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">تأكيد المبلغ</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
        </div>
        <div class="modal-body">
          <input type="number" step="0.01" id="confirmPaymentAmount" class="form-control">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" id="confirmPaymentBtn">تأكيد</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
        </div>
      </div>
    </div>
  </div>

  <!-- نافذة تفاصيل الإجازة -->
  <div class="modal fade" id="leaveDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">تفاصيل الإجازة</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
        </div>
        <div class="modal-body" id="leaveDetailsContainer">
          <p class="text-center">لا توجد بيانات</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
        </div>
      </div>
    </div>
  </div>

  <!-- قسم سجل الاستعلامات -->
    <div class="collapse" id="queriesSection">
      <div class="card card-custom mt-4 mb-5">
        <div class="card-header d-flex justify-content-between align-items-center"
          style="background: var(--warning-color); color: #000; border-radius: var(--border-radius) var(--border-radius) 0 0;">
          <span class="fw-bold">سجل الاستعلامات</span>
          <div class="d-flex gap-2">
            <button class="btn btn-light btn-sm" id="sortQueriesNewest">
              <i class="bi bi-arrow-down-circle"></i> الأحدث
            </button>
            <button class="btn btn-light btn-sm" id="sortQueriesOldest">
              <i class="bi bi-arrow-up-circle"></i> الأقدم
            </button>
            <button class="btn btn-light btn-sm" id="sortQueriesReset">
              <i class="bi bi-arrow-repeat"></i> افتراضي
            </button>
            <button class="btn btn-danger btn-sm" id="deleteAllQueries">
              <i class="bi bi-trash3-fill"></i> حذف الكل
            </button>
          </div>
        </div>
        <div class="card-body">
          <!-- فلترة حسب تاريخ الاستعلام -->
          <div class="row g-2 mb-3">
            <div class="col-md-3">
              <input type="date" id="filter_q_from_date" class="form-control" placeholder="من تاريخ الاستعلام">
            </div>
            <div class="col-md-3">
              <input type="date" id="filter_q_to_date" class="form-control" placeholder="إلى تاريخ الاستعلام">
            </div>
            <div class="col-md-6 d-flex gap-2">
              <button class="btn btn-primary btn-sm" id="btn-filter-queries-dates"><i class="bi bi-funnel"></i> فلترة</button>
              <button class="btn btn-light btn-sm" id="btn-reset-queries-dates"><i class="bi bi-arrow-counterclockwise"></i> افتراضي</button>
            </div>
          </div>

          <div class="input-group mb-2">
            <input type="text" id="searchQueries" class="form-control"
              placeholder="ابحث برمز الخدمة أو المريض أو الهوية">
            <button class="btn btn-primary" type="button" id="btn-search-queries"><i class="bi bi-search"></i>
              بحث</button>
          </div>
          <div class="table-responsive">
            <table class="table table-bordered table-hover text-center" id="queriesTable">
              <thead class="table-light">
                <tr>
                  <th>رقم</th>
                  <th>رمز الخدمة</th>
                  <th>المريض</th>
                  <th>الهوية</th>
                  <th>وقت الاستعلام</th>
                  <th>تحكم</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($queries)): ?>
                  <tr class="no-results">
                    <td colspan="6">لا توجد سجلات للاستعلام.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($queries as $idx => $q): ?>
                    <tr data-id="<?= $q['qid'] ?>">
                      <td class="row-num"></td>
                      <td class="cell-service"><?= htmlspecialchars(strtoupper($q['service_code'])) ?></td>
                      <td class="cell-patient"><?= htmlspecialchars($q['patient_name']) ?></td>
                      <td class="cell-identity"><?= htmlspecialchars($q['identity_number']) ?></td>
                      <td class="cell-queried"><?= htmlspecialchars($q['queried_at']) ?></td>
                      <td>
                        <button class="btn btn-danger btn-sm action-btn btn-delete-query"><i class="bi bi-trash-fill"></i>
                          حذف</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
  </div>
  </div>

  <!-- قسم المدفوعات -->
  <div id="paymentsSection">
    <div class="card card-custom mt-4 mb-5">
      <div class="card-header d-flex justify-content-between align-items-center" style="background: var(--success-color); color:#fff; border-radius: var(--border-radius) var(--border-radius) 0 0;">
        <span class="fw-bold">إحصائيات المدفوعات</span>
        <div class="d-flex gap-2">
          <button class="btn btn-light btn-sm" id="sortPaymentsPaid"><i class="bi bi-sort-down-alt"></i> أعلى المدفوعات</button>
          <button class="btn btn-light btn-sm" id="sortPaymentsUnpaid"><i class="bi bi-sort-down-alt"></i> أعلى غير المدفوعة</button>
          <button class="btn btn-light btn-sm" id="sortPaymentsReset"><i class="bi bi-arrow-repeat"></i> افتراضي</button>
        </div>
      </div>
      <div class="card-body">
        <div class="input-group mb-2">
          <input type="text" id="searchPayments" class="form-control" placeholder="ابحث باسم المريض">
          <button class="btn btn-primary" type="button" id="btn-search-payments"><i class="bi bi-search"></i> بحث</button>
        </div>
        <div class="table-responsive">
          <table class="table table-bordered table-hover text-center" id="paymentsTable">
            <thead class="table-light">
              <tr>
                <th>رقم</th>
                <th>المريض</th>
                <th>الإجازات الكلية</th>
                <th>مدفوعة</th>
                <th>غير مدفوعة</th>
                <th>إجمالي المدفوع</th>
                <th>إجمالي غير المدفوع</th>
                <th>عرض الإجازات</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($payments as $idx => $p): ?>
              <tr data-id="<?= $p['id'] ?>">
                <td class="row-num"></td>
                <td><?= htmlspecialchars($p['name']) ?></td>
                <td><?= $p['total'] ?></td>
                <td><?= $p['paid_count'] ?></td>
                <td><?= $p['unpaid_count'] ?></td>
                <td><?= number_format($p['paid_amount'], 2) ?></td>
                <td><?= number_format($p['unpaid_amount'], 2) ?></td>
                <td><button class="btn btn-info btn-sm btn-view-patient-leaves">عرض</button></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($payments)): ?>
                <tr class="no-results"><td colspan="8">لا بيانات</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- نافذة إدارة الأطباء -->
    <div class="modal fade" id="doctorsModal" tabindex="-1">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content p-3">
          <h5>قائمة الأطباء
            <button class="btn btn-success-custom btn-sm float-end" id="btn-show-add-doctor">
              <i class="bi bi-person-plus-fill"></i> إضافة
            </button>
          </h5>
          <div class="input-group mb-2">
            <input type="text" id="searchDoctorsTable" class="form-control" placeholder="ابحث بالاسم أو المسمى">
            <button class="btn btn-primary" type="button" id="btn-search-doctors"><i class="bi bi-search"></i>
              بحث</button>
          </div>
          <div class="table-responsive">
            <table class="table table-bordered table-hover text-center" id="doctorsTable">
              <thead>
                <tr>
                  <th>رقم</th>
                  <th>الاسم</th>
                  <th>المسمى</th>
                  <th>ملاحظة</th>
                  <th>تحكم</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($doctors as $idx => $d): ?>
                  <tr data-id="<?= $d['id'] ?>">
                    <td class="row-num"></td>
                    <td><?= htmlspecialchars($d['name']) ?></td>
                    <td><?= htmlspecialchars($d['title']) ?></td>
                    <td><?= htmlspecialchars($d['note']) ?></td>
                    <td>
                      <button class="btn btn-warning btn-sm action-btn btn-edit-doctor"><i
                          class="bi bi-pencil-square"></i> تعديل</button>
                      <button class="btn btn-danger btn-sm action-btn btn-delete-doctor"><i class="bi bi-trash-fill"></i>
                        حذف</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($doctors)): ?>
                  <tr class="no-results">
                    <td colspan="5">لا يوجد أطباء حاليًا.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <form class="row g-2 mt-3 needs-validation" id="doctorForm" style="display:none;" novalidate>
            <?= csrf_input(); ?>
            <input type="hidden" id="doctor_form_id" name="doctor_id">
            <div class="col-md-5">
              <input type="text" id="doctor_form_name" name="doctor_name" class="form-control" placeholder="اسم الطبيب"
                required>
              <div class="invalid-feedback">أدخل اسم الطبيب.</div>
            </div>
            <div class="col-md-4">
              <input type="text" id="doctor_form_title" name="doctor_title" class="form-control"
                placeholder="المسمى الوظيفي" required>
              <div class="invalid-feedback">أدخل المسمى الوظيفي.</div>
            </div>
            <div class="col-md-4">
              <input type="text" id="doctor_form_note" name="doctor_note" class="form-control"
                placeholder="ملاحظة">
            </div>
            <div class="col-md-4 d-flex gap-1">
              <button type="submit" class="btn btn-success-custom w-100"><i class="bi bi-save-fill"></i> حفظ</button>
              <button type="button" class="btn btn-secondary w-100" id="btn-cancel-doctor"><i
                  class="bi bi-x-circle"></i> إلغاء</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- نافذة إدارة المرضى -->
    <div class="modal fade" id="patientsModal" tabindex="-1">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content p-3">
          <h5>قائمة المرضى
            <button class="btn btn-success-custom btn-sm float-end" id="btn-show-add-patient"><i
                class="bi bi-person-plus-fill"></i> إضافة</button>
          </h5>
          <div class="input-group mb-2">
            <input type="text" id="searchPatientsTable" class="form-control" placeholder="ابحث بالاسم أو الهوية">
            <button class="btn btn-primary" type="button" id="btn-search-patients"><i class="bi bi-search"></i>
              بحث</button>
          </div>
          <div class="table-responsive">
            <table class="table table-bordered table-hover text-center" id="patientsTable">
              <thead>
                <tr>
                  <th>رقم</th>
                  <th>الاسم</th>
                  <th>الهوية</th>
                  <th>تحكم</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($patients as $idx => $p): ?>
                  <tr data-id="<?= $p['id'] ?>">
                    <td class="row-num"></td>
                    <td><?= htmlspecialchars($p['name']) ?></td>
                    <td><?= htmlspecialchars($p['identity_number']) ?></td>
                    <td>
                      <button class="btn btn-warning btn-sm action-btn btn-edit-patient"><i
                          class="bi bi-pencil-square"></i> تعديل</button>
                      <button class="btn btn-danger btn-sm action-btn btn-delete-patient"><i class="bi bi-trash-fill"></i>
                        حذف</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($patients)): ?>
                  <tr class="no-results">
                    <td colspan="4">لا يوجد مرضى حاليًا.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <form class="row g-2 mt-3 needs-validation" id="patientForm" style="display:none;" novalidate>
            <?= csrf_input(); ?>
            <input type="hidden" id="patient_form_id" name="patient_id">
            <div class="col-md-5">
              <input type="text" id="patient_form_name" name="patient_name" class="form-control"
                placeholder="اسم المريض" required>
              <div class="invalid-feedback">أدخل اسم المريض.</div>
            </div>
            <div class="col-md-5">
              <input type="text" id="patient_form_identity" name="identity_number" class="form-control"
                placeholder="رقم الهوية" required>
              <div class="invalid-feedback">أدخل رقم الهوية.</div>
            </div>
            <div class="col-md-2 d-flex gap-1">
              <button type="submit" class="btn btn-success-custom w-100"><i class="bi bi-save-fill"></i> حفظ</button>
              <button type="button" class="btn btn-secondary w-100" id="btn-cancel-patient"><i
                  class="bi bi-x-circle"></i> إلغاء</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- نافذة تعديل إجازة -->
    <div class="modal fade" id="editLeaveModal" tabindex="-1">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content p-3">
          <h5>تعديل الإجازة</h5>
          <form id="editLeaveForm" class="row g-2 needs-validation" novalidate>
            <?= csrf_input(); ?>
            <input type="hidden" id="leave_id_edit" name="leave_id_edit">
            <div class="col-md-3">
              <label>رمز الخدمة</label>
              <input type="text" id="service_code_edit" name="service_code_edit" class="form-control" required>
              <div class="invalid-feedback">أدخل رمز الخدمة.</div>
            </div>
            <div class="col-md-3">
              <label>تاريخ الإصدار</label>
              <input type="date" id="issue_date_edit" name="issue_date_edit" class="form-control" required>
              <div class="invalid-feedback">اختر تاريخ الإصدار.</div>
            </div>
            <div class="col-md-3">
              <label>المريض</label>
              <input type="text" id="patient_edit" class="form-control" readonly>
            </div>
            <div class="col-md-3">
              <label>الطبيب</label>
              <input type="text" id="doctor_edit" class="form-control" readonly>
            </div>
            <div class="col-md-3">
              <label>بداية الإجازة</label>
              <input type="date" id="start_date_edit" name="start_date_edit" class="form-control" required>
              <div class="invalid-feedback">اختر تاريخ البداية.</div>
            </div>
            <div class="col-md-3">
              <label>نهاية الإجازة</label>
              <input type="date" id="end_date_edit" name="end_date_edit" class="form-control" required>
              <div class="invalid-feedback">اختر تاريخ النهاية.</div>
            </div>
            <div class="col-md-3">
              <label>عدد الأيام</label>
              <input type="number" name="days_count_edit" id="days_count_edit" class="form-control" readonly required>
              <div class="form-check mt-1">
                <input class="form-check-input" type="checkbox" id="days_manual_edit" name="days_manual_edit" value="1">
                <label class="form-check-label" for="days_manual_edit" style="font-size:13px">أدخل يدويًا</label>
              </div>
              <div class="invalid-feedback">حدد عدد الأيام.</div>
            </div>
            <!-- إضافة حقل مرافق في التعديل -->
            <div class="col-md-2">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="is_companion_edit" id="is_companion_edit" value="1">
                <label class="form-check-label" for="is_companion_edit">إجازة مرافق</label>
              </div>
            </div>
            <div class="col-md-3 mt-2 companion-fields-edit hidden-field">
              <label>اسم المرافق</label>
              <input type="text" name="companion_name_edit" id="companion_name_edit" class="form-control">
              <div class="invalid-feedback">أدخل اسم المرافق.</div>
            </div>
           <div class="col-md-3 mt-2 companion-fields-edit hidden-field">
             <label>صلة القرابة</label>
             <input type="text" name="companion_relation_edit" id="companion_relation_edit" class="form-control">
             <div class="invalid-feedback">أدخل صلة القرابة.</div>
           </div>

            <div class="col-md-2">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="is_paid_edit" id="is_paid_edit" value="1">
                <label class="form-check-label" for="is_paid_edit">مدفوعة</label>
              </div>
            </div>
            <div class="col-md-3">
              <label>المبلغ</label>
              <input type="number" step="0.01" name="payment_amount_edit" id="payment_amount_edit" class="form-control" value="0">
            </div>
            <div class="col-12 text-center mt-3 d-flex gap-2">
              <button type="submit" class="btn btn-success-custom w-50"><i class="bi bi-save-fill"></i> حفظ
                التعديلات</button>
              <button type="button" class="btn btn-secondary w-50" data-bs-dismiss="modal"><i
                  class="bi bi-x-circle"></i> إلغاء</button>
            </div>
          </form>
        </div>
      </div>
    </div>

  </div>

  <!-- مكتبات جافاسكربت الضرورية -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // ===== دالة عرض الإشعار (Toast-like) =====
      function showAlert(type, message) {
        const toastId = 'toast-' + Date.now();
        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
        <div id="${toastId}" class="toast align-items-center text-white bg-dark border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="3000" style="background: var(--toast-bg); color: var(--toast-text); border-radius: var(--border-radius);">
          <div class="d-flex">
            <div class="toast-body">
              ${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="إغلاق"></button>
          </div>
        </div>
      `;
        document.getElementById('alert-container').append(wrapper.firstElementChild);
        const toastEl = document.getElementById(toastId);
        const bsToast = new bootstrap.Toast(toastEl);
        bsToast.show();
      }

      // ===== إظهار/إخفاء مؤشر التحميل =====
      function showLoading() {
        document.getElementById('loadingOverlay').classList.add('show');
      }
      function hideLoading() {
        document.getElementById('loadingOverlay').classList.remove('show');
      }

      // ===== داكن/فاتح مود =====
      const darkToggle = document.getElementById('darkModeToggle');
      function setDarkMode(on) {
        if (on) {
          document.body.classList.add('dark-mode');
          darkToggle.innerHTML = '<i class="bi bi-sun-fill"></i> فاتح';
        } else {
          document.body.classList.remove('dark-mode');
          darkToggle.innerHTML = '<i class="bi bi-moon-fill"></i> داكن';
        }
        localStorage.setItem('darkMode', on ? '1' : '0');
      }
      const savedMode = localStorage.getItem('darkMode');
      setDarkMode(savedMode === '1');
      darkToggle.addEventListener('click', () => {
        setDarkMode(!document.body.classList.contains('dark-mode'));
      });

      // ===== نافذة التأكيد العامة =====
      const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
      let confirmCallback = null;
      function showConfirm(message, callback) {
        document.getElementById('confirmMessage').textContent = message;
        confirmCallback = callback;
        confirmModal.show();
      }
      document.getElementById('confirmYesBtn').addEventListener('click', () => {
        if (confirmCallback) confirmCallback();
        confirmModal.hide();
      });

      // ===== نافذة عرض تفاصيل استعلامات الإجازة =====
      const viewQueriesModal = new bootstrap.Modal(document.getElementById('viewQueriesModal'));
      let currentLeaveIdForQueries = null;
      let originalQueriesDetailRows = []; // لحفظ نسخ الصفوف الأصلية
      function showQueriesDetails(leaveId) {
        currentLeaveIdForQueries = leaveId;
        document.getElementById('queriesDetailsContainer').innerHTML = '<p class="text-center">جارٍ جلب البيانات...</p>';
        viewQueriesModal.show();
        fetch('', {
          method: 'POST',
          body: new URLSearchParams({
            action: 'fetch_queries',
            leave_id: leaveId,
            csrf_token: '<?= $_SESSION["csrf_token"] ?>'
          })
        })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            if (data.queries.length === 0) {
              document.getElementById('queriesDetailsContainer').innerHTML = '<p class="text-center">لا توجد استعلامات لهذه الإجازة.</p>';
              originalQueriesDetailRows = [];
            } else {
              let html = `
                <div class="table-responsive">
                  <table class="table table-bordered table-hover text-center" id="queriesDetailsTable">
                    <thead class="table-light">
                      <tr><th>رقم</th><th>وقت الاستعلام</th><th>تحكم</th></tr>
                    </thead>
                    <tbody>
              `;
              data.queries.forEach((q, idx) => {
                html += `
                  <tr data-qid="${q.id}">
                    <td class="row-num"></td>
                    <td class="cell-queried">${q.queried_at}</td>
                    <td>
                      <button class="btn btn-danger btn-sm btn-delete-query-detail" data-qid="${q.id}">
                        <i class="bi bi-trash-fill"></i> حذف
                      </button>
                    </td>
                  </tr>
                `;
              });
              html += `</tbody></table></div>`;
              document.getElementById('queriesDetailsContainer').innerHTML = html;

              // جمع الصفوف الأصلية
              originalQueriesDetailRows = Array.from(document.querySelectorAll('#queriesDetailsTable tbody tr'));

              // ربط أحداث حذف كامل صف
              document.querySelectorAll('.btn-delete-query-detail').forEach(btn => {
                btn.addEventListener('click', () => {
                  const qid = btn.getAttribute('data-qid');
                  showConfirm('تأكيد حذف سجل الاستعلام؟', () => {
                    showLoading();
                    fetch('', {
                      method: 'POST',
                      body: new URLSearchParams({
                        action: 'delete_query',
                        query_id: qid,
                        csrf_token: '<?= $_SESSION["csrf_token"] ?>'
                      })
                    })
                    .then(r => r.json())
                    .then(res => {
                      hideLoading();
                      if (res.success) {
                        showAlert('success', 'تم حذف سجل الاستعلام');
                        const row = document.querySelector(`tr[data-qid="${qid}"]`);
                        if (row) row.remove();
                        reIndexTable('queriesDetailsTable');
                        // حدّث عدّاد الاستعلامات في الصف الرئيسي (النشط أو الأرشيف)
                        const leaveRow = document.querySelector(`#leavesTable tr[data-id="${leaveId}"]`);
                        const archRow = document.querySelector(`#archivedTable tr[data-id="${leaveId}"]`);
                        if (leaveRow) {
                          let countCell = leaveRow.querySelector('.cell-queries-count');
                          let newCount = parseInt(countCell.textContent) - 1;
                          if (newCount < 0) newCount = 0;
                          countCell.textContent = newCount;
                        }
                        if (archRow) {
                          let countCell = archRow.querySelector('.cell-queries-count');
                          let newCount = parseInt(countCell.textContent) - 1;
                          if (newCount < 0) newCount = 0;
                          countCell.textContent = newCount;
                        }
                        const tbody = document.querySelector('#queriesDetailsTable tbody');
                        if (!tbody.querySelector('tr[data-qid]')) {
                          document.getElementById('queriesDetailsContainer').innerHTML = '<p class="text-center">لا توجد استعلامات لهذه الإجازة.</p>';
                          originalQueriesDetailRows = [];
                        }
                      }
                    });
                  });
                });
              });
            }
          } else {
            document.getElementById('queriesDetailsContainer').innerHTML = '<p class="text-center text-danger">حدث خطأ عند جلب البيانات.</p>';
          }
        });
      }

      // زر حذف كل الاستعلامات في نافذة التفاصيل
      document.getElementById('btn-delete-all-queries').onclick = () => {
        if (!currentLeaveIdForQueries) return;
        showConfirm('هل تريد حذف جميع سجلات الاستعلام لهذه الإجازة؟', () => {
          showLoading();
          fetch('', {
            method: 'POST',
            body: new URLSearchParams({
              action: 'delete_all_queries',
              csrf_token: '<?= $_SESSION["csrf_token"] ?>'
            })
          })
          .then(r => r.json())
          .then(res => {
            hideLoading();
            if (res.success) {
              showAlert('success', res.message);
              document.getElementById('queriesDetailsContainer').innerHTML = '<p class="text-center">لا توجد استعلامات لهذه الإجازة.</p>';
              const leaveRow = document.querySelector(`#leavesTable tr[data-id="${currentLeaveIdForQueries}"]`);
              const archRow = document.querySelector(`#archivedTable tr[data-id="${currentLeaveIdForQueries}"]`);
              if (leaveRow) leaveRow.querySelector('.cell-queries-count').textContent = '0';
              if (archRow) archRow.querySelector('.cell-queries-count').textContent = '0';
              originalQueriesDetailRows = [];
            }
          });
        });
      };

      // ==== 12. تفعيل التحقق من الفورمات (Bootstrap) ====
      (() => {
        'use strict';
        const forms = document.querySelectorAll('.needs-validation');
        Array.from(forms).forEach(form => {
          form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
              event.preventDefault();
              event.stopPropagation();
            }
            form.classList.add('was-validated');
          }, false);
        });
      })();

      // ==== 13. إعادة ترقيم صفوف جدول معين =====
      function reIndexTable(tableId) {
        const tbody = document.getElementById(tableId)?.querySelector('tbody');
        if (!tbody) return;
        let idx = 1;
        Array.from(tbody.querySelectorAll('tr')).forEach(row => {
          if (row.classList.contains('no-results')) return;
          const numCell = row.querySelector('.row-num');
          if (numCell) {
            numCell.textContent = idx++;
          }
        });
      }

      // ==== 14. البحث في <select> (المرضى والطبية) في نموذج الإضافة ====
      function filterSelectOptions(searchInputId, buttonId, selectId, noResultId) {
        const input = document.getElementById(searchInputId);
        const btn = document.getElementById(buttonId);
        const sel = document.getElementById(selectId);
        const noResElem = document.getElementById(noResultId);
        btn.addEventListener('click', () => {
          const text = input.value.trim().toLowerCase();
          let anyMatch = false;
          Array.from(sel.options).forEach(opt => {
            const valName = opt.getAttribute('data-name') || '';
            const valExtra = (opt.getAttribute('data-identity') || '') + ' ' +
                             (opt.getAttribute('data-title') || '') + ' ' +
                             (opt.getAttribute('data-note') || '');
            if (opt.value === 'manual') {
              opt.style.display = '';
              return;
            }
            if (valName.includes(text) || valExtra.includes(text)) {
              opt.style.display = '';
              anyMatch = true;
            } else {
              opt.style.display = 'none';
            }
          });
          if (!anyMatch) {
            noResElem.style.display = '';
            showAlert('warning', 'لا يوجد نتائج مطابقة');
          } else {
            noResElem.style.display = 'none';
            showAlert('success', 'تم العثور على نتائج');
          }
          const curVal = sel.value;
          const curOption = sel.querySelector(`option[value="${curVal}"]`);
          if (curOption && curOption.style.display === 'none') {
            sel.value = '';
          }
        });
      }
      filterSelectOptions('searchPatient', 'btn-search-patient', 'patient_select', 'noPatientResult');
      filterSelectOptions('searchDoctor', 'btn-search-doctor', 'doctor_select', 'noDoctorResult');

      // ==== 15. إعادة إظهار كل الخيارات في <select> بعد تصفية ====
      function resetSelectOptions(selectId, noResultId) {
        const sel = document.getElementById(selectId);
        const noResElem = document.getElementById(noResultId);
        Array.from(sel.options).forEach(opt => {
          opt.style.display = '';
        });
        noResElem.style.display = 'none';
        sel.value = '';
      }

      // ==== 16. إدارة الأطباء ====
      const btnShowAddDoctor = document.getElementById('btn-show-add-doctor');
      const btnCancelDoctor = document.getElementById('btn-cancel-doctor');
      const doctorForm = document.getElementById('doctorForm');
      const doctorsBody = document.querySelector('#doctorsTable tbody');
      let originalDoctorsRows = Array.from(doctorsBody.querySelectorAll('tr'));

      btnShowAddDoctor.addEventListener('click', () => {
        doctorForm.reset();
        document.getElementById('doctor_form_id').value = '';
        doctorForm.style.display = 'flex';
        doctorForm.classList.remove('was-validated');
      });

      btnCancelDoctor.addEventListener('click', () => {
        doctorForm.style.display = 'none';
        doctorForm.classList.remove('was-validated');
      });

      doctorForm.addEventListener('submit', e => {
        e.preventDefault();
        if (!doctorForm.checkValidity()) return;
        const formData = new FormData(doctorForm);
        const isEdit = document.getElementById('doctor_form_id').value !== '';
        formData.append('action', isEdit ? 'edit_doctor' : 'add_doctor');
        showLoading();
        fetch('', { method: 'POST', body: formData })
          .then(r => r.json()).then(data => {
            hideLoading();
            if (data.success) {
              showAlert('success', isEdit ? 'تم تحديث الطبيب' : 'تم إضافة الطبيب');
              const d = data.doctor;
              if (isEdit) {
                const row = document.querySelector(`#doctorsTable tr[data-id="${d.id}"]`);
                if (row) {
                  row.children[1].textContent = d.name;
                  row.children[2].textContent = d.title;
                  row.children[3].textContent = d.note ?? '';
                }
              } else {
                const newRow = document.createElement('tr');
                newRow.setAttribute('data-id', d.id);
                newRow.innerHTML = `
                  <td class="row-num"></td>
                  <td>${d.name}</td>
                  <td>${d.title}</td>
                  <td>${d.note ?? ''}</td>
                  <td>
                    <button class="btn btn-warning btn-sm action-btn btn-edit-doctor"><i class="bi bi-pencil-square"></i> تعديل</button>
                    <button class="btn btn-danger btn-sm action-btn btn-delete-doctor"><i class="bi bi-trash-fill"></i> حذف</button>
                  </td>
                `;
                doctorsBody.prepend(newRow);
                attachDoctorRowEvents(newRow);
              }
              doctorForm.style.display = 'none';
              reIndexTable('doctorsTable');
              originalDoctorsRows = Array.from(doctorsBody.querySelectorAll('tr'));
            } else {
              showAlert('danger', data.message);
            }
          });
      });

      function attachDoctorRowEvents(row) {
        row.querySelector('.btn-edit-doctor').addEventListener('click', () => {
          const id = row.getAttribute('data-id');
          document.getElementById('doctor_form_id').value = id;
          document.getElementById('doctor_form_name').value = row.children[1].textContent.trim();
          document.getElementById('doctor_form_title').value = row.children[2].textContent.trim();
          document.getElementById('doctor_form_note').value = row.children[3].textContent.trim();
          doctorForm.style.display = 'flex';
          doctorForm.classList.remove('was-validated');
        });
        row.querySelector('.btn-delete-doctor').addEventListener('click', () => {
          const id = row.getAttribute('data-id');
          showConfirm('هل أنت متأكد من حذف الطبيب؟', () => {
            const fd = new FormData();
            fd.append('action', 'delete_doctor');
            fd.append('doctor_id', id);
            fd.append('csrf_token', '<?= $_SESSION["csrf_token"] ?>');
            showLoading();
            fetch('', { method: 'POST', body: fd })
              .then(r => r.json()).then(data => {
                hideLoading();
                if (data.success) {
                  showAlert('success', 'تم حذف الطبيب');
                  row.remove();
                  reIndexTable('doctorsTable');
                  originalDoctorsRows = Array.from(doctorsBody.querySelectorAll('tr'));
                }
              });
          });
        });
      }
      document.querySelectorAll('#doctorsTable tbody tr').forEach(attachDoctorRowEvents);
      reIndexTable('doctorsTable');

      // ==== 17. فرز الأطباء الأبجدي (الأحدث/الأقدم/إعادة الضبط) ====
      document.getElementById('sortDoctorsNewest')?.addEventListener('click', () => {
        const tbody = document.getElementById('doctorsTable').querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr:not(.no-results)'));
        rows.sort((a, b) => b.children[1].textContent.trim().localeCompare(a.children[1].textContent.trim()));
        rows.forEach(r => tbody.appendChild(r));
        reIndexTable('doctorsTable');
        showAlert('success', 'تم الفرز: الأطباء أبجديًا (الأحدث)');
      });
      document.getElementById('sortDoctorsOldest')?.addEventListener('click', () => {
        const tbody = document.getElementById('doctorsTable').querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr:not(.no-results)'));
        rows.sort((a, b) => a.children[1].textContent.trim().localeCompare(b.children[1].textContent.trim()));
        rows.forEach(r => tbody.appendChild(r));
        reIndexTable('doctorsTable');
        showAlert('success', 'تم الفرز: الأطباء أبجديًا (الأقدم)');
      });
      document.getElementById('sortDoctorsReset')?.addEventListener('click', () => {
        const tbody = document.getElementById('doctorsTable').querySelector('tbody');
        tbody.innerHTML = '';
        originalDoctorsRows.forEach(r => tbody.appendChild(r));
        reIndexTable('doctorsTable');
        showAlert('success', 'تم إعادة الترتيب الافتراضي للأطباء');
      });

      // ==== 18. البحث في جدول الأطباء ====
      function filterTable(tableId, inputId, buttonId, columns) {
        const input = document.getElementById(inputId);
        const button = document.getElementById(buttonId);
        const table = document.getElementById(tableId);
        const tbody = table.querySelector('tbody');
        const noResultsClass = 'no-results';
        button.addEventListener('click', () => {
          const filter = input.value.toLowerCase().trim();
          let anyVisible = false;
          const existingNoRes = tbody.querySelector(`.${noResultsClass}`);
          if (existingNoRes) existingNoRes.remove();
          Array.from(tbody.querySelectorAll('tr')).forEach(row => {
            if (row.classList.contains(noResultsClass)) return;
            const text = columns.map(idx => row.children[idx].textContent.toLowerCase()).join(' ');
            const match = text.includes(filter);
            row.style.display = match ? '' : 'none';
            if (match) anyVisible = true;
          });
          if (!anyVisible) {
            const colCount = table.querySelectorAll('thead th').length;
            const noRow = document.createElement('tr');
            noRow.classList.add(noResultsClass);
            noRow.innerHTML = `<td colspan="${colCount}" class="no-results">لم يتم العثور على نتائج مطابقة.</td>`;
            tbody.append(noRow);
            showAlert('warning', 'لا توجد نتائج مطابقة');
          } else {
            showAlert('success', 'تم العثور على نتائج');
          }
          reIndexTable(tableId);
        });
      }
      filterTable('doctorsTable', 'searchDoctorsTable', 'btn-search-doctors', [1, 2, 3]);

      // ==== 19. إدارة المرضى ====
      const btnShowAddPatient = document.getElementById('btn-show-add-patient');
      const btnCancelPatient = document.getElementById('btn-cancel-patient');
      const patientForm = document.getElementById('patientForm');
      const patientsBody = document.querySelector('#patientsTable tbody');
      let originalPatientsRows = Array.from(patientsBody.querySelectorAll('tr'));

      btnShowAddPatient.addEventListener('click', () => {
        patientForm.reset();
        document.getElementById('patient_form_id').value = '';
        patientForm.style.display = 'flex';
        patientForm.classList.remove('was-validated');
      });

      btnCancelPatient.addEventListener('click', () => {
        patientForm.style.display = 'none';
        patientForm.classList.remove('was-validated');
      });

      patientForm.addEventListener('submit', e => {
        e.preventDefault();
        if (!patientForm.checkValidity()) return;
        const formData = new FormData(patientForm);
        const isEdit = document.getElementById('patient_form_id').value !== '';
        formData.append('action', isEdit ? 'edit_patient' : 'add_patient');
        showLoading();
        fetch('', { method: 'POST', body: formData })
          .then(r => r.json()).then(data => {
            hideLoading();
            if (data.success) {
              showAlert('success', isEdit ? 'تم تحديث المريض' : 'تم إضافة المريض');
              const p = data.patient;
              if (isEdit) {
                const row = document.querySelector(`#patientsTable tr[data-id="${p.id}"]`);
                if (row) {
                  row.children[1].textContent = p.name;
                  row.children[2].textContent = p.identity_number;
                }
              } else {
                const newRow = document.createElement('tr');
                newRow.setAttribute('data-id', p.id);
                newRow.innerHTML = `
                  <td class="row-num"></td>
                  <td>${p.name}</td>
                  <td>${p.identity_number}</td>
                  <td>
                    <button class="btn btn-warning btn-sm action-btn btn-edit-patient"><i class="bi bi-pencil-square"></i> تعديل</button>
                    <button class="btn btn-danger btn-sm action-btn btn-delete-patient"><i class="bi bi-trash-fill"></i> حذف</button>
                  </td>
                `;
                patientsBody.prepend(newRow);
                attachPatientRowEvents(newRow);
              }
              patientForm.style.display = 'none';
              reIndexTable('patientsTable');
              originalPatientsRows = Array.from(patientsBody.querySelectorAll('tr'));
            } else {
              showAlert('danger', data.message);
            }
          });
      });

      function attachPatientRowEvents(row) {
        row.querySelector('.btn-edit-patient').addEventListener('click', () => {
          const id = row.getAttribute('data-id');
          document.getElementById('patient_form_id').value = id;
          document.getElementById('patient_form_name').value = row.children[1].textContent.trim();
          document.getElementById('patient_form_identity').value = row.children[2].textContent.trim();
          patientForm.style.display = 'flex';
          patientForm.classList.remove('was-validated');
        });
        row.querySelector('.btn-delete-patient').addEventListener('click', () => {
          const id = row.getAttribute('data-id');
          showConfirm('هل أنت متأكد من حذف المريض؟', () => {
            const fd = new FormData();
            fd.append('action', 'delete_patient');
            fd.append('patient_id', id);
            fd.append('csrf_token', '<?= $_SESSION["csrf_token"] ?>');
            showLoading();
            fetch('', { method: 'POST', body: fd })
              .then(r => r.json()).then(data => {
                hideLoading();
                if (data.success) {
                  showAlert('success', 'تم حذف المريض');
                  row.remove();
                  reIndexTable('patientsTable');
                  originalPatientsRows = Array.from(patientsBody.querySelectorAll('tr'));
                }
              });
          });
        });
      }
      document.querySelectorAll('#patientsTable tbody tr').forEach(attachPatientRowEvents);
      reIndexTable('patientsTable');

      // ==== 20. فرز المرضى الأبجدي (الأحدث/الأقدم/إعادة الضبط) ====
      document.getElementById('sortPatientsNewest')?.addEventListener('click', () => {
        const tbody = document.getElementById('patientsTable').querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr:not(.no-results)'));
        rows.sort((a, b) => b.children[1].textContent.trim().localeCompare(a.children[1].textContent.trim()));
        rows.forEach(r => tbody.appendChild(r));
        reIndexTable('patientsTable');
        showAlert('success', 'تم الفرز: المرضى أبجديًا (الأحدث)');
      });
      document.getElementById('sortPatientsOldest')?.addEventListener('click', () => {
        const tbody = document.getElementById('patientsTable').querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr:not(.no-results)'));
        rows.sort((a, b) => a.children[1].textContent.trim().localeCompare(b.children[1].textContent.trim()));
        rows.forEach(r => tbody.appendChild(r));
        reIndexTable('patientsTable');
        showAlert('success', 'تم الفرز: المرضى أبجديًا (الأقدم)');
      });
      document.getElementById('sortPatientsReset')?.addEventListener('click', () => {
        const tbody = document.getElementById('patientsTable').querySelector('tbody');
        tbody.innerHTML = '';
        originalPatientsRows.forEach(r => tbody.appendChild(r));
        reIndexTable('patientsTable');
        showAlert('success', 'تم إعادة الترتيب الافتراضي للمرضى');
      });

      // ==== 21. البحث في جدول المرضى ====
      filterTable('patientsTable', 'searchPatientsTable', 'btn-search-patients', [1, 2]);

      // ==== 22. إضافة إجازة جديدة ====
      const leaveForm = document.getElementById('leaveForm');
      leaveForm.addEventListener('submit', e => {
        e.preventDefault();
        if (!leaveForm.checkValidity()) return;
        const formData = new FormData(leaveForm);
        formData.append('action', 'add_leave');
        showLoading();
        fetch('', { method: 'POST', body: formData })
          .then(r => r.json()).then(data => {
            hideLoading();
            if (data.success) {
              showAlert('success', data.message);
              const lv = data.leave;
              const tbody = document.querySelector('#leavesTable tbody');
              const noRow = tbody.querySelector('.no-results');
              if (noRow) noRow.remove();
              const newRow = document.createElement('tr');
              newRow.setAttribute('data-id', lv.id);
              newRow.setAttribute('data-patient', lv.patient_id);
              newRow.setAttribute('data-comp-name', lv.companion_name);
              newRow.setAttribute('data-comp-rel', lv.companion_relation);
              newRow.innerHTML = `
                <td class="row-num"></td>
                <td class="cell-service">${lv.service_code}</td>
                <td class="cell-patient">${lv.patient_name}</td>
                <td class="cell-identity">${lv.identity_number}</td>
                <td class="cell-doctor">${lv.doctor_name}</td>
                <td>${lv.doctor_title}</td>
                <td>${lv.doctor_note ?? ''}</td>
                <td class="cell-issue">${lv.issue_date}</td>
                <td>${lv.start_date}</td>
                <td>${lv.end_date}</td>
                <td>${lv.days_count}</td>
                <td>${lv.is_companion ? '<span class="badge bg-warning text-dark">مرافق</span>' : '<span class="badge bg-info text-dark">أساسي</span>'}</td>
                <td class="cell-queries-count">${lv.queries_count}</td>
                <td class="cell-created">${lv.created_at}</td>
                <td>${lv.is_paid ? 'نعم' : 'لا'}</td>
                <td>${parseFloat(lv.payment_amount).toFixed(2)}</td>
                <td>
                  <button class="btn btn-info btn-sm action-btn btn-edit-leave"><i class="bi bi-pencil-square"></i> تعديل</button>
                  <button class="btn btn-danger btn-sm action-btn btn-delete-leave"><i class="bi bi-trash-fill"></i> أرشفة</button>
                  <button class="btn btn-warning btn-sm action-btn btn-view-queries"><i class="bi bi-journal-text"></i> استعلامات</button>
                </td>
              `;
              tbody.prepend(newRow);
              attachLeaveRowEvents(newRow);
              reIndexTable('leavesTable');

              leaveForm.reset();
              document.getElementById('patient_manual_name').classList.add('hidden-field');
              document.getElementById('patient_manual_id').classList.add('hidden-field');
              document.getElementById('doctor_manual_name').classList.add('hidden-field');
              document.getElementById('doctor_manual_title').classList.add('hidden-field');
              document.getElementById('doctor_saved_title').classList.add('hidden-field');
              document.querySelectorAll('.companion-fields').forEach(el => el.classList.add('hidden-field'));
            } else {
              showAlert('danger', data.message);
            }
          });
      });

      // ==== 23. إظهار/إخفاء الحقول الذكية للمرضى والأطباء ====
      const patientSelect = document.getElementById('patient_select');
      const pManualName = document.getElementById('patient_manual_name');
      const pManualId = document.getElementById('patient_manual_id');
      patientSelect.addEventListener('change', () => {
        const isMan = patientSelect.value === 'manual';
        [pManualName, pManualId].forEach(el => {
          const inp = el;
          if (isMan) {
            el.classList.remove('hidden-field');
            inp.required = true;
          } else {
            el.classList.add('hidden-field');
            inp.required = false;
            inp.value = '';
          }
        });
      });

      const doctorSelect = document.getElementById('doctor_select');
      const dManualName = document.getElementById('doctor_manual_name');
      const dManualTitle = document.getElementById('doctor_manual_title');
      const dManualNote = document.getElementById('doctor_manual_note');
      const dSavedTitle = document.getElementById('doctor_saved_title');
      doctorSelect.addEventListener('change', () => {
        const val = doctorSelect.value;
        const isMan = val === 'manual';
        if (isMan) {
          dManualName.classList.remove('hidden-field');
          dManualTitle.classList.remove('hidden-field');
          dManualNote.classList.remove('hidden-field');
          dSavedTitle.classList.add('hidden-field');
          dManualName.required = true;
          dManualTitle.required = true;
        } else {
          dManualName.classList.add('hidden-field');
          dManualTitle.classList.add('hidden-field');
          dManualNote.classList.add('hidden-field');
          dManualName.required = false;
          dManualTitle.required = false;
          dManualName.value = '';
          dManualTitle.value = '';
          dManualNote.value = '';
          if (val) {
            const selected = doctorSelect.options[doctorSelect.selectedIndex];
            dSavedTitle.value = selected.getAttribute('data-title');
            dSavedTitle.classList.remove('hidden-field');
          } else {
            dSavedTitle.classList.add('hidden-field');
            dSavedTitle.value = '';
          }
        }
      });

      // ==== 24. حساب عدد الأيام تلقائيًا ====
      const startDateInput = document.getElementById('start_date');
      const endDateInput = document.getElementById('end_date');
      const daysCountInput = document.getElementById('days_count');
      const daysManualChk = document.getElementById('days_manual');
      function updateDays() {
        if (daysManualChk.checked) return;
        const start = new Date(startDateInput.value);
        const end = new Date(endDateInput.value);
        if (start && end && end >= start) {
          const diff = Math.floor((end - start) / (1000 * 60 * 60 * 24)) + 1;
          daysCountInput.value = diff > 0 ? diff : 1;
        } else {
          daysCountInput.value = '';
        }
      }
      startDateInput.addEventListener('change', updateDays);
      endDateInput.addEventListener('change', updateDays);
      daysManualChk.addEventListener('change', () => {
        daysCountInput.readOnly = !daysManualChk.checked;
        if (!daysManualChk.checked) updateDays();
      });
      updateDays();

      // ==== 25. إضافة/إخفاء حقول المرافق في إضافة ====
      const isCompChk = document.getElementById('is_companion');
      const compFields = document.querySelectorAll('.companion-fields');
      isCompChk.addEventListener('change', () => {
        const isC = isCompChk.checked;
        compFields.forEach(el => {
          const inp = el.querySelector('input');
          if (isC) {
            el.classList.remove('hidden-field');
            inp.required = true;
          } else {
            el.classList.add('hidden-field');
            inp.required = false;
            inp.value = '';
          }
        });
      });

      // ==== 26. تعديل الإجازة (رابط أزرار في كل صف) ====
      function attachLeaveRowEvents(row) {
        // تعديل
        row.querySelector('.btn-edit-leave').addEventListener('click', () => {
          const id = row.getAttribute('data-id');
          showEditLeave(id);
        });
        // أرشفة
        row.querySelector('.btn-delete-leave').addEventListener('click', () => {
          const id = row.getAttribute('data-id');
          showConfirm('تأكيد نقل الإجازة إلى الأرشيف؟', () => {
            const fd = new FormData();
            fd.append('action', 'delete_leave');
            fd.append('leave_id', id);
            fd.append('csrf_token', '<?= $_SESSION["csrf_token"] ?>');
            showLoading();
            fetch('', { method: 'POST', body: fd })
              .then(r => r.json())
              .then(data => {
                hideLoading();
                if (data.success) {
                  showAlert('warning', data.message);
                  row.remove();
                  reIndexTable('leavesTable');
                  // حذف صفوف الاستعلامات الخاصة بهذه الإجازة
                  document.querySelectorAll(`#queriesTable tr[data-id]`).forEach(qrow => {
                    if (qrow.querySelector('.cell-service').textContent.trim() === row.querySelector('.cell-service').textContent.trim()) {
                      qrow.remove();
                    }
                  });
                  reIndexTable('queriesTable');
                }
              });
          });
        });
        // عرض استعلامات (نشطة أو مؤرشفة)
        row.querySelector('.btn-view-queries').addEventListener('click', () => {
          const id = row.getAttribute('data-id');
          showQueriesDetails(id);
        });
      }
      document.querySelectorAll('#leavesTable tbody tr[data-id]').forEach(attachLeaveRowEvents);
      reIndexTable('leavesTable');

      // ==== 27. استرجاع إجازة من الأرشيف ====
      document.querySelectorAll('.btn-restore-leave').forEach(btn => {
        btn.addEventListener('click', () => {
          const row = btn.closest('tr');
          const id = row.getAttribute('data-id');
          showConfirm('هل تريد استرجاع الإجازة من الأرشيف؟', () => {
            const fd = new FormData();
            fd.append('action', 'restore_leave');
            fd.append('leave_id', id);
            fd.append('csrf_token', '<?= $_SESSION["csrf_token"] ?>');
            showLoading();
            fetch('', { method: 'POST', body: fd })
              .then(r => r.json())
              .then(data => {
                hideLoading();
                if (data.success) {
                  showAlert('success', data.message);
                  row.remove();
                  reIndexTable('archivedTable');
                }
              });
          });
        });
      });

      // ==== 28. حذف نهائي إجازة من الأرشيف ====
      document.querySelectorAll('.btn-force-delete-leave').forEach(btn => {
        btn.addEventListener('click', () => {
          const row = btn.closest('tr');
          const id = row.getAttribute('data-id');
          showConfirm('هل تريد الحذف النهائي للإجازة؟ لا يمكن التراجع عن هذا الإجراء.', () => {
            const fd = new FormData();
            fd.append('action', 'force_delete_leave');
            fd.append('leave_id', id);
            fd.append('csrf_token', '<?= $_SESSION["csrf_token"] ?>');
            showLoading();
            fetch('', { method: 'POST', body: fd })
              .then(r => r.json())
              .then(data => {
                hideLoading();
                if (data.success) {
                  showAlert('danger', data.message);
                  row.remove();
                  reIndexTable('archivedTable');
                }
              });
          });
        });
      });

      // ==== 29. حذف كل الأرشيف ====
      document.getElementById('btn-delete-all-archived')?.addEventListener('click', () => {
        showConfirm('هل أنت متأكد من حذف جميع الإجازات المؤرشفة نهائيًا؟', () => {
          showLoading();
          fetch('', {
            method: 'POST',
            body: new URLSearchParams({
              action: 'force_delete_all_archived',
              csrf_token: '<?= $_SESSION["csrf_token"] ?>'
            })
          })
          .then(r => r.json())
          .then(res => {
            hideLoading();
            if (res.success) {
              showAlert('danger', res.message);
              document.querySelectorAll('#archivedTable tbody tr').forEach(r => r.remove());
              const colCount = document.querySelectorAll('#archivedTable thead th').length;
              document.querySelector('#archivedTable tbody').innerHTML = `<tr class="no-results"><td colspan="${colCount}">لا توجد إجازات في الأرشيف.</td></tr>`;
              reIndexTable('archivedTable');
            }
          });
        });
      });
      reIndexTable('archivedTable');

      // ==== 30. فتح نافذة تعديل الإجازة ====
      const editLeaveModal = new bootstrap.Modal(document.getElementById('editLeaveModal'));
      function showEditLeave(id) {
        const row = document.querySelector(`#leavesTable tr[data-id="${id}"]`);
        if (!row) return;
        const cells = row.children;
        document.getElementById('leave_id_edit').value = id;
        document.getElementById('service_code_edit').value = cells[1].textContent.trim();
        document.getElementById('issue_date_edit').value = cells[6].textContent.trim();
        document.getElementById('patient_edit').value = cells[2].textContent.trim();
        document.getElementById('doctor_edit').value = cells[4].textContent.trim();
        document.getElementById('start_date_edit').value = cells[7].textContent.trim();
        document.getElementById('end_date_edit').value = cells[8].textContent.trim();
       document.getElementById('days_count_edit').value = cells[9].textContent.trim();

        document.getElementById('is_paid_edit').checked = cells[13].textContent.trim() === 'نعم';
        document.getElementById('payment_amount_edit').value = cells[14].textContent.trim();

        // تعبئة بيانات المرافق في التعديل
        const isCBox = document.getElementById('is_companion_edit');
        const compNameInput = document.getElementById('companion_name_edit');
        const compRelInput = document.getElementById('companion_relation_edit');
        const compName = row.getAttribute('data-comp-name');
        const compRel = row.getAttribute('data-comp-rel');
        if (cells[10].querySelector('.badge.bg-warning')) {
          isCBox.checked = true;
          document.querySelectorAll('.companion-fields-edit').forEach(el => el.classList.remove('hidden-field'));
          compNameInput.value = compName;
          compRelInput.value = compRel;
          compNameInput.required = true;
          compRelInput.required = true;
        } else {
          isCBox.checked = false;
          document.querySelectorAll('.companion-fields-edit').forEach(el => el.classList.add('hidden-field'));
          compNameInput.required = false;
          compRelInput.required = false;
          compNameInput.value = '';
          compRelInput.value = '';
        }

        // ربط تغيير المرافق في التعديل لإظهار/إخفاء الحقول
        isCBox.onchange = () => {
          const checked = isCBox.checked;
          document.querySelectorAll('.companion-fields-edit').forEach(el => {
            const inp = el.querySelector('input');
            if (checked) {
              el.classList.remove('hidden-field');
              inp.required = true;
            } else {
              el.classList.add('hidden-field');
              inp.required = false;
              inp.value = '';
            }
          });
        };

        // حساب الأيام في نافذة التعديل
        const startE = document.getElementById('start_date_edit');
        const endE = document.getElementById('end_date_edit');
        const daysE = document.getElementById('days_count_edit');
        const manualChkE = document.getElementById('days_manual_edit');
        function updateDaysEdit() {
          if (manualChkE.checked) return;
          const s = new Date(startE.value);
          const e = new Date(endE.value);
          if (s && e && e >= s) {
            const diff = Math.floor((e - s) / (1000 * 60 * 60 * 24)) + 1;
            daysE.value = diff > 0 ? diff : 1;
          } else {
            daysE.value = '';
          }
        }
        startE.onchange = updateDaysEdit;
        endE.onchange = updateDaysEdit;
        manualChkE.onchange = () => {
          daysE.readOnly = !manualChkE.checked;
          if (!manualChkE.checked) updateDaysEdit();
        };
        updateDaysEdit();

        editLeaveModal.show();
      }

      // ==== 31. حفظ التعديلات على الإجازة ====
      const editLeaveForm = document.getElementById('editLeaveForm');
      editLeaveForm.addEventListener('submit', e => {
        e.preventDefault();
        if (!editLeaveForm.checkValidity()) return;
        const formData = new FormData(editLeaveForm);
        formData.append('action', 'edit_leave');
        showLoading();
        fetch('', { method: 'POST', body: formData })
          .then(r => r.json()).then(data => {
            hideLoading();
            if (data.success) {
              showAlert('success', data.message);
              const lv = data.leave;
              const row = document.querySelector(`#leavesTable tr[data-id="${lv.id}"]`);
              if (row) {
                row.children[1].textContent = lv.service_code;
                row.children[6].textContent = lv.issue_date;
                row.children[7].textContent = lv.start_date;
                row.children[8].textContent = lv.end_date;
                row.children[9].textContent = lv.days_count;
                if (lv.is_companion) {
                  row.children[10].innerHTML = '<span class="badge bg-warning text-dark">مرافق</span>';
                  row.setAttribute('data-comp-name', lv.companion_name);
                  row.setAttribute('data-comp-rel', lv.companion_relation);
                } else {
                  row.children[10].innerHTML = '<span class="badge bg-info text-dark">أساسي</span>';
                  row.setAttribute('data-comp-name', '');
                  row.setAttribute('data-comp-rel', '');
                }
                row.children[13].textContent = lv.is_paid ? 'نعم' : 'لا';
                row.children[14].textContent = parseFloat(lv.payment_amount).toFixed(2);
              }
              editLeaveModal.hide();
              reIndexTable('leavesTable');
            } else {
              showAlert('danger', data.message);
            }
          });
      });

      // ==== 32. حذف سجل استعلام واحد (في قائمة السجل) ====
      document.querySelectorAll('.btn-delete-query').forEach(btn => {
        btn.addEventListener('click', () => {
          const row = btn.closest('tr');
          const id = row.getAttribute('data-id');
          showConfirm('تأكيد حذف سجل الاستعلام؟', () => {
            showLoading();
            const fd = new FormData();
            fd.append('action', 'delete_query');
            fd.append('query_id', id);
            fd.append('csrf_token', '<?= $_SESSION["csrf_token"] ?>');
            fetch('', { method: 'POST', body: fd })
              .then(r => r.json())
              .then(data => {
                hideLoading();
                if (data.success) {
                  showAlert('success', 'تم حذف سجل الاستعلام');
                  row.remove();
                  reIndexTable('queriesTable');
                }
              });
          });
        });
      });
      reIndexTable('queriesTable');

      // ==== 33. حذف جميع سجلات الاستعلام في قسم السجل ====
      document.getElementById('deleteAllQueries')?.addEventListener('click', () => {
        showConfirm('هل أنت متأكد من حذف جميع سجلات الاستعلام؟', () => {
          showLoading();
          const fd = new FormData();
          fd.append('action', 'delete_all_queries');
          fd.append('csrf_token', '<?= $_SESSION["csrf_token"] ?>');
          fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
              hideLoading();
              if (data.success) {
                showAlert('success', data.message);
                document.querySelectorAll('#queriesTable tbody tr[data-id]').forEach(r => r.remove());
                const tbodyQ = document.querySelector('#queriesTable tbody');
                const colCountQ = document.querySelectorAll('#queriesTable thead th').length;
                tbodyQ.innerHTML = `<tr class="no-results"><td colspan="${colCountQ}">لا توجد سجلات للاستعلام.</td></tr>`;
                reIndexTable('queriesTable');
              }
            });
        });
      });

      // ==== 34. عرض استعلامات الإجازات المؤرشفة ====
      document.querySelectorAll('#archivedTable .btn-view-queries').forEach(btn => {
        btn.addEventListener('click', () => {
          const row = btn.closest('tr');
          const id = row.getAttribute('data-id');
          showQueriesDetails(id);
        });
      });

      // ==== 35. jsPDF لتصدير PDF ==== 
      document.getElementById('exportPDF')?.addEventListener('click', () => {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
        doc.text('إجازات مرضية نشطة', 40, 30);
        doc.autoTable({
          startY: 50,
          html: '#leavesTable',
          styles: { font: 'helvetica', fontSize: 10, halign: 'center' },
          headStyles: { fillColor: [2, 136, 209] }
        });
        doc.save('sick_leaves_active.pdf');
      });

      // ==== 36. تصدير Excel (CSV بسيط) ==== 
      document.getElementById('exportExcel')?.addEventListener('click', () => {
        function downloadCSV(csv, filename) {
          const csvFile = new Blob([csv], { type: 'text/csv' });
          const tempLink = document.createElement('a');
          tempLink.download = filename;
          tempLink.href = window.URL.createObjectURL(csvFile);
          tempLink.style.display = 'none';
          document.body.appendChild(tempLink);
          tempLink.click();
          document.body.removeChild(tempLink);
        }
        const rows = Array.from(document.querySelectorAll('#leavesTable tr'));
        const csv = rows.map(row => {
          const cols = Array.from(row.querySelectorAll('th, td')).map(cell => `"${cell.textContent.replace(/"/g, '""')}"`);
          return cols.join(',');
        }).join('\n');
        downloadCSV(csv, 'sick_leaves_active.csv');
      });

      // ==== 37. طباعة الجدول ==== 
      document.getElementById('printTable')?.addEventListener('click', () => {
        const printWindow = window.open('', '', 'height=700,width=900');
        printWindow.document.write('<html dir="rtl"><head><title>طباعة الإجازات</title>');
        printWindow.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">');
        printWindow.document.write('</head><body>');
        printWindow.document.write('<h3 class="text-center mt-3">جميع الإجازات المرضية النشطة</h3>');
        printWindow.document.write(document.getElementById('leavesTable').outerHTML);
        printWindow.document.write('</body></html>');
        printWindow.document.close();
        printWindow.focus();
        setTimeout(() => { printWindow.print(); printWindow.close(); }, 500);
      });

      // ==== 38. البحث في الجداول بواسطة الأزرار ==== 
      filterTable('leavesTable', 'searchLeaves', 'btn-search-leaves', [1, 2, 4, 6]);
      filterTable('archivedTable', 'searchArchived', 'btn-search-archived', [1, 2, 4, 6]);
      filterTable('queriesTable', 'searchQueries', 'btn-search-queries', [1, 2, 4]);
      filterTable('paymentsTable', 'searchPayments', 'btn-search-payments', [1]);

      // ==== 39. الفرز حسب الأحدث/الأقدم/إعادة الترتيب الإفتراضي ==== 
      // نحتفظ بالصفوف الأصلية لكل جدول
      const originalLeavesRows = Array.from(document.querySelectorAll('#leavesTable tbody tr'));
      const originalArchivedRows = Array.from(document.querySelectorAll('#archivedTable tbody tr'));
      const originalQueriesRows = Array.from(document.querySelectorAll('#queriesTable tbody tr'));

      document.getElementById('sortLeavesNewest')?.addEventListener('click', () => {
        sortTable('leavesTable', 12, false);
        showAlert('success', 'تم الفرز: الإجازات (الأحدث أولاً)');
      });
      document.getElementById('sortLeavesOldest')?.addEventListener('click', () => {
        sortTable('leavesTable', 12, true);
        showAlert('success', 'تم الفرز: الإجازات (الأقدم أولاً)');
      });
      document.getElementById('sortLeavesReset')?.addEventListener('click', () => {
        const tbody = document.getElementById('leavesTable').querySelector('tbody');
        tbody.innerHTML = '';
        originalLeavesRows.forEach(r => tbody.appendChild(r));
        reIndexTable('leavesTable');
        showAlert('success', 'تم إعادة الترتيب الافتراضي للإجازات');
      });

      document.getElementById('sortArchivedNewest')?.addEventListener('click', () => {
        sortTable('archivedTable', 12, false);
        showAlert('success', 'تم الفرز: الأرشيف (الأحدث أولاً)');
      });
      document.getElementById('sortArchivedOldest')?.addEventListener('click', () => {
        sortTable('archivedTable', 12, true);
        showAlert('success', 'تم الفرز: الأرشيف (الأقدم أولاً)');
      });
      document.getElementById('sortArchivedReset')?.addEventListener('click', () => {
        const tbody = document.getElementById('archivedTable').querySelector('tbody');
        tbody.innerHTML = '';
        originalArchivedRows.forEach(r => tbody.appendChild(r));
        reIndexTable('archivedTable');
        showAlert('success', 'تم إعادة الترتيب الافتراضي للأرشيف');
      });

      document.getElementById('sortQueriesNewest')?.addEventListener('click', () => {
        sortTable('queriesTable', 4, false);
        showAlert('success', 'تم الفرز: سجل الاستعلامات (الأحدث أولاً)');
      });
      document.getElementById('sortQueriesOldest')?.addEventListener('click', () => {
        sortTable('queriesTable', 4, true);
        showAlert('success', 'تم الفرز: سجل الاستعلامات (الأقدم أولاً)');
      });
      document.getElementById('sortQueriesReset')?.addEventListener('click', () => {
        const tbody = document.getElementById('queriesTable').querySelector('tbody');
        tbody.innerHTML = '';
        originalQueriesRows.forEach(r => tbody.appendChild(r));
        reIndexTable('queriesTable');
        showAlert('success', 'تم إعادة الترتيب الافتراضي لسجل الاستعلامات');
      });

      document.getElementById('sortQueriesDetailNewest')?.addEventListener('click', () => {
        sortTable('queriesDetailsTable', 1, false);
        showAlert('success', 'تم الفرز: تفاصيل الاستعلام (الأحدث أولاً)');
      });
      document.getElementById('sortQueriesDetailOldest')?.addEventListener('click', () => {
        sortTable('queriesDetailsTable', 1, true);
        showAlert('success', 'تم الفرز: تفاصيل الاستعلام (الأقدم أولاً)');
      });
      document.getElementById('sortQueriesDetailReset')?.addEventListener('click', () => {
        const tbody = document.getElementById('queriesDetailsTable')?.querySelector('tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        originalQueriesDetailRows.forEach(r => tbody.appendChild(r));
        reIndexTable('queriesDetailsTable');
        showAlert('success', 'تم إعادة الترتيب الافتراضي لتفاصيل الاستعلام');
      });

      const originalPaymentsRows = Array.from(document.querySelectorAll('#paymentsTable tbody tr'));
      document.getElementById('sortPaymentsPaid')?.addEventListener('click', () => {
        sortTable('paymentsTable', 5, false);
        showAlert('success','تم الفرز: أعلى المدفوعات');
      });
      document.getElementById('sortPaymentsUnpaid')?.addEventListener('click', () => {
        sortTable('paymentsTable', 6, false);
        showAlert('success','تم الفرز: أعلى غير المدفوعة');
      });
      document.getElementById('sortPaymentsReset')?.addEventListener('click', () => {
        const tbody = document.getElementById('paymentsTable').querySelector('tbody');
        tbody.innerHTML = '';
        originalPaymentsRows.forEach(r => tbody.appendChild(r));
        reIndexTable('paymentsTable');
        showAlert('success','تم إعادة الترتيب الافتراضي للمدفوعات');
      });

      document.querySelectorAll('.btn-view-patient-leaves').forEach(btn => {
        btn.addEventListener('click', () => {
          const pid = btn.closest('tr').getAttribute('data-id');
          const tbody = document.querySelector('#leavesTable tbody');
          Array.from(tbody.querySelectorAll('tr')).forEach(row => {
            row.style.display = row.getAttribute('data-patient') === pid ? '' : 'none';
          });
          showAlert('success', 'تم عرض إجازات المريض');
        });
      });

      // ==== 40. دوال الفرز بدون إعادة التحميل ==== 
      function sortTable(tableId, columnIdx, asc) {
        const tableBody = document.getElementById(tableId)?.querySelector('tbody');
        if (!tableBody) return;
        const rows = Array.from(tableBody.querySelectorAll('tr:not(.no-results)')).filter(r => r.style.display !== 'none');
        rows.sort((a, b) => {
          const va = a.children[columnIdx].textContent.trim();
          const vb = b.children[columnIdx].textContent.trim();
          // إذا كان التاريخ في نمط YYYY-MM-DD، نحوله لـ Date للمقارنة
          if (/^\d{4}-\d{2}-\d{2}/.test(va) && /^\d{4}-\d{2}-\d{2}/.test(vb)) {
            return asc ? new Date(va) - new Date(vb) : new Date(vb) - new Date(va);
          }
          return asc ? va.localeCompare(vb) : vb.localeCompare(va);
        });
        rows.forEach(row => tableBody.appendChild(row));
        reIndexTable(tableId);
      }

      // ==== 41. دوال الفلترة حسب التاريخ بدون إعادة التحميل ====
      function filterDate(tableId, cellClass, fromId, toId) {
        const fromInput = document.getElementById(fromId);
        const toInput = document.getElementById(toId);
        const table = document.getElementById(tableId);
        const tbody = table.querySelector('tbody');
        const noResultsClass = 'no-results';
        const fromDate = fromInput.value ? new Date(fromInput.value) : null;
        const toDate = toInput.value ? new Date(toInput.value) : null;
        let anyVisible = false;

        // إزالة صف النتائج الفارغة السابقة إن وجدت
        const existingNoRes = tbody.querySelector(`.${noResultsClass}`);
        if (existingNoRes) existingNoRes.remove();

        Array.from(tbody.querySelectorAll('tr')).forEach(row => {
          if (row.classList.contains(noResultsClass)) return;
          const cellText = row.querySelector(`.${cellClass}`).textContent.trim();
          // نأخذ فقط الجزء الخاص بالتاريخ (YYYY-MM-DD) قبل المسافة
          const datePart = cellText.split(' ')[0];
          const rowDate = new Date(datePart);
          let showRow = true;
          if (fromDate && rowDate < fromDate) showRow = false;
          if (toDate && rowDate > toDate) showRow = false;
          row.style.display = showRow ? '' : 'none';
          if (showRow) anyVisible = true;
        });

        if (!anyVisible) {
          const colCount = table.querySelectorAll('thead th').length;
          const noRow = document.createElement('tr');
          noRow.classList.add(noResultsClass);
          noRow.innerHTML = `<td colspan="${colCount}" class="no-results">لا توجد نتائج مطابقة.</td>`;
          tbody.append(noRow);
          showAlert('warning', 'لا توجد نتائج مطابقة للفلترة');
        } else {
          showAlert('success', 'تمت عملية الفلترة');
        }
        reIndexTable(tableId);
      }

      function resetDateFilter(tableId, originalRows, fromId, toId) {
        const tbody = document.getElementById(tableId).querySelector('tbody');
        tbody.innerHTML = '';
        originalRows.forEach(r => tbody.appendChild(r));
        document.getElementById(fromId).value = '';
        document.getElementById(toId).value = '';
        reIndexTable(tableId);
        showAlert('success', 'تمت إعادة عرض كافة البيانات');
      }

      const leaveDetailsModal = new bootstrap.Modal(document.getElementById('leaveDetailsModal'));
      function showLeaveDetails(id){
        let row = document.querySelector(`#leavesTable tr[data-id="${id}"]`);
        if(!row){
          row = document.querySelector(`#archivedTable tr[data-id="${id}"]`);
        }
        if(!row) return;
        row.scrollIntoView({behavior:'smooth'});
        const table = row.closest('table');
        const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent);
        let html = '<div class="table-responsive"><table class="table table-bordered text-center"><thead><tr>';
        headers.forEach(h=>{ html += `<th>${h}</th>`; });
        html += '</tr></thead><tbody><tr>';
        Array.from(row.children).forEach(td => { html += `<td>${td.innerHTML}</td>`; });
        html += '</tr></tbody></table></div>';
        document.getElementById('leaveDetailsContainer').innerHTML = html;
        leaveDetailsModal.show();
      }

      function filterPaid(tableId, colIdx, status) {
        document.querySelectorAll(`#${tableId} tbody tr[data-id]`).forEach(row => {
          const val = row.children[colIdx].textContent.trim();
          let show = true;
          if (status === 'paid') show = val === 'نعم';
          else if (status === 'unpaid') show = val === 'لا';
          row.style.display = show ? '' : 'none';
        });
        reIndexTable(tableId);
        if(status === 'paid') showAlert('success','تمت الفلترة: المدفوعة فقط');
        else if(status === 'unpaid') showAlert('success','تمت الفلترة: غير المدفوعة فقط');
        else showAlert('success','تمت إعادة عرض الكل');
      }

      // ==== 42. تفعيل أزرار الفلترة والإعادة ====
      // فلترة الإجازات النشطة
      document.getElementById('btn-filter-dates').addEventListener('click', () => {
        filterDate('leavesTable', 'cell-created', 'filter_from_date', 'filter_to_date');
      });
      document.getElementById('btn-reset-dates').addEventListener('click', () => {
        resetDateFilter('leavesTable', originalLeavesRows, 'filter_from_date', 'filter_to_date');
      });

      document.getElementById('showPaidLeaves').addEventListener('click', () => {
        filterPaid('leavesTable', 14, 'paid');
      });
      document.getElementById('showUnpaidLeaves').addEventListener('click', () => {
        filterPaid('leavesTable', 14, 'unpaid');
      });
      document.getElementById('showAllLeaves').addEventListener('click', () => {
        filterPaid('leavesTable', 14, null);
      });

      // فلترة الأرشيف
      document.getElementById('btn-filter-arch-dates').addEventListener('click', () => {
        filterDate('archivedTable', 'cell-deleted', 'filter_arch_from_date', 'filter_arch_to_date');
      });
      document.getElementById('btn-reset-arch-dates').addEventListener('click', () => {
        resetDateFilter('archivedTable', originalArchivedRows, 'filter_arch_from_date', 'filter_arch_to_date');
      });

      document.getElementById('showPaidArchived').addEventListener('click', () => {
        filterPaid('archivedTable', 14, 'paid');
      });
      document.getElementById('showUnpaidArchived').addEventListener('click', () => {
        filterPaid('archivedTable', 14, 'unpaid');
      });
      document.getElementById('showAllArchived').addEventListener('click', () => {
        filterPaid('archivedTable', 14, null);
      });

      // فلترة سجل الاستعلامات
      document.getElementById('btn-filter-queries-dates').addEventListener('click', () => {
        filterDate('queriesTable', 'cell-queried', 'filter_q_from_date', 'filter_q_to_date');
      });
      document.getElementById('btn-reset-queries-dates').addEventListener('click', () => {
        resetDateFilter('queriesTable', originalQueriesRows, 'filter_q_from_date', 'filter_q_to_date');
      });

      function attachNotifHandlers(){
        document.querySelectorAll('#notifPayments .btn-del-notif').forEach(btn => {
          btn.onclick = () => {
            const id = btn.getAttribute('data-id');
            const fd = new FormData();
            fd.append('action', 'delete_notification');
            fd.append('notification_id', id);
            fd.append('csrf_token', '<?= $_SESSION["csrf_token"] ?>');
            fetch('', {method:'POST', body: fd}).then(r=>r.json()).then(res=>{ if(res.success){ btn.closest('li').remove(); }});
          };
        });
        const confirmPayModal = new bootstrap.Modal(document.getElementById('confirmPaymentModal'));
        let currentPayLeave = null;
        document.querySelectorAll('#notifPayments .btn-pay-notif').forEach(btn => {
          btn.onclick = () => {
            currentPayLeave = btn.getAttribute('data-leave');
            const fd = new FormData();
            fd.append('action','get_leave_amount');
            fd.append('leave_id', currentPayLeave);
            fd.append('csrf_token','<?= $_SESSION["csrf_token"] ?>');
            fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
              if(res.success){
                document.getElementById('confirmPaymentAmount').value = res.amount;
                confirmPayModal.show();
              }
            });
          };
        });
        document.getElementById('confirmPaymentBtn').addEventListener('click',()=>{
          const amount = document.getElementById('confirmPaymentAmount').value || 0;
          const fd = new FormData();
          fd.append('action','mark_leave_paid');
          fd.append('leave_id', currentPayLeave);
          fd.append('amount', amount);
          fd.append('csrf_token','<?= $_SESSION["csrf_token"] ?>');
          fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
            if(res.success){
              confirmPayModal.hide();
              const li = document.querySelector(`#notifPayments li[data-leave="${currentPayLeave}"]`);
              if(li) li.remove();
              const row = document.querySelector(`#leavesTable tr[data-id="${currentPayLeave}"]`) || document.querySelector(`#archivedTable tr[data-id="${currentPayLeave}"]`);
              if(row){
                row.children[14].textContent = 'نعم';
                row.children[15].textContent = parseFloat(amount).toFixed(2);
              }
              showAlert('success','تم تحديث حالة الإجازة');
            }
          });
        });
        document.querySelectorAll('#notifPayments .btn-view-leave').forEach(btn => {
          btn.onclick = () => {
            const id = btn.getAttribute('data-leave');
            showLeaveDetails(id);
          };
        });
      }
      attachNotifHandlers();

      document.getElementById('refreshNotifs').addEventListener('click', () => {
        const fd = new FormData();
        fd.append('action','fetch_notifications');
        fd.append('csrf_token','<?= $_SESSION["csrf_token"] ?>');
        fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
          if(res.success){
            const ul = document.getElementById('notifPayments');
            ul.innerHTML = '';
            if(res.data.length===0){ ul.innerHTML = '<li class="list-group-item">لا إشعارات</li>'; }
            res.data.forEach(n=>{
              const li = document.createElement('li');
              li.className = 'list-group-item d-flex justify-content-between align-items-center';
              li.setAttribute('data-leave', n.leave_id);
              li.setAttribute('data-id', n.id);
              li.innerHTML = `<span>${n.message}</span><div class="btn-group"><button class="btn btn-info btn-sm btn-view-leave" data-leave="${n.leave_id}">تفاصيل</button><button class="btn btn-success btn-sm btn-pay-notif" data-leave="${n.leave_id}">مدفوعة</button><button class="btn btn-danger btn-sm btn-del-notif" data-id="${n.id}">حذف</button></div>`;
              ul.appendChild(li);
            });
            attachNotifHandlers();
            showAlert('success','تم تحديث الإشعارات');
          }
        });
      });

    });
  </script>
</body>
</html>
