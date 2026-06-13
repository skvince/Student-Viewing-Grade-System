<?php require_once __DIR__ . '/inc/functions.php'; ?>
<?php
session_start();
$loginError = '';
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    session_start();
    $loginError = 'You have been logged out.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === 'admin' && $password === '123') {
        $_SESSION['user_role'] = 'admin';
        $_SESSION['user_id'] = 0;
        header('Location: admin.php');
        exit;
    }

  if (! $username || ! $password) {
    $loginError = 'Username and password are required.';
  } else {
    $conn = db_connect();
    if (! $conn) {
      $loginError = 'Database connection failed.';
    } else {
        $user = authenticate_user($username, $password);
        if ($user) {
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_id'] = $user['id'];
            audit_log('login', $user['id']);
            if ($user['role'] === 'teacher') {
                header('Location: teacher_module.php');
            } else {
                header('Location: student_module.php');
            }
            exit;
        } else {
            $loginError = 'Invalid username or password.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>CSCQC Portal</title>
    <link rel="icon" type="image/png" href="https://cscqcph.com/images/bg/cscqc.png"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
    <style>
      * { margin: 0; padding: 0; box-sizing: border-box; font-family: Arial, Helvetica, sans-serif; }
      body { background: #f5f5f5; min-height: 100vh; }
      main { display: flex; min-height: 100vh; }
      section.login-section { width: 50%; display: flex; justify-content: center; align-items: center; background: #f5f5f5; }
      article.login-box { width: 450px; }
      header.title { display: flex; align-items: center; gap: 15px; margin-bottom: 50px; }
      .icon { width: 75px; height: 75px; border-radius: 50%; background: #ffffff; box-shadow: 0 2px 8px rgba(0,0,0,0.1); display: flex; justify-content: center; align-items: center; }
      .icon img { max-width: 85%; max-height: 85%; object-fit: contain; }
      .title h1 { font-size: 55px; color: #143d2b; font-family: Georgia, "Times New Roman", Times, serif; }
      form { display: flex; flex-direction: column; }
      label { font-size: 18px; margin-bottom: 10px; color: #214d38; }
      input { width: 100%; padding: 18px; margin-bottom: 30px; border: none; border-radius: 12px; background: #e9eef7; font-size: 18px; outline: none; }
      button { padding: 18px; border: none; border-radius: 12px; background: #124b2f; color: white; font-size: 28px; font-weight: bold; cursor: pointer; transition: 0.3s; }
      button:hover { background: #0d3823; }
      aside { width: 50%; background: #124b2f; color: white; display: flex; justify-content: center; align-items: center; text-align: center; }
      .right-content h2 { font-size: 90px; line-height: 1.1; font-family: Georgia, "Times New Roman", Times, serif; margin-bottom: 40px; }
      .right-content p { font-size: 24px; color: #d5d5d5; }
      @media (max-width: 900px) {
        main { flex-direction: column; }
        section.login-section, aside { width: 100%; min-height: 50vh; padding: 40px 20px; }
        .right-content h2 { font-size: 50px; }
        .title h1 { font-size: 40px; }
      }
    </style>
  </head>
  <body>
    <main>
      <section class="login-section">
        <article class="login-box">
          <header class="title">
            <div class="icon"><img src="https://cscqcph.com/images/bg/cscqcph.png" alt="CSCQC Logo"></div>
            <h1>Sign In</h1>
          </header>
          <form method="post">
            <?php if ($loginError): ?>
              <div style="background:#fde8e8;color:#991b1b;padding:10px;border-radius:6px;margin-bottom:12px;"><?php echo htmlspecialchars($loginError); ?></div>
            <?php endif; ?>
            <label for="username">Username </label>
            <input type="text" id="username" name="username" placeholder="ID" required />
            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="Password" required />
            <button type="submit">Login</button>
          </form>
        </article>
      </section>
      <aside>
        <div class="right-content">
          <h2>BE FUTURE <br />QUALIFIED <br />WITH US</h2>
          <p>College of St. Catherine Quezon City</p>
        </div>
      </aside>
    </main>
  </body>
</html>
