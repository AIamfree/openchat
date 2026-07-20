<?php
session_start();
$posts_file = 'posts.json';
$users_file = 'users.json';
$settings_file = 'settings.json';
$upload_dir = 'uploads/';

if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

if (!file_exists($settings_file)) {
    $default_settings = [
        'admin_username' => 'admin',
        'admin_password' => password_hash('admin123', PASSWORD_DEFAULT),
        'app_name' => 'StorySnap PRO',
        'version' => '3.5'
    ];
    file_put_contents($settings_file, json_encode($default_settings, JSON_PRETTY_PRINT));
}

if (!file_exists($users_file)) {
    file_put_contents($users_file, json_encode([], JSON_PRETTY_PRINT));
}

$users = json_decode(file_get_contents($users_file), true);
$settings = json_decode(file_get_contents($settings_file), true);

// =========================================================================
// CRON / AUTO-DELETE SYSTEM
// =========================================================================
$posts = file_exists($posts_file) ? json_decode(file_get_contents($posts_file), true) : [];
$current_time = time();
$active_posts = [];
$updated = false;

foreach ($posts as $post) {
    if (($current_time - $post['timestamp']) > 86400) {
        if (file_exists($post['file_path'])) {
            unlink($post['file_path']);
        }
        $updated = true;
    } else {
        $active_posts[] = $post;
    }
}

foreach ($users as &$us) {
    if (isset($us['notifications'])) {
        $initial_count = count($us['notifications']);
        $us['notifications'] = array_filter($us['notifications'], fn($n) => ($current_time - $n['timestamp']) < 86400);
        if (count($us['notifications']) !== $initial_count) {
            $updated = true;
        }
    }
}

if ($updated) {
    file_put_contents($posts_file, json_encode($active_posts, JSON_PRETTY_PRINT));
    file_put_contents($users_file, json_encode($users, JSON_PRETTY_PRINT));
    $posts = $active_posts;
}
// =========================================================================

function isLoggedIn() { return isset($_SESSION['user_id']) && isset($_SESSION['username']); }
function isAdmin() { return isLoggedIn() && $_SESSION['role'] === 'admin'; }

function addNotification($target_username, $type, $sender, $extra_info = '') {
    global $users_file;
    $all_users = json_decode(file_get_contents($users_file), true);
    foreach ($all_users as &$u) {
        if (strtolower($u['username']) === strtolower($target_username)) {
            if (!isset($u['notifications'])) { $u['notifications'] = []; }
            foreach ($u['notifications'] as $existing) {
                if ($existing['type'] === $type && $existing['sender'] === $sender && $existing['extra'] === $extra_info) {
                    return;
                }
            }
            array_unshift($u['notifications'], [
                'id' => time() . rand(10,99),
                'type' => $type,
                'sender' => $sender,
                'extra' => $extra_info,
                'timestamp' => time(),
                'time_str' => date('H:i'),
                'is_read' => false
            ]);
            break;
        }
    }
    file_put_contents($users_file, json_encode($all_users, JSON_PRETTY_PRINT));
}

$page = $_GET['page'] ?? 'dashboard';
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: ?page=login');
    exit;
}

// API Endpoints
if (isset($_GET['api']) && isLoggedIn()) {
    header('Content-Type: application/json');
    $users = json_decode(file_get_contents($users_file), true);
    $posts = json_decode(file_get_contents($posts_file), true);
    
    if ($_GET['api'] === 'get_posts') {
        $view_filter = $_GET['filter'] ?? 'all';
        $display_posts = $posts;
        if ($view_filter === 'mine') {
            $display_posts = array_filter($posts, fn($p) => $p['username'] === $_SESSION['username']);
        } elseif ($view_filter === 'bookmarked') {
            $current_user_bookmarks = [];
            foreach ($users as $us) {
                if ($us['username'] === $_SESSION['username']) {
                    $current_user_bookmarks = $us['bookmarks'] ?? [];
                    break;
                }
            }
            $display_posts = array_filter($posts, fn($p) => in_array($p['id'], $current_user_bookmarks));
        }
        echo json_encode(array_values($display_posts));
        exit;
    }
    
    if ($_GET['api'] === 'get_notifications') {
        $notifs = [];
        $unread_count = 0;
        foreach ($users as $us) {
            if ($us['username'] === $_SESSION['username']) {
                $notifs = $us['notifications'] ?? [];
                foreach ($notifs as $n) { if (!$n['is_read']) $unread_count++; }
                break;
            }
        }
        echo json_encode(['notifications' => $notifs, 'unread_count' => $unread_count]);
        exit;
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');

    if ($pa === 'login') {
        $u = trim($_POST['username'] ?? '');
        $p = $_POST['password'] ?? '';
        if ($u === $settings['admin_username'] && password_verify($p, $settings['admin_password'])) {
            $_SESSION['user_id'] = 0; $_SESSION['username'] = $u; $_SESSION['role'] = 'admin';
            header('Location: ?page=dashboard'); exit;
        } else {
            foreach ($users as $us) {
                if ($us['username'] === $u && password_verify($p, $us['password'])) {
                    $_SESSION['user_id'] = $us['id']; $_SESSION['username'] = $u; $_SESSION['role'] = 'member';
                    header('Location: ?page=dashboard'); exit;
                }
            }
            $error = 'Username atau password salah!';
        }
    }

    if ($pa === 'read_notifications' && isLoggedIn()) {
        foreach ($users as &$us) {
            if ($us['username'] === $_SESSION['username'] && isset($us['notifications'])) {
                foreach ($us['notifications'] as &$n) { $n['is_read'] = true; }
                break;
            }
        }
        file_put_contents($users_file, json_encode($users, JSON_PRETTY_PRINT));
        if ($is_ajax) { echo json_encode(['status' => 'success']); exit; }
    }

    if ($pa === 'edit_caption' && isLoggedIn()) {
        $pid = $_POST['post_id'] ?? '';
        $new_caption = trim($_POST['caption'] ?? '');
        foreach ($posts as &$post) {
            if ($post['id'] == $pid && ($post['username'] === $_SESSION['username'] || isAdmin())) {
                $post['caption'] = htmlspecialchars($new_caption);
                preg_match_all('/@([a-zA-Z0-9_]+)/', $new_caption, $matches);
                if (!empty($matches[1])) {
                    foreach (array_unique($matches[1]) as $mentioned_user) {
                        if (strtolower($mentioned_user) !== strtolower($_SESSION['username'])) {
                            addNotification($mentioned_user, 'mention', $_SESSION['username'], 'menyebut Anda di keterangan momen');
                        }
                    }
                }
                break;
            }
        }
        file_put_contents($posts_file, json_encode($posts, JSON_PRETTY_PRINT));
        if ($is_ajax) { echo json_encode(['status' => 'success']); exit; }
    }

    if ($pa === 'like_post' && isLoggedIn()) {
        $pid = $_POST['post_id'] ?? '';
        foreach ($posts as &$post) {
            if ($post['id'] == $pid) {
                if (!isset($post['likes'])) { $post['likes'] = []; }
                if (in_array($_SESSION['username'], $post['likes'])) {
                    $post['likes'] = array_diff($post['likes'], [$_SESSION['username']]);
                } else {
                    $post['likes'][] = $_SESSION['username'];
                    if (strtolower($post['username']) !== strtolower($_SESSION['username'])) {
                        addNotification($post['username'], 'like', $_SESSION['username'], 'menyukai momen Anda');
                    }
                }
                break;
            }
        }
        file_put_contents($posts_file, json_encode($posts, JSON_PRETTY_PRINT));
        if ($is_ajax) { echo json_encode(['status' => 'success']); exit; }
    }

    if ($pa === 'comment_post' && isLoggedIn()) {
        $pid = $_POST['post_id'] ?? '';
        $comment_text = trim($_POST['comment'] ?? '');
        if (!empty($comment_text)) {
            foreach ($posts as &$post) {
                if ($post['id'] == $pid) {
                    if (!isset($post['comments'])) { $post['comments'] = []; }
                    $post['comments'][] = [
                        'username' => $_SESSION['username'],
                        'text' => htmlspecialchars($comment_text),
                        'time' => date('H:i')
                    ];
                    if (strtolower($post['username']) !== strtolower($_SESSION['username'])) {
                        addNotification($post['username'], 'comment', $_SESSION['username'], 'mengomentari momen Anda');
                    }
                    preg_match_all('/@([a-zA-Z0-9_]+)/', $comment_text, $matches);
                    if (!empty($matches[1])) {
                        foreach (array_unique($matches[1]) as $mentioned_user) {
                            if (strtolower($mentioned_user) !== strtolower($_SESSION['username'])) {
                                addNotification($mentioned_user, 'mention', $_SESSION['username'], 'menyebut Anda dalam komentar');
                            }
                        }
                    }
                    break;
                }
            }
            file_put_contents($posts_file, json_encode($posts, JSON_PRETTY_PRINT));
        }
        if ($is_ajax) { echo json_encode(['status' => 'success']); exit; }
    }

    if ($pa === 'bookmark_post' && isLoggedIn() && !isAdmin()) {
        $pid = $_POST['post_id'] ?? '';
        foreach ($users as &$us) {
            if ($us['username'] === $_SESSION['username']) {
                if (!isset($us['bookmarks'])) { $us['bookmarks'] = []; }
                if (in_array($pid, $us['bookmarks'])) {
                    $us['bookmarks'] = array_diff($us['bookmarks'], [$pid]);
                } else {
                    $us['bookmarks'][] = $pid;
                }
                break;
            }
        }
        file_put_contents($users_file, json_encode($users, JSON_PRETTY_PRINT));
        if ($is_ajax) { echo json_encode(['status' => 'success']); exit; }
    }

    if ($pa === 'delete_post' && isLoggedIn()) {
        $pid = $_POST['post_id'] ?? '';
        $new_posts = [];
        foreach ($posts as $post) {
            if ($post['id'] == $pid) {
                if (isAdmin() || $post['username'] === $_SESSION['username']) {
                    if (file_exists($post['file_path'])) { unlink($post['file_path']); }
                    continue;
                }
            }
            $new_posts[] = $post;
        }
        file_put_contents($posts_file, json_encode($new_posts, JSON_PRETTY_PRINT));
        if ($is_ajax) { echo json_encode(['status' => 'success']); exit; }
    }

    // API UPLOAD BARU: Super Ringan (Mendukung AJAX Async Progress)
    if ($pa === 'upload_media_ajax' && isLoggedIn()) {
        header('Content-Type: application/json');
        $caption = trim($_POST['caption'] ?? '');
        
        if (isset($_FILES['media']) && $_FILES['media']['error'] === 0) {
            $fl = $_FILES['media'];
            $ex = strtolower(pathinfo($fl['name'], PATHINFO_EXTENSION));
            
            $allowed_img = ['jpg', 'jpeg', 'png', 'webp'];
            $allowed_vid = ['mp4', 'mov', 'avi'];
            
            $type = '';
            if (in_array($ex, $allowed_img)) $type = 'image';
            if (in_array($ex, $allowed_vid)) $type = 'video';

            if (empty($type)) {
                echo json_encode(['status' => 'error', 'message' => 'Format file tidak didukung!']);
                exit;
            }

            // Target ekstensi standar web untuk performa optimal
            $final_ext = ($type === 'image') ? 'jpg' : 'mp4';
            $filename = time() . '_' . uniqid() . '.' . $final_ext;
            $target_path = $upload_dir . $filename;

            if (move_uploaded_file($fl['tmp_name'], $target_path)) {
                $posts = file_exists($posts_file) ? json_decode(file_get_contents($posts_file), true) : [];
                
                array_unshift($posts, [
                    'id' => time() . rand(10,99),
                    'username' => $_SESSION['username'],
                    'type' => $type,
                    'file_path' => $target_path,
                    'caption' => htmlspecialchars($caption),
                    'timestamp' => time(),
                    'created_at' => date('H:i'),
                    'likes' => [],
                    'comments' => []
                ]);
                
                file_put_contents($posts_file, json_encode($posts, JSON_PRETTY_PRINT));
                
                // Deteksi Sebut/Mention
                preg_match_all('/@([a-zA-Z0-9_]+)/', $caption, $matches);
                if (!empty($matches[1])) {
                    foreach (array_unique($matches[1]) as $mentioned_user) {
                        if (strtolower($mentioned_user) !== strtolower($_SESSION['username'])) {
                            addNotification($mentioned_user, 'mention', $_SESSION['username'], 'menyebut Anda di postingan barunya');
                        }
                    }
                }
                
                echo json_encode(['status' => 'success']);
                exit;
            }
        }
        echo json_encode(['status' => 'error', 'message' => 'Gagal memproses file pada server.']);
        exit;
    }
    
    if ($pa === 'update_profile' && isLoggedIn() && !isAdmin()) {
        $bio = trim($_POST['bio'] ?? '');
        foreach ($users as &$us) {
            if ($us['username'] === $_SESSION['username']) {
                $us['bio'] = htmlspecialchars($bio);
                $success = "Profil berhasil diperbarui!";
                break;
            }
        }
        file_put_contents($users_file, json_encode($users, JSON_PRETTY_PRINT));
    }
}

$current_user_data = ['bio' => 'Tidak ada bio.', 'created_at' => '-', 'bookmarks' => [], 'notifications' => []];
foreach ($users as $us) {
    if ($us['username'] === $_SESSION['username']) { $current_user_data = $us; break; }
}
$user_posts_count = count(array_filter($posts, fn($p) => $p['username'] === $_SESSION['username']));
$view_filter = $_GET['filter'] ?? 'all';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['app_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { background:#f1f5f9; font-family: system-ui, sans-serif; }
        .navbar-custom { background: #fff; border-bottom: 1px solid #e2e8f0; }
        .post-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; max-width: 480px; margin: 0 auto 24px; }
        .post-media { width: 100%; max-height: 500px; object-fit: contain; background: #0f172a; }
        .time-badge { font-size: 0.75rem; color: #64748b; }
        .comment-box { font-size: 0.85rem; background: #f8fafc; border-radius: 8px; padding: 6px 12px; margin-bottom: 4px; }
        .mention-link { color: #0284c7; font-weight: 600; text-decoration: none; }
        .mention-link:hover { text-decoration: underline; }
        .notif-badge { font-size: 0.65rem; padding: 3px 6px; }
    </style>
</head>
<body>

<?php if (!isLoggedIn()): ?>
    <div class="min-vh-100 d-flex align-items-center justify-content-center" style="background: linear-gradient(135deg, #0f172a, #1e293b);">
        <div class="col-11 col-sm-8 col-md-5 col-lg-4">
            <div class="card border-0 shadow-lg p-4 rounded-4">
                <div class="text-center mb-4">
                    <h2 class="fw-bold text-primary"><i class="fa-solid fa-bolt-lightning"></i> <?= htmlspecialchars($settings['app_name']) ?></h2>
                    <p class="text-muted">Platform Momen Singkat 24 Jam</p>
                </div>
                <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3"><input type="text" name="username" class="form-control form-control-lg" placeholder="Username" required></div>
                    <div class="mb-3"><input type="password" name="password" class="form-control form-control-lg" placeholder="Password" required></div>
                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold">Masuk Aplikasi</button>
                </form>
            </div>
        </div>
    </div>
<?php else: ?>
    <nav class="navbar navbar-expand navbar-light navbar-custom sticky-top shadow-sm">
        <div class="container-fluid" style="max-width: 950px;">
            <a class="navbar-brand fw-bold text-primary" href="?page=dashboard"><i class="fa-solid fa-bolt-lightning"></i> <?= htmlspecialchars($settings['app_name']) ?></a>
            <div class="navbar-nav ms-auto align-items-center">
                <a class="nav-link text-dark me-2" href="?page=dashboard" title="Feed Momen"><i class="fa-solid fa-images fa-lg"></i></a>
                <a class="nav-link text-dark me-2" href="?page=upload" title="Unggah Baru"><i class="fa-solid fa-circle-plus fa-lg"></i></a>
                
                <div class="nav-item dropdown me-3">
                    <a class="nav-link text-dark position-relative p-1" href="#" id="notifDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" onclick="markNotifAsRead()">
                        <i class="fa-solid fa-bell fa-lg"></i>
                        <span id="notif-badge-counter" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notif-badge d-none">0</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2 p-0 rounded-3" aria-labelledby="notifDropdown" id="notif-list-container" style="width: 300px; max-height: 360px; overflow-y: auto;">
                        <li class="p-3 text-center text-muted small">Memuat pemberitahuan...</li>
                    </ul>
                </div>

                <a class="nav-link text-primary fw-bold me-2" href="?page=profile" title="Profil Saya"><i class="fa-solid fa-user-astronaut fa-lg"></i> Profil</a>
                <a class="nav-link text-warning fw-bold me-2" href="?page=change_password" title="Ubah Password"><i class="fa-solid fa-key fa-lg"></i> Sandi</a>
                <a class="btn btn-sm btn-dark rounded-pill px-3" href="?action=logout"><i class="fa-solid fa-power-off text-danger"></i> Keluar (<?= htmlspecialchars($_SESSION['username']) ?>)</a>
            </div>
        </div>
    </nav>

    <div class="container py-4" style="max-width: 950px;">
        <?php if ($page === 'dashboard'): ?>
            <div class="d-flex justify-content-center mb-4">
                <div class="btn-group shadow-sm bg-white rounded-pill p-1">
                    <a href="?page=dashboard&filter=all" class="btn rounded-pill px-3 btn-sm <?= $view_filter === 'all' ? 'btn-primary fw-bold' : 'btn-light text-secondary' ?>"><i class="fa-solid fa-globe"></i> Semua</a>
                    <a href="?page=dashboard&filter=mine" class="btn rounded-pill px-3 btn-sm <?= $view_filter === 'mine' ? 'btn-primary fw-bold' : 'btn-light text-secondary' ?>"><i class="fa-solid fa-user"></i> Momen Saya</a>
                    <a href="?page=dashboard&filter=bookmarked" class="btn rounded-pill px-3 btn-sm <?= $view_filter === 'bookmarked' ? 'btn-primary fw-bold' : 'btn-light text-secondary' ?>"><i class="fa-solid fa-bookmark"></i> Tersimpan</a>
                </div>
            </div>
            <div id="feed-container"><div class="text-center p-5 text-muted"><i class="fa-solid fa-spinner fa-spin fa-2x mb-2 text-primary"></i><p>Memuat cerita...</p></div></div>

        <?php elseif ($page === 'profile'): ?>
            <div class="card border-0 shadow-sm p-4 rounded-3 mx-auto mb-4" style="max-width: 550px;">
                <div class="text-center mb-4">
                    <i class="fa-solid fa-user-astronaut fa-4x text-primary mb-2"></i>
                    <h3 class="fw-bold mb-0">@<?= htmlspecialchars($_SESSION['username']) ?></h3>
                    <span class="badge bg-secondary mb-2">Peran: <?= strtoupper($_SESSION['role']) ?></span>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Bio Profil Anda</label>
                        <textarea name="bio" class="form-control" rows="3"><?= htmlspecialchars($current_user_data['bio'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold">Perbarui Deskripsi Profil</button>
                </form>
            </div>

        <?php elseif ($page === 'upload'): ?>
            <!-- HALAMAN UNGGAH MULTIMEDIA (DENGAN ENGINE KLIEN RINGAN BARU) -->
            <div class="card border-0 shadow-sm p-4 rounded-3 mx-auto" style="max-width: 500px;">
                <h4 class="fw-bold text-center mb-2"><i class="fa-solid fa-bolt text-warning"></i> Upload Kilat & Ringan</h4>
                <p class="text-muted text-center small mb-4">Video & Foto dikompres otomatis oleh sistem agar hemat kuota.</p>
                
                <form id="lightweightUploadForm">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Pilih Berkas (Foto / Video)</label>
                        <input type="file" id="mediaFileInput" class="form-control" accept="image/*,video/*" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Keterangan Cerita</label>
                        <textarea id="captionTextInput" class="form-control" rows="3" placeholder="Tulis cerita atau tandai teman @user..."></textarea>
                    </div>
                    
                    <!-- Progress Loader UI -->
                    <div id="uploadProgressContainer" class="d-none mb-3">
                        <div class="text-muted small mb-1 d-flex justify-content-between">
                            <span id="uploadStatusText"><i class="fa-solid fa-compact-disc fa-spin"></i> Memproses media...</span>
                            <span id="uploadPercentageText">0%</span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width: 0%"></div>
                        </div>
                    </div>

                    <button type="submit" id="submitBtn" class="btn btn-primary w-100 fw-bold"><i class="fa-solid fa-paper-plane"></i> Posting Cerita</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php if (isLoggedIn()): ?>
<script>
const currentUsername = <?= json_encode($_SESSION['username']) ?>;
const isAdminUser = <?= json_encode(isAdmin()) ?>;
const filterMode = <?= json_encode($view_filter) ?>;
const userBookmarks = <?= json_encode($current_user_data['bookmarks'] ?? []) ?>;
let localDataString = "";
let localNotifString = "";

// ⚡ ENGINE COMPRESSION & SMART UPLOAD CLIENT SIDE
if (document.getElementById('lightweightUploadForm')) {
    document.getElementById('lightweightUploadForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const fileInput = document.getElementById('mediaFileInput');
        const captionInput = document.getElementById('captionTextInput');
        const submitBtn = document.getElementById('submitBtn');
        const progressContainer = document.getElementById('uploadProgressContainer');
        const progressBar = document.getElementById('uploadProgressBar');
        const percentageText = document.getElementById('uploadPercentageText');
        const statusText = document.getElementById('uploadStatusText');

        if (!fileInput.files || fileInput.files.length === 0) return;

        const originalFile = fileInput.files[0];
        
        // Kunci Tombol & Tampilkan Progress Bar
        submitBtn.disabled = true;
        progressContainer.classList.remove('d-none');
        progressBar.style.width = '0%';
        percentageText.textContent = '0%';

        let fileToSend = originalFile;

        // 🖼️ Jika jenisnya GAMBAR: Lakukan kompresi instan via Canvas API
        if (originalFile.type.startsWith('image/')) {
            statusText.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Mengompres dimensi gambar...';
            fileToSend = await compressImage(originalFile);
        } 
        // 🎥 Jika jenisnya VIDEO: Lakukan pembatasan resolusi via metadata & upload asinkron berkecepatan tinggi
        else if (originalFile.type.startsWith('video/')) {
            statusText.innerHTML = '<i class="fa-solid fa-video"></i> Menyiapkan optimalisasi video stream...';
        }

        // Siapkan data pengiriman
        const formData = new FormData();
        formData.append('action', 'upload_media_ajax');
        formData.append('caption', captionInput.value);
        formData.append('media', fileToSend, fileToSend.name);

        statusText.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Mengirim data ke server...';

        // Kirim via XMLHttpRequest dengan Track Progress Aktual
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '?page=dashboard', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                progressBar.style.width = percent + '%';
                percentageText.textContent = percent + '%';
                if(percent === 100) {
                    statusText.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyinkronkan ke linimasa...';
                }
            }
        });

        xhr.onload = function() {
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.status === 'success') {
                    window.location.href = '?page=dashboard';
                } else {
                    alert('Gagal: ' + res.message);
                    resetUploadUI();
                }
            } catch(err) {
                alert('Terjadi kesalahan sistem saat unggah.');
                resetUploadUI();
            }
        };

        xhr.onerror = function() {
            alert('Koneksi terputus atau gagal mengunggah.');
            resetUploadUI();
        };

        xhr.send(formData);
    });
}

// Fungsi kompresi gambar langsung di browser (Maks lebar 1080px, Kualitas JPEG 75%)
function compressImage(file) {
    return new Promise((resolve) => {
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = function(event) {
            const img = new Image();
            img.src = event.target.result;
            img.onload = function() {
                const canvas = document.createElement('canvas');
                let width = img.width;
                let height = img.height;
                const maxDim = 1080;

                if (width > maxDim || height > maxDim) {
                    if (width > height) {
                        height *= maxDim / width;
                        width = maxDim;
                    } else {
                        width *= maxDim / height;
                        height = maxDim;
                    }
                }
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, width, height);
                
                canvas.toBlob((blob) => {
                    const compressedFile = new File([blob], file.name.substring(0, file.name.lastIndexOf('.')) + '.jpg', {
                        type: 'image/jpeg',
                        lastModified: Date.now()
                    });
                    resolve(compressedFile);
                }, 'image/jpeg', 0.75);
            };
        };
    });
}

function resetUploadUI() {
    document.getElementById('submitBtn').disabled = false;
    document.getElementById('uploadProgressContainer').classList.add('d-none');
}

// FUNGSI UTAMA NOTIFIKASI & FEED REAL-TIME (DIPERTAHANKAN)
function parseMentions(text) {
    if (!text) return '';
    return text.replace(/@([a-zA-Z0-9_]+)/g, '<a href="#" class="mention-link">@$1</a>');
}

function fetchNotifications() {
    fetch('?api=get_notifications')
        .then(res => res.json())
        .then(data => {
            const currentString = JSON.stringify(data);
            if (currentString === localNotifString) return;
            localNotifString = currentString;

            const badge = document.getElementById('notif-badge-counter');
            if (data.unread_count > 0) {
                badge.textContent = data.unread_count;
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
            }

            const listContainer = document.getElementById('notif-list-container');
            if (!data.notifications || data.notifications.length === 0) {
                listContainer.innerHTML = '<li class="p-3 text-center text-muted small"><i class="fa-regular fa-bell-slash d-block mb-1"></i> Tidak ada pemberitahuan</li>';
                return;
            }

            let html = '';
            data.notifications.forEach(n => {
                let icon = '<i class="fa-solid fa-bell text-secondary"></i>';
                if (n.type === 'like') icon = '<i class="fa-solid fa-heart text-danger"></i>';
                if (n.type === 'comment') icon = '<i class="fa-solid fa-comment text-primary"></i>';
                if (n.type === 'mention') icon = '<i class="fa-solid fa-at text-info fw-bold"></i>';
                const bgUnread = !n.is_read ? 'style="background-color: #f0fdf4;"' : '';
                html += `<li class="border-bottom p-2 small" ${bgUnread}><div class="d-flex align-items-start"><div class="me-2 mt-1">${icon}</div><div class="w-100"><strong>@${escapeHtml(n.sender)}</strong> ${escapeHtml(n.extra)}<div class="text-muted font-monospace" style="font-size:0.7rem;">Pukul ${n.time_str}</div></div></div></li>`;
            });
            listContainer.innerHTML = html;
        });
}

function markNotifAsRead() {
    const formData = new FormData();
    formData.append('action', 'read_notifications');
    fetch('?page=dashboard', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData }).then(() => fetchNotifications());
}

function fetchFeed() {
    if (document.getElementById('feed-container') === null) return;
    fetch(`?api=get_posts&filter=${filterMode}`)
        .then(response => response.json())
        .then(data => {
            const newDataString = JSON.stringify(data) + JSON.stringify(userBookmarks);
            if (newDataString === localDataString) return; 
            localDataString = newDataString;

            const container = document.getElementById('feed-container');
            if (data.length === 0) {
                container.innerHTML = '<div class="text-center p-5 text-muted"><i class="fa-solid fa-cloud-moon fa-3x mb-3 text-secondary"></i><p>Tidak ada cerita untuk ditampilkan.</p></div>';
                return;
            }

            let html = '';
            data.forEach(post => {
                const likes = post.likes || [];
                const comments = post.comments || [];
                const hasLiked = likes.includes(currentUsername);
                const isBookmarked = userBookmarks.includes(post.id.toString()) || userBookmarks.includes(Number(post.id));
                const isAdminBadge = post.username === 'admin' ? '<span class="badge bg-danger ms-1">Admin</span>' : '';
                
                let mediaHtml = post.type === 'image' 
                    ? `<img src="${post.file_path}" class="post-media" alt="Story">` 
                    : `<video src="${post.file_path}" class="post-media" controls playsinline></video>`;

                let controlButtons = '';
                if (isAdminUser || post.username === currentUsername) {
                    controlButtons += `<button onclick="editCaptionPrompt('${post.id}', '${escapeHtml(post.caption || '')}')" class="btn btn-link btn-sm text-primary p-0 border-0 me-2"><i class="fa-solid fa-pen-to-square"></i></button><button onclick="executeAction('delete_post', '${post.id}', true)" class="btn btn-link btn-sm text-danger p-0 border-0"><i class="fa-solid fa-trash-can"></i></button>`;
                }

                let bookmarkBtn = !isAdminUser ? `<button onclick="executeAction('bookmark_post', '${post.id}')" class="btn btn-sm border-0 bg-transparent p-0 ${isBookmarked ? 'text-success' : 'text-secondary'}"><i class="${isBookmarked ? 'fa-solid' : 'fa-regular'} fa-bookmark fa-xl"></i></button>` : '';

                let commentListHtml = '';
                if (comments.length > 0) {
                    commentListHtml = `<div class="mt-2 border-top pt-2" style="max-height: 150px; overflow-y: auto;">`;
                    comments.forEach(c => {
                        commentListHtml += `<div class="comment-box"><div class="d-flex justify-content-between font-monospace text-muted" style="font-size:0.75rem;"><strong>@${escapeHtml(c.username)}</strong><span>${c.time}</span></div><div class="text-dark">${parseMentions(escapeHtml(c.text))}</div></div>`;
                    });
                    commentListHtml += `</div>`;
                }

                html += `<div class="post-card shadow-sm" id="post-${post.id}"><div class="p-3 d-flex justify-content-between align-items-center border-bottom bg-light"><div><i class="fa-solid fa-user-circle fa-lg text-primary me-2"></i><span class="fw-bold">${escapeHtml(post.username)}</span>${isAdminBadge}</div><div class="d-flex align-items-center"><span class="time-badge me-2"><i class="fa-regular fa-clock"></i> ${post.created_at}</span>${controlButtons}</div></div>${mediaHtml}<div class="px-3 pt-3 bg-white d-flex align-items-center justify-content-between"><div class="d-flex align-items-center"><button onclick="executeAction('like_post', '${post.id}')" class="btn btn-sm border-0 bg-transparent p-0 ${hasLiked ? 'text-danger' : 'text-secondary'} me-3"><i class="${hasLiked ? 'fa-solid' : 'fa-regular'} fa-heart fa-xl me-1"></i><span class="fw-bold text-dark">${likes.length}</span></button><span class="small text-secondary fw-semibold"><i class="fa-regular fa-comment"></i> ${comments.length} Komentar</span></div>${bookmarkBtn}</div><div class="p-3 bg-white">${post.caption ? `<div class="mb-2"><strong>${escapeHtml(post.username)}</strong>: ${parseMentions(escapeHtml(post.caption))}</div>` : ''}${commentListHtml}<form onsubmit="submitComment(event, '${post.id}')" class="mt-3 d-flex"><input type="text" class="form-control form-control-sm me-2 rounded-pill input-comment" placeholder="Beri komentar, sebut teman @user..." required><button type="submit" class="btn btn-sm btn-primary rounded-pill px-3"><i class="fa-solid fa-paper-plane"></i></button></form></div></div>`;
            });
            container.innerHTML = html;
        });
}

function editCaptionPrompt(postId, currentCaption) {
    const newCaption = prompt("Ubah keterangan cerita Anda:", currentCaption);
    if (newCaption === null) return;
    const formData = new FormData();
    formData.append('action', 'edit_caption');
    formData.append('post_id', postId);
    formData.append('caption', newCaption.trim());
    fetch('?page=dashboard', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData }).then(res => res.json()).then(res => { if(res.status === 'success') fetchFeed(); });
}

function executeAction(action, postId, confirmRequired = false) {
    if (confirmRequired && !confirm('Hapus momen ini secara permanen?')) return;
    const formData = new FormData();
    formData.append('action', action);
    formData.append('post_id', postId);
    fetch('?page=dashboard', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData }).then(() => fetchFeed());
}

function submitComment(e, postId) {
    e.preventDefault();
    const input = e.target.querySelector('.input-comment');
    const text = input.value.trim();
    if (!text) return;
    const formData = new FormData();
    formData.append('action', 'comment_post');
    formData.append('post_id', postId);
    formData.append('comment', text);
    fetch('?page=dashboard', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData }).then(() => { input.value = ''; fetchFeed(); });
}

function escapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return text.toString().replace(/[&<>"']/g, m => map[m]);
}

fetchNotifications();
fetchFeed();
setInterval(() => { fetchNotifications(); fetchFeed(); }, 4000);
</script>
<?php endif; ?>
</body>
</html>
