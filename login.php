<?php
session_start();

// بيانات الاتصال
$db_host = 'ocvwlym0zv3tcn68.cbetxkdyhwsb.us-east-1.rds.amazonaws.com';
$db_user = 'smrg7ak77778emkb';
$db_pass = 'fw69cuijof4ahuhb';
$db_name = 'ygscnjzq8ueid5yz';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';

  $stmt = $conn->prepare("SELECT id, password FROM admins WHERE username=? LIMIT 1");
  $stmt->bind_param("s", $username);
  $stmt->execute();
  $stmt->bind_result($uid, $pw_hash);

  if ($stmt->fetch() && password_verify($password, $pw_hash)) {
    $_SESSION['admin_id'] = $uid;
    $stmt->close();
    $conn->close();
    header("Location: admin.php");
    exit;
  } else {
    $msg = "بيانات الدخول غير صحيحة!";
  }
  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
  <meta charset="UTF-8">
  <title>تسجيل دخول المشرف</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@500;700&display=swap" rel="stylesheet">
  <style>
    body {
      background: linear-gradient(135deg, #3776ed 0%, #6ec9f7 100%);
      background-size: 200% 200%;
      animation: gradientShift 8s ease infinite;
      min-height: 100vh;
      font-family: 'Cairo', Tahoma, Arial, sans-serif;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0;
    }

    @keyframes gradientShift {
      0% { background-position: 0 0; }
      50% { background-position: 100% 100%; }
      100% { background-position: 0 0; }
    }

    .login-box {
      background: #fff;
      border-radius: 24px;
      box-shadow: 0 8px 32px rgba(55, 118, 237, 0.12), 0 1.5px 5px #c7e4fb;
      padding: 38px 30px 30px 30px;
      max-width: 380px;
      width: 100%;
      margin: auto;
      position: relative;
      animation: pop-in .8s cubic-bezier(.48, -0.04, .54, 1.23);
    }

    @keyframes pop-in {
      from {
        transform: scale(.92) translateY(40px);
        opacity: .3;
      }

      to {
        transform: scale(1) translateY(0);
        opacity: 1;
      }
    }

    .login-icon {
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 10px;
      font-size: 44px;
      color: #3776ed;
      background: #e8f1fe;
      border-radius: 50%;
      width: 68px;
      height: 68px;
      margin: -64px auto 20px auto;
      box-shadow: 0 3px 14px #edf4fa;
    }

    h3 {
      letter-spacing: 0.5px;
      font-weight: 700;
      color: #222;
      margin-bottom: 14px;
      font-size: 1.5rem;
    }

    .login-box .form-control {
      border-radius: 13px;
      border: 1.5px solid #c7e4fb;
      box-shadow: none;
      padding: 10px 16px;
      font-size: 1rem;
      background: #f8fbff;
      transition: border-color 0.2s;
      font-family: inherit;
    }

    .login-box .form-control:focus {
      border-color: #3776ed;
      background: #fff;
    }

    .btn-primary {
      background: linear-gradient(90deg, #3776ed 0%, #61c2f5 100%);
      border: none;
      font-weight: 700;
      border-radius: 13px;
      transition: background .2s;
      font-size: 1.1rem;
      box-shadow: 0 4px 24px #e6f1fd;
    }

    .btn-primary:focus,
    .btn-primary:hover {
      background: linear-gradient(90deg, #2761b2 0%, #4db8e6 100%);
      outline: none;
    }

    .form-label {
      font-weight: bold;
      color: #1e375a;
      margin-bottom: 5px;
      font-size: 1.03rem;
    }

    .alert {
      margin-bottom: 15px;
      font-size: 1rem;
      border-radius: 9px;
      text-align: center;
    }

    @media (max-width: 480px) {
      .login-box {
        padding: 20px 7vw;
      }

      .login-icon {
        font-size: 38px;
        width: 54px;
        height: 54px;
        margin-top: -46px;
      }

      h3 {
        font-size: 1.08rem;
      }
    }
  </style>
</head>

<body>
  <div class="login-box shadow">
    <div class="login-icon">
      <svg width="34" height="34" fill="none" viewBox="0 0 48 48">
        <circle cx="24" cy="24" r="23" fill="#e8f1fe" stroke="#3776ed" stroke-width="2" />
        <path d="M32 19a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z" fill="#3776ed" stroke="#3776ed" stroke-width="2" />
        <path d="M8 37c0-5.523 7.163-10 16-10s16 4.477 16 10v2H8v-2Z" fill="#c7e4fb" stroke="#3776ed"
          stroke-width="2" />
      </svg>
    </div>
    <h3 class="mb-4 text-center">تسجيل دخول المشرف</h3>
    <?php if ($msg): ?>
      <div class="alert alert-danger shadow-sm"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <div class="mb-3">
        <label class="form-label" for="username">اسم المستخدم</label>
        <input type="text" id="username" name="username" class="form-control shadow-sm" autofocus required>
      </div>
      <div class="mb-3">
        <label class="form-label" for="password">كلمة المرور</label>
        <input type="password" id="password" name="password" class="form-control shadow-sm" required>
      </div>
      <button type="submit" class="btn btn-primary w-100 mt-2 shadow">دخول</button>
    </form>
  </div>
</body>

</html>