<?php
session_start();

// Load environment labels shown in the page footer.
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';

    require_once __DIR__ . '/config.php';

    $row = null;
    $conn = null;

    if (use_dynamodb()) {
        try {
            $row = dynamodb_find_user($user);
        } catch (Throwable $e) {
            die("DB接続エラー");
        }
    } else {
        $conn = db_connect('auth_system');
        if ($conn->connect_error) { die("DB接続エラー"); }

        $stmt = $conn->prepare("SELECT password, redirect_target FROM login_users WHERE username=?");
        $stmt->bind_param("s", $user);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
    }

    if ($row) {
        if (password_verify($pass, $row['password'])) {
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $user;
            $_SESSION['can_edit'] = ($row['redirect_target'] ?? '') === 'index.php';

            // Keep redirects independent of the deployment host.
            $target = $_SESSION['can_edit'] ? 'index.php' : 'index2.php';
            header("Location: " . $target);
            exit;
        }
    }

    $error = "IDまたはパスワードが正しくありません。";
    if ($conn instanceof mysqli) {
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Our Story</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%23ec4899%22 stroke-width=%222%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22><path d=%22M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5%22/></svg>">
    <!-- Versioned local assets avoid runtime CDN dependencies. -->
    <link rel="stylesheet" href="assets/tailwind.css">
    <script src="assets/lucide.min.js"></script>
    <style>
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fadeIn { animation: fadeIn 0.5s ease-out forwards; }
        body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
    </style>
</head>
<body class="bg-rose-50/30 text-gray-800 font-sans min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md animate-fadeIn">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-white rounded-3xl shadow-lg shadow-rose-100 mb-4">
                <i data-lucide="heart" class="text-pink-500 w-8 h-8"></i>
            </div>
            <h1 class="text-2xl font-black tracking-tighter uppercase">Our <span class="text-pink-500">Story</span></h1>
            <p class="text-gray-400 text-[10px] font-black uppercase tracking-[0.2em] mt-2">Authentication Portal</p>
        </div>

        <div class="bg-white rounded-[2.5rem] shadow-2xl shadow-rose-100/50 overflow-hidden border border-white p-8 lg:p-10">
            <h2 class="text-xl font-black text-gray-800 text-center mb-8 tracking-tighter">Sign in</h2>

            <?php if(isset($error)): ?>
                <div class="bg-red-50 border border-red-100 text-red-500 text-xs font-bold px-4 py-3 rounded-2xl mb-6 flex items-center gap-2">
                    <i data-lucide="alert-circle" class="w-4 h-4"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5">
                <div class="space-y-1">
                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-widest ml-1">ユーザー名</label>
                    <div class="relative">
                        <i data-lucide="user" class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-300 w-5 h-5"></i>
                        <input type="text" name="username" placeholder="Username" required
                            class="w-full pl-12 pr-5 py-4 bg-gray-50 rounded-2xl font-bold border-none focus:ring-2 focus:ring-pink-200 transition-all outline-none">
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-widest ml-1">パスワード</label>
                    <div class="relative">
                        <i data-lucide="lock" class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-300 w-5 h-5"></i>
                        <input type="password" name="password" placeholder="Password" required
                            class="w-full pl-12 pr-5 py-4 bg-gray-50 rounded-2xl font-bold border-none focus:ring-2 focus:ring-pink-200 transition-all outline-none">
                    </div>
                </div>

                <button type="submit"
                    class="w-full bg-pink-500 text-white font-black py-4 rounded-2xl shadow-xl shadow-pink-100 flex items-center justify-center gap-3 transition-all active:scale-[0.98] hover:bg-pink-600 mt-8 text-sm uppercase tracking-widest">
                    ログイン <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </button>
            </form>
        </div>

        <div class="text-center mt-8 space-y-2">
            <p class="text-gray-300 text-[10px] font-bold uppercase tracking-widest">Secure Gateway Connection</p>
            <div class="flex justify-center gap-2">
                <span class="bg-white/50 text-gray-400 text-[9px] px-2 py-0.5 rounded-full font-bold border border-white"><?= htmlspecialchars(app_platform_label(), ENT_QUOTES) ?></span>
                <span class="bg-white/50 text-gray-400 text-[9px] px-2 py-0.5 rounded-full font-bold border border-white"><?= htmlspecialchars(db_engine_label(), ENT_QUOTES) ?></span>
            </div>
        </div>
    </div>
    <script>lucide.createIcons();</script>
</body>
</html>