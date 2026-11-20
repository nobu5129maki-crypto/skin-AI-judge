<?php
require __DIR__ . '/auth.php';
skin_session_start();

if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$error = '';
$postedId = '';
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedId = isset($_POST['login_id']) ? trim((string) $_POST['login_id']) : '';
    $postedPassword = isset($_POST['password']) ? (string) $_POST['password'] : '';
    $postedToken = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    $tokenOk = is_string($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $postedToken);
    if ($tokenOk && skin_check_login($postedId, $postedPassword)) {
        session_regenerate_id(true);
        $_SESSION['skin_user'] = $postedId;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        $next = skin_cookie_path();
        if ($next !== '/') {
            $next .= '/';
        }
        header('Location: ' . $next, true, 303);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        exit;
    }
    $error = 'IDまたはパスワードが違います';
}

if (skin_logged_in()) {
    $app = __DIR__ . '/index.html';
    if (!is_file($app)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "画面を読み込めません\n";
        exit;
    }
    $html = file_get_contents($app);
    if (!is_string($html)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "画面を読み込めません\n";
        exit;
    }
    $save = <<<'HTML'
<script>(function(){try{var i=sessionStorage.getItem("skinai_pending_id");var p=sessionStorage.getItem("skinai_pending_password");if(i&&p){localStorage.setItem("skinai_login_id",i);localStorage.setItem("skinai_login_password",p);sessionStorage.removeItem("skinai_pending_id");sessionStorage.removeItem("skinai_pending_password");}}catch(e){}})();</script>
<script>if("serviceWorker" in navigator){navigator.serviceWorker.register("./sw.js",{scope:"./"}).catch(function(){});}</script>
HTML;
    $html = preg_replace('/<\\/head>/', $save . '</head>', $html, 1);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    echo $html;
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
http_response_code(200);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1e1b4b">
    <meta name="robots" content="noindex, nofollow">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="AI肌解析プロ">
    <meta name="description" content="AIが肌の状態を解析し、ケアの目安を出します">
    <link rel="manifest" href="./manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="./icon-192.png">
    <link rel="apple-touch-icon" href="./icon-192.png">
    <title>ログイン｜AI肌解析プロ</title>
    <script>
        if ("serviceWorker" in navigator) {
            navigator.serviceWorker.register("./sw.js", { scope: "./" }).catch(function () {});
        }
    </script>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body {
            font-family: -apple-system, "Hiragino Sans", "Hiragino Kaku Gothic ProN", "Noto Sans JP", sans-serif;
            color: #0f172a;
            background: #f8fafc;
        }
        main { max-width: 430px; margin: 0 auto; padding: 36px 16px 48px; }
        header { text-align: center; }
        .mark {
            width: 72px;
            height: 72px;
            border-radius: 18px;
            box-shadow: 0 12px 30px rgba(30, 27, 75, 0.18);
        }
        h1 { margin: 14px 0 0; font-size: 1.45rem; letter-spacing: -0.02em; }
        .lead { margin: 8px 0 0; color: #64748b; line-height: 1.7; }
        form {
            margin-top: 22px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
        }
        label { display: block; font-size: 0.85rem; font-weight: 700; color: #4338ca; margin-bottom: 6px; }
        .field { margin-bottom: 14px; }
        input {
            width: 100%;
            border: 1px solid #cbd5e1;
            background: #fff;
            border-radius: 12px;
            padding: 12px 14px;
            font: inherit;
            color: inherit;
        }
        .password-row { display: flex; gap: 8px; }
        .password-row input { min-width: 0; }
        button, .toggle { font: inherit; cursor: pointer; }
        .toggle {
            flex: 0 0 auto;
            border-radius: 12px;
            border: 1px solid #4338ca;
            background: transparent;
            color: #4338ca;
            padding: 0 14px;
            font-weight: 700;
        }
        .submit {
            width: 100%;
            border: none;
            border-radius: 15px;
            background: #4f46e5;
            color: #fff;
            font-weight: 800;
            padding: 14px;
        }
        .error {
            margin: 0 0 14px;
            padding: 10px 12px;
            border-radius: 12px;
            background: #fff1f2;
            color: #e11d48;
            font-size: 0.95rem;
            font-weight: 700;
        }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
    <main>
        <header>
            <img class="mark" src="./icon-192.png" alt="" width="72" height="72">
            <h1>AI肌解析プロ</h1>
            <p class="lead">入るには ID とパスワードが必要です</p>
        </header>
        <form method="post" action="" autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8'); ?>">
            <p id="loginError" class="error" role="alert"<?php echo $error === '' ? ' hidden' : ''; ?>><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="field">
                <label for="login_id">ID</label>
                <input id="login_id" name="login_id" type="text" inputmode="latin" autocapitalize="none" autocorrect="off" spellcheck="false" autocomplete="username" required maxlength="64" value="<?php echo htmlspecialchars($postedId, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="field">
                <label for="password">パスワード</label>
                <div class="password-row">
                    <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="128">
                    <button type="button" class="toggle" id="togglePassword" aria-controls="password" aria-pressed="false">表示</button>
                </div>
            </div>
            <button type="submit" class="submit">入る</button>
        </form>
    </main>
    <script>
        var idKey = "skinai_login_id";
        var passwordKey = "skinai_login_password";
        var form = document.querySelector("form");
        var idInput = document.getElementById("login_id");
        var passwordInput = document.getElementById("password");
        var errorBox = document.getElementById("loginError");

        document.getElementById("togglePassword").addEventListener("click", function () {
            var showing = passwordInput.type === "text";
            passwordInput.type = showing ? "password" : "text";
            this.textContent = showing ? "表示" : "隠す";
            this.setAttribute("aria-pressed", showing ? "false" : "true");
        });

        form.addEventListener("submit", function () {
            try {
                sessionStorage.setItem("skinai_pending_id", idInput.value.trim());
                sessionStorage.setItem("skinai_pending_password", passwordInput.value);
            } catch (err) {}
        });

        try {
            var failed = errorBox && !errorBox.hasAttribute("hidden") && errorBox.textContent;
            if (failed) {
                localStorage.removeItem(passwordKey);
                sessionStorage.removeItem("skinai_pending_id");
                sessionStorage.removeItem("skinai_pending_password");
            }
            var savedId = localStorage.getItem(idKey) || "";
            var savedPassword = localStorage.getItem(passwordKey) || "";
            if (!failed && savedId && savedPassword) {
                idInput.value = savedId;
                passwordInput.value = savedPassword;
                try {
                    sessionStorage.setItem("skinai_pending_id", savedId);
                    sessionStorage.setItem("skinai_pending_password", savedPassword);
                } catch (err) {}
                form.submit();
            } else if (savedId && !idInput.value) {
                idInput.value = savedId;
            }
        } catch (err) {}
    </script>
</body>
</html>
