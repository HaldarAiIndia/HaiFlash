<?php
// ============================================================
//  HaiFire — Admin Verification Panel
//  verification.php  |  Standalone Admin-only page
//  Shares /data/ files with index.php
// ============================================================

session_start();
session_regenerate_id(false);

// ── Data paths (must match index.php) ────────────────────────
define('DATA_DIR',   __DIR__ . '/data/');
define('USERS_FILE', DATA_DIR . 'users.json');
define('POSTS_FILE', DATA_DIR . 'posts.json');
define('CMNT_FILE',  DATA_DIR . 'comments.json');
define('AVATAR_DIR', DATA_DIR . 'avatars/');
define('AVATAR_URL', 'data/avatars/');

foreach ([DATA_DIR, AVATAR_DIR] as $d) {
    if (!is_dir($d)) mkdir($d, 0755, true);
}
foreach ([USERS_FILE, POSTS_FILE, CMNT_FILE] as $f) {
    if (!file_exists($f)) file_put_contents($f, '[]', LOCK_EX);
}

// ── Core helpers ─────────────────────────────────────────────
function rj(string $f): array {
    $fp = fopen($f, 'r'); if (!$fp) return [];
    flock($fp, LOCK_SH);
    $data = json_decode(stream_get_contents($fp), true) ?? [];
    flock($fp, LOCK_UN); fclose($fp);
    return $data;
}
function wj(string $f, array $d): void {
    $fp = fopen($f, 'c+'); if (!$fp) return;
    flock($fp, LOCK_EX); ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    flock($fp, LOCK_UN); fclose($fp);
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function now(): string { return date('c'); }
function logged_in(): bool { return !empty($_SESSION['uid']); }
function me(): array {
    return $_SESSION['user'] ?? ['uid'=>'','displayName'=>'','username'=>'','photoURL'=>'','verified'=>false];
}
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function vcsrf(): void {
    if (($_POST['_t'] ?? '') !== ($_SESSION['csrf'] ?? '')) { http_response_code(403); die('Bad CSRF token'); }
}
function get_user_by_uid(string $uid): ?array {
    foreach (rj(USERS_FILE) as $u) { if ($u['uid'] === $uid) return $u; }
    return null;
}
function is_admin(): bool {
    if (!logged_in()) return false;
    $all = rj(USERS_FILE); if (empty($all)) return false;
    $u   = me();
    return ($u['username'] === 'admin') || ($all[0]['uid'] === $u['uid']);
}
function av(string $uid, string $name, string $size = '44px'): string {
    $u     = $uid ? get_user_by_uid($uid) : null;
    $photo = $u['photoURL'] ?? '';
    $i     = strtoupper(mb_substr($name ?: '?', 0, 1));
    $fs    = 'calc(' . $size . ' * 0.38)';
    if ($photo && file_exists(AVATAR_DIR . basename($photo))) {
        $src = h(AVATAR_URL . basename($photo));
        return "<div style=\"width:{$size};height:{$size};border-radius:50%;overflow:hidden;flex-shrink:0\"><img src=\"{$src}\" style=\"width:100%;height:100%;object-fit:cover\" alt=\"\"></div>";
    }
    return "<div style=\"width:{$size};height:{$size};border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,#0d0e2e,#1a1850);border:1.5px solid #9090e0;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:{$fs};color:#a8a8f8\">{$i}</div>";
}

// ── AJAX / POST handlers ──────────────────────────────────────
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
$method  = $_SERVER['REQUEST_METHOD'];
$action  = trim($_POST['action'] ?? '');

// Toggle verification (AJAX)
if ($method === 'POST' && isset($_POST['target_uid']) && !isset($_POST['bulk'])) {
    vcsrf();
    if (!is_admin()) { http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Forbidden']); exit; }
    $tuid   = trim($_POST['target_uid']);
    $act    = in_array($action, ['verify','unverify']) ? $action : '';
    if (!$tuid || !$act) { echo json_encode(['ok'=>false,'msg'=>'Bad params']); exit; }

    $users = rj(USERS_FILE);
    $found = false;
    foreach ($users as &$u) {
        if ($u['uid'] === $tuid) {
            $u['verified'] = ($act === 'verify');
            if ($tuid === $_SESSION['uid']) $_SESSION['user'] = $u;
            $found = true; break;
        }
    }
    wj(USERS_FILE, $users);
    echo json_encode(['ok' => $found, 'verified' => ($act === 'verify')]);
    exit;
}

// Bulk verify / unverify all (POST form)
if ($method === 'POST' && isset($_POST['bulk'])) {
    vcsrf();
    if (!is_admin()) { header('Location: verification.php'); exit; }
    $bulk   = $_POST['bulk'];        // 'verify_all' | 'unverify_all'
    $uids   = (array)($_POST['uids'] ?? []);
    $state  = ($bulk === 'verify_all');
    $users  = rj(USERS_FILE);
    foreach ($users as &$u) {
        if (in_array($u['uid'], $uids)) {
            $u['verified'] = $state;
            if ($u['uid'] === $_SESSION['uid']) $_SESSION['user'] = $u;
        }
    }
    wj(USERS_FILE, $users);
    header('Location: verification.php?saved=' . ($state ? 'verified' : 'unverified') . '&count=' . count($uids));
    exit;
}

// ── Pre-load data ─────────────────────────────────────────────
$all_users    = rj(USERS_FILE);
$all_posts    = rj(POSTS_FILE);
$all_comments = rj(CMNT_FILE);

$total_users    = count($all_users);
$total_verified = count(array_filter($all_users, fn($u) => $u['verified'] ?? false));
$total_unverif  = $total_users - $total_verified;

$saved_msg = '';
if (isset($_GET['saved'])) {
    $cnt = (int)($_GET['count'] ?? 0);
    $saved_msg = $_GET['saved'] === 'verified'
        ? "✅ {$cnt} user(s) verified successfully."
        : "🔕 {$cnt} user(s) had verification revoked.";
}

// Filter query
$q      = strtolower(trim($_GET['q'] ?? ''));
$filter = $_GET['filter'] ?? 'all';   // all | verified | unverified

$display_users = array_filter($all_users, function($u) use ($q, $filter) {
    if ($filter === 'verified'   && !($u['verified'] ?? false)) return false;
    if ($filter === 'unverified' && ($u['verified'] ?? false))  return false;
    if ($q) {
        $haystack = strtolower(($u['displayName'] ?? '') . ' ' . ($u['username'] ?? '') . ' ' . ($u['email'] ?? ''));
        if (strpos($haystack, $q) === false) return false;
    }
    return true;
});

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verification Panel — HaiFire Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<style>
/* ── Reset & Base ─────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --font:    'Inter', system-ui, sans-serif;
  --bg:      #080919;
  --surface: #0d0e2e;
  --navy:    #0d0e2e;
  --card:    #11132e;
  --card2:   #161838;
  --border:  rgba(144,144,224,.13);
  --border2: rgba(144,144,224,.22);
  --ind:     #5856d6;
  --lav:     #9090e0;
  --lav2:    #a8a8f8;
  --bolt:    #a8a8f8;
  --yellow:  #ffcc02;
  --green:   #34c779;
  --red:     #ff453a;
  --pink:    #ff3b60;
  --text:    #f0f0ff;
  --text2:   #b8b8d4;
  --muted:   #6666a0;
  --radius:  18px;
  --radius-sm: 12px;
  --shadow:  0 8px 32px rgba(0,0,0,.45);
  --glow:    0 0 20px rgba(88,86,214,.18);
}
html { font-size: 15px; }
body {
  font-family: var(--font);
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}
a { color: var(--lav2); text-decoration: none; }
a:hover { text-decoration: underline; }

/* ── Layout ──────────────────────────────────────────────── */
.shell {
  display: flex;
  min-height: 100vh;
}
.sidebar {
  width: 240px;
  flex-shrink: 0;
  background: var(--card);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  padding: 28px 0 20px;
  position: sticky;
  top: 0;
  height: 100vh;
  overflow-y: auto;
}
.sidebar-logo {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 22px 28px;
  border-bottom: 1px solid var(--border);
  margin-bottom: 16px;
}
.logo-bolt {
  width: 36px; height: 36px;
  background: var(--ind);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: var(--glow);
}
.logo-bolt .icon { color: #fff; font-size: 20px; font-variation-settings: 'FILL' 1,'wght' 600; }
.logo-text { font-size: 17px; font-weight: 900; letter-spacing: -.4px; color: var(--text); }
.logo-sub  { font-size: 10px; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: .8px; }
.nav-group { padding: 0 10px; margin-bottom: 8px; }
.nav-item {
  display: flex; align-items: center; gap: 11px;
  padding: 11px 14px; border-radius: 12px;
  font-size: 14px; font-weight: 600;
  color: var(--text2); background: none; border: none;
  width: 100%; text-align: left; cursor: pointer;
  transition: all .18s; font-family: var(--font);
  text-decoration: none;
}
.nav-item:hover  { background: rgba(144,144,224,.07); color: var(--text); }
.nav-item.active { background: rgba(88,86,214,.16); color: var(--lav2); }
.nav-item .icon  { font-size: 20px; flex-shrink: 0; font-variation-settings: 'FILL' 0,'wght' 400; }
.nav-item.active .icon { font-variation-settings: 'FILL' 1,'wght' 500; }
.nav-divider { height: 1px; background: var(--border); margin: 10px 22px; }
.sidebar-footer {
  margin-top: auto;
  padding: 16px 16px 0;
  border-top: 1px solid var(--border);
}
.sidebar-me {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 8px; border-radius: 12px;
  transition: background .18s; cursor: pointer;
}
.sidebar-me:hover { background: rgba(144,144,224,.07); }
.sidebar-me-name   { font-size: 13px; font-weight: 700; }
.sidebar-me-handle { font-size: 11px; color: var(--muted); }

.main {
  flex: 1;
  min-width: 0;
  padding: 36px 40px;
  max-width: 1100px;
}

/* ── Page header ─────────────────────────────────────────── */
.page-header {
  display: flex; align-items: flex-start; gap: 18px;
  margin-bottom: 32px; flex-wrap: wrap;
}
.page-icon {
  width: 52px; height: 52px; border-radius: 15px;
  background: rgba(255,204,2,.1);
  border: 1.5px solid rgba(255,204,2,.3);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.page-icon .icon { color: var(--yellow); font-size: 26px; font-variation-settings: 'FILL' 1,'wght' 500; }
.page-title { flex: 1; min-width: 0; }
.page-title h1 { font-size: 26px; font-weight: 900; letter-spacing: -.6px; }
.page-title p  { font-size: 13px; color: var(--muted); margin-top: 4px; }
.back-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 10px 18px; border-radius: 100px;
  background: var(--card); border: 1px solid var(--border);
  color: var(--text2); font-size: 13px; font-weight: 700;
  cursor: pointer; font-family: var(--font); transition: all .18s;
  text-decoration: none;
}
.back-btn:hover { border-color: var(--border2); color: var(--text); text-decoration: none; }

/* ── Stats row ───────────────────────────────────────────── */
.stat-row { display: flex; gap: 14px; margin-bottom: 28px; flex-wrap: wrap; }
.stat-card {
  flex: 1; min-width: 120px;
  background: var(--card); border: 1px solid var(--border);
  border-radius: var(--radius-sm); padding: 18px 20px;
  text-align: center;
}
.stat-num   { font-size: 30px; font-weight: 900; }
.stat-label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .8px; margin-top: 4px; }

/* ── Toolbar ─────────────────────────────────────────────── */
.toolbar {
  display: flex; gap: 12px; align-items: center;
  margin-bottom: 22px; flex-wrap: wrap;
}
.search-wrap { position: relative; flex: 1; min-width: 220px; }
.search-wrap .icon {
  position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
  color: var(--muted); font-size: 18px; pointer-events: none;
}
.search-input {
  width: 100%;
  background: var(--card); border: 1.5px solid var(--border);
  color: var(--text); border-radius: 100px;
  padding: 11px 20px 11px 44px;
  font-size: 14px; font-family: var(--font); outline: none;
  transition: border-color .2s;
}
.search-input:focus { border-color: var(--ind); }
.filter-pills { display: flex; gap: 6px; }
.pill {
  padding: 9px 16px; border-radius: 100px;
  font-size: 13px; font-weight: 700;
  background: var(--card); border: 1px solid var(--border);
  color: var(--muted); cursor: pointer; font-family: var(--font);
  transition: all .18s; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;
}
.pill:hover        { border-color: var(--border2); color: var(--text2); text-decoration: none; }
.pill.active       { background: rgba(88,86,214,.18); border-color: var(--ind); color: var(--lav2); }
.pill.active-green { background: rgba(52,199,121,.12); border-color: rgba(52,199,121,.4); color: var(--green); }
.pill.active-red   { background: rgba(255,69,58,.1);  border-color: rgba(255,69,58,.3);  color: var(--red); }

/* ── Alerts ──────────────────────────────────────────────── */
.alert {
  padding: 14px 18px; border-radius: var(--radius-sm);
  font-size: 14px; font-weight: 600;
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 22px;
}
.alert-success { background: rgba(52,199,121,.1); border: 1px solid rgba(52,199,121,.3); color: var(--green); }
.alert-info    { background: rgba(88,86,214,.1);  border: 1px solid rgba(88,86,214,.3);  color: var(--lav2); }
.alert-warn    { background: rgba(255,204,2,.08); border: 1px solid rgba(255,204,2,.25); color: var(--yellow); }

/* ── Bulk action bar ─────────────────────────────────────── */
.bulk-bar {
  display: none; align-items: center; gap: 12px;
  background: var(--card2); border: 1px solid var(--border2);
  border-radius: var(--radius-sm); padding: 13px 18px;
  margin-bottom: 18px; flex-wrap: wrap;
}
.bulk-bar.visible { display: flex; }
.bulk-info { flex: 1; font-size: 13px; font-weight: 700; color: var(--lav2); }

/* ── User cards ──────────────────────────────────────────── */
.user-grid { display: flex; flex-direction: column; gap: 8px; }
.user-card {
  display: flex; align-items: center; gap: 14px;
  background: var(--card); border: 1px solid var(--border);
  border-radius: var(--radius-sm); padding: 15px 18px;
  transition: border-color .2s, background .2s;
  position: relative;
}
.user-card:hover { border-color: var(--border2); background: var(--card2); }
.user-card.is-verified { border-left: 3px solid rgba(255,204,2,.4); }

.user-select {
  width: 18px; height: 18px; border-radius: 5px;
  accent-color: var(--ind); cursor: pointer; flex-shrink: 0;
}
.user-info   { flex: 1; min-width: 0; }
.user-name   {
  font-size: 15px; font-weight: 700;
  display: flex; align-items: center; gap: 7px; flex-wrap: wrap;
}
.user-meta   { font-size: 12px; color: var(--muted); margin-top: 3px; display: flex; gap: 10px; flex-wrap: wrap; }
.user-meta span { display: flex; align-items: center; gap: 3px; }
.user-actions { display: flex; gap: 8px; flex-shrink: 0; flex-wrap: wrap; }

/* Badges */
.badge {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 100px;
}
.badge-verified   { background: rgba(255,204,2,.12); color: var(--yellow); border: 1px solid rgba(255,204,2,.25); }
.badge-unverified { background: rgba(99,99,122,.1);  color: var(--muted);  border: 1px solid rgba(99,99,122,.2); }
.badge-admin      { background: rgba(88,86,214,.18); color: var(--lav2);   border: 1px solid var(--border2); }
.badge-you        { background: rgba(52,199,121,.1); color: var(--green);  border: 1px solid rgba(52,199,121,.3); }

/* Buttons */
.btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 9px 18px; border-radius: 100px;
  font-size: 13px; font-weight: 700; cursor: pointer;
  font-family: var(--font); border: none;
  transition: all .2s cubic-bezier(.4,0,.2,1);
  white-space: nowrap;
}
.btn-sm { padding: 7px 14px; font-size: 12px; }
.btn-verify   { background: rgba(255,204,2,.18); color: var(--yellow); border: 1px solid rgba(255,204,2,.3); }
.btn-verify:hover   { background: rgba(255,204,2,.3); }
.btn-unverify { background: rgba(99,99,122,.1); color: var(--muted); border: 1px solid rgba(99,99,122,.25); }
.btn-unverify:hover { background: rgba(99,99,122,.2); color: var(--text2); }
.btn-danger   { background: rgba(255,69,58,.1); color: var(--red); border: 1px solid rgba(255,69,58,.25); }
.btn-danger:hover   { background: rgba(255,69,58,.2); }
.btn-primary  { background: var(--ind); color: #fff; }
.btn-primary:hover  { background: #6a68e8; }
.btn-ghost    { background: var(--card2); color: var(--text2); border: 1px solid var(--border); }
.btn-ghost:hover    { border-color: var(--border2); color: var(--text); }
.btn:disabled { opacity: .5; cursor: not-allowed; }

/* ── Empty state ─────────────────────────────────────────── */
.empty { text-align: center; padding: 64px 24px; color: var(--muted); }
.empty .icon { font-size: 56px; opacity: .18; display: block; margin-bottom: 14px; font-variation-settings: 'FILL' 1,'wght' 300; }
.empty p { font-size: 15px; font-weight: 600; }

/* ── Toast ───────────────────────────────────────────────── */
.toast {
  position: fixed; bottom: 32px; left: 50%; transform: translateX(-50%) translateY(10px);
  background: var(--card2); border: 1px solid var(--border2); color: var(--text);
  padding: 12px 28px; border-radius: 100px; font-size: 14px; font-weight: 600;
  box-shadow: var(--shadow); z-index: 9999; opacity: 0; pointer-events: none;
  transition: all .3s; white-space: nowrap;
}
.toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* ── Access denied ───────────────────────────────────────── */
.denied {
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  text-align: center; padding: 24px;
}

/* ── Section labels ──────────────────────────────────────── */
.section-label {
  font-size: 11px; font-weight: 800; color: var(--muted);
  text-transform: uppercase; letter-spacing: .9px;
  margin: 24px 0 12px; display: flex; align-items: center; gap: 10px;
}
.section-label::after {
  content: ''; flex: 1; height: 1px; background: var(--border);
}

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 900px) {
  .sidebar { display: none; }
  .main    { padding: 24px 18px; }
}
@media (max-width: 600px) {
  .stat-row { gap: 8px; }
  .toolbar  { flex-direction: column; align-items: stretch; }
  .filter-pills { flex-wrap: wrap; }
  .user-actions { width: 100%; justify-content: flex-end; margin-top: 8px; }
  .user-card  { flex-wrap: wrap; }
}

.icon {
  font-family: 'Material Symbols Rounded';
  font-size: 20px; line-height: 1;
  font-style: normal; font-weight: normal;
  display: inline-flex; align-items: center; justify-content: center;
  font-variation-settings: 'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;
  vertical-align: middle; user-select: none;
}
.icon.sm { font-size: 16px; }
.icon.lg { font-size: 28px; }
</style>
</head>
<body>

<?php if (!logged_in()): ?>
<!-- ── Not logged in ───────────────────────────────────────── -->
<div class="denied">
  <div>
    <span class="icon lg" style="color:var(--muted);opacity:.4;display:block;margin-bottom:16px;font-size:56px">lock</span>
    <h2 style="font-size:22px;font-weight:800;margin-bottom:10px">Sign in required</h2>
    <p style="color:var(--muted);margin-bottom:24px">You must be logged in to access this panel.</p>
    <a href="index.php?page=auth" class="btn btn-primary"><span class="icon sm">login</span>Go to Login</a>
  </div>
</div>

<?php elseif (!is_admin()): ?>
<!-- ── Not admin ───────────────────────────────────────────── -->
<div class="denied">
  <div>
    <span class="icon lg" style="color:var(--red);opacity:.5;display:block;margin-bottom:16px;font-size:56px">gpp_bad</span>
    <h2 style="font-size:22px;font-weight:800;color:var(--red);margin-bottom:10px">Access Denied</h2>
    <p style="color:var(--muted);margin-bottom:24px">You don't have admin privileges to access this page.</p>
    <a href="index.php" class="btn btn-ghost"><span class="icon sm">home</span>Back to HaiFire</a>
  </div>
</div>

<?php else: ?>
<!-- ── ADMIN SHELL ─────────────────────────────────────────── -->
<div class="shell">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="logo-bolt"><span class="icon">bolt</span></div>
      <div>
        <div class="logo-text">HaiFire</div>
        <div class="logo-sub">Admin</div>
      </div>
    </div>

    <div class="nav-group">
      <a class="nav-item" href="index.php">
        <span class="icon">home</span> Back to App
      </a>
    </div>

    <div class="nav-divider"></div>
    <div style="padding: 8px 22px 6px; font-size: 10px; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: .9px;">Management</div>

    <div class="nav-group">
      <a class="nav-item active" href="verification.php">
        <span class="icon" style="color:var(--yellow);font-variation-settings:'FILL' 1,'wght' 500">verified_user</span>
        Verification
      </a>
      <a class="nav-item" href="index.php?page=admin_panel&tab=users">
        <span class="icon">group</span> All Users
      </a>
      <a class="nav-item" href="index.php?page=admin_panel&tab=posts">
        <span class="icon">article</span> All Posts
      </a>
    </div>

    <div class="sidebar-footer">
      <div class="sidebar-me" onclick="location.href='index.php?page=profile'">
        <?= av(me()['uid'], me()['displayName'], '36px') ?>
        <div style="flex:1;min-width:0">
          <div class="sidebar-me-name"><?= h(me()['displayName']) ?></div>
          <div class="sidebar-me-handle">@<?= h(me()['username']) ?></div>
        </div>
        <a href="index.php?page=logout" onclick="event.stopPropagation()" title="Logout">
          <span class="icon sm" style="color:var(--muted)">logout</span>
        </a>
      </div>
    </div>
  </aside>

  <!-- Main content -->
  <main class="main">

    <!-- Page Header -->
    <div class="page-header">
      <div class="page-icon">
        <span class="icon">verified_user</span>
      </div>
      <div class="page-title">
        <h1>Manual Verification Panel</h1>
        <p>Grant or revoke verified badges for HaiFire users.</p>
      </div>
      <a href="index.php" class="back-btn">
        <span class="icon sm">arrow_back</span> Back to App
      </a>
    </div>

    <!-- Success / info banner -->
    <?php if ($saved_msg): ?>
    <div class="alert alert-success">
      <span class="icon sm">check_circle</span> <?= h($saved_msg) ?>
    </div>
    <?php endif; ?>

    <!-- Info note -->
    <div class="alert alert-warn">
      <span class="icon sm">info</span>
      <span>Only grant verification to real identities or notable accounts. The <strong>⚡ verified badge</strong> is visible on profiles and posts across the app.</span>
    </div>

    <!-- Stats Row -->
    <div class="stat-row">
      <div class="stat-card">
        <div class="stat-num"><?= $total_users ?></div>
        <div class="stat-label">Total Users</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" style="color:var(--yellow)"><?= $total_verified ?></div>
        <div class="stat-label">Verified</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" style="color:var(--muted)"><?= $total_unverif ?></div>
        <div class="stat-label">Unverified</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" style="color:var(--ind)"><?= $total_users > 0 ? round($total_verified / $total_users * 100) : 0 ?>%</div>
        <div class="stat-label">Verified Rate</div>
      </div>
    </div>

    <!-- Toolbar -->
    <form method="GET" action="verification.php" style="margin-bottom:0">
      <div class="toolbar">
        <div class="search-wrap">
          <span class="icon">search</span>
          <input class="search-input" type="text" name="q" value="<?= h($q) ?>" placeholder="Search by name, @handle, or email…" autocomplete="off">
          <?php if($filter !== 'all'):?><input type="hidden" name="filter" value="<?= h($filter) ?>"><?php endif;?>
        </div>
        <div class="filter-pills">
          <a href="verification.php<?= $q ? '?q='.urlencode($q) : '' ?>"
             class="pill <?= $filter==='all'?'active':'' ?>">All (<?= $total_users ?>)</a>
          <a href="verification.php?filter=verified<?= $q ? '&q='.urlencode($q) : '' ?>"
             class="pill <?= $filter==='verified'?'active-green':'' ?>">
            <span class="icon sm" style="color:var(--yellow);font-variation-settings:'FILL' 1,'wght' 600">verified</span>
            Verified (<?= $total_verified ?>)
          </a>
          <a href="verification.php?filter=unverified<?= $q ? '&q='.urlencode($q) : '' ?>"
             class="pill <?= $filter==='unverified'?'active-red':'' ?>">Unverified (<?= $total_unverif ?>)</a>
        </div>
      </div>
    </form>

    <!-- Bulk action bar -->
    <div class="bulk-bar" id="bulk-bar">
      <div class="bulk-info" id="bulk-info">0 users selected</div>
      <form method="POST" action="verification.php" id="bulk-form" style="display:contents">
        <input type="hidden" name="_t" value="<?= h(csrf()) ?>">
        <div id="bulk-uid-inputs"></div>
        <button type="submit" name="bulk" value="verify_all"   class="btn btn-sm btn-verify">
          <span class="icon sm" style="font-variation-settings:'FILL' 1,'wght' 600">verified</span>Verify Selected
        </button>
        <button type="submit" name="bulk" value="unverify_all" class="btn btn-sm btn-unverify">
          <span class="icon sm">remove_moderator</span>Revoke Selected
        </button>
        <button type="button" class="btn btn-sm btn-ghost" onclick="clearSelection()">Clear</button>
      </form>
    </div>

    <!-- User List -->
    <?php if (empty($display_users)): ?>
    <div class="empty">
      <span class="icon">search_off</span>
      <p><?= $q ? 'No users match "'.h($q).'"' : 'No users found.' ?></p>
    </div>

    <?php else:
      // Split for grouped display when no filter/search
      $show_grouped = (!$q && $filter === 'all');
      $unverif_list = array_values(array_filter($display_users, fn($u)=>!($u['verified']??false)));
      $verif_list   = array_values(array_filter($display_users, fn($u)=>($u['verified']??false)));
    ?>

    <?php if ($show_grouped && !empty($unverif_list)): ?>
    <div class="section-label">
      <span class="icon sm">pending</span> Awaiting Verification (<?= count($unverif_list) ?>)
    </div>
    <div class="user-grid">
      <?php foreach ($unverif_list as $u): ?>
        <?php
          $posts_count = count(array_filter($all_posts, fn($p)=>$p['userId']===$u['uid']));
          $is_self = ($u['uid'] === me()['uid']);
          $is_first_user = ($all_users[0]['uid'] === $u['uid']);
        ?>
        <div class="user-card" id="ucard-<?= h($u['uid']) ?>">
          <input type="checkbox" class="user-select" onchange="onCheck(this,'<?= h($u['uid']) ?>')" value="<?= h($u['uid']) ?>">
          <?= av($u['uid'], $u['displayName'], '48px') ?>
          <div class="user-info">
            <div class="user-name">
              <?= h($u['displayName']) ?>
              <span class="badge badge-unverified"><span class="icon sm">remove_moderator</span>Unverified</span>
              <?php if ($is_self): ?>     <span class="badge badge-you">You</span><?php endif; ?>
              <?php if ($is_first_user): ?><span class="badge badge-admin"><span class="icon sm">admin_panel_settings</span>Admin</span><?php endif; ?>
            </div>
            <div class="user-meta">
              <span><span class="icon sm">alternate_email</span><?= h($u['username']) ?></span>
              <span><span class="icon sm">mail</span><?= h($u['email'] ?? '—') ?></span>
              <span><span class="icon sm">article</span><?= $posts_count ?> posts</span>
              <span><span class="icon sm">group</span><?= count($u['followers']??[]) ?> followers</span>
              <span><span class="icon sm">calendar_today</span>Joined <?= date('M j, Y', strtotime($u['createdAt'] ?? now())) ?></span>
            </div>
          </div>
          <div class="user-actions">
            <button class="btn btn-sm btn-verify" onclick="toggleVerify('<?= h($u['uid']) ?>','verify',this)">
              <span class="icon sm" style="font-variation-settings:'FILL' 1,'wght' 600">verified</span>Grant Verification
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($show_grouped && !empty($verif_list)): ?>
    <div class="section-label" style="margin-top:<?= !empty($unverif_list)?'28px':'0' ?>">
      <span class="icon sm" style="color:var(--yellow);font-variation-settings:'FILL' 1,'wght' 600">verified</span>
      Verified Users (<?= count($verif_list) ?>)
    </div>
    <?php endif; ?>

    <?php
      // Which list to show in the grid
      $grid_users = $show_grouped ? $verif_list : array_values($display_users);
    ?>
    <?php if (!empty($grid_users)): ?>
    <div class="user-grid">
      <?php foreach ($grid_users as $u):
        $posts_count = count(array_filter($all_posts, fn($p)=>$p['userId']===$u['uid']));
        $is_self     = ($u['uid'] === me()['uid']);
        $is_admin_u  = ($all_users[0]['uid'] === $u['uid']) || ($u['username'] === 'admin');
        $is_verif    = $u['verified'] ?? false;
      ?>
      <div class="user-card <?= $is_verif ? 'is-verified' : '' ?>" id="ucard-<?= h($u['uid']) ?>">
        <input type="checkbox" class="user-select" onchange="onCheck(this,'<?= h($u['uid']) ?>')" value="<?= h($u['uid']) ?>">
        <?= av($u['uid'], $u['displayName'], '48px') ?>
        <div class="user-info">
          <div class="user-name">
            <?= h($u['displayName']) ?>
            <?php if ($is_verif): ?>
              <span class="badge badge-verified" id="badge-<?= h($u['uid']) ?>"><span class="icon sm" style="font-variation-settings:'FILL' 1,'wght' 600">verified</span>Verified</span>
            <?php else: ?>
              <span class="badge badge-unverified" id="badge-<?= h($u['uid']) ?>"><span class="icon sm">remove_moderator</span>Unverified</span>
            <?php endif; ?>
            <?php if ($is_self):  ?><span class="badge badge-you">You</span><?php endif; ?>
            <?php if ($is_admin_u): ?><span class="badge badge-admin"><span class="icon sm">admin_panel_settings</span>Admin</span><?php endif; ?>
          </div>
          <div class="user-meta">
            <span><span class="icon sm">alternate_email</span><?= h($u['username']) ?></span>
            <span><span class="icon sm">mail</span><?= h($u['email'] ?? '—') ?></span>
            <span><span class="icon sm">article</span><?= $posts_count ?> posts</span>
            <span><span class="icon sm">group</span><?= count($u['followers']??[]) ?> followers</span>
            <span><span class="icon sm">calendar_today</span>Joined <?= date('M j, Y', strtotime($u['createdAt'] ?? now())) ?></span>
          </div>
        </div>
        <div class="user-actions" id="actions-<?= h($u['uid']) ?>">
          <?php if ($is_verif): ?>
          <button class="btn btn-sm btn-unverify" onclick="toggleVerify('<?= h($u['uid']) ?>','unverify',this)">
            <span class="icon sm">remove_moderator</span>Revoke
          </button>
          <?php else: ?>
          <button class="btn btn-sm btn-verify" onclick="toggleVerify('<?= h($u['uid']) ?>','verify',this)">
            <span class="icon sm" style="font-variation-settings:'FILL' 1,'wght' 600">verified</span>Verify
          </button>
          <?php endif; ?>
          <a href="index.php?page=user_profile&uid=<?= h($u['uid']) ?>" class="btn btn-sm btn-ghost" target="_blank">
            <span class="icon sm">open_in_new</span>Profile
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($grid_users) && empty($unverif_list)): ?>
    <div class="empty"><span class="icon">search_off</span><p>No users match the current filter.</p></div>
    <?php endif; ?>

    <?php endif; // end display_users check ?>

  </main>
</div><!-- /shell -->

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
const CSRF_T = '<?= h(csrf()) ?>';
let selected = new Set();

// ─── Toggle single verify / unverify ─────────────────────────
async function toggleVerify(uid, action, btn) {
  const original = btn.innerHTML;
  btn.disabled   = true;
  btn.innerHTML  = '<span style="display:inline-block;width:14px;height:14px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle"></span>';

  const fd = new FormData();
  fd.append('_t',         CSRF_T);
  fd.append('target_uid', uid);
  fd.append('action',     action);

  try {
    const r = await fetch('verification.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) throw new Error('Server error');

    const card   = document.getElementById('ucard-' + uid);
    const badge  = document.getElementById('badge-' + uid);
    const actions = document.getElementById('actions-' + uid);

    if (d.verified) {
      // Update badge
      if (badge) {
        badge.className   = 'badge badge-verified';
        badge.innerHTML   = '<span class="icon sm" style="font-variation-settings:\'FILL\' 1,\'wght\' 600">verified</span>Verified';
      }
      // Update card border
      if (card) card.classList.add('is-verified');
      // Swap button
      if (actions) {
        const newBtn = actions.querySelector('button');
        if (newBtn) {
          newBtn.className = 'btn btn-sm btn-unverify';
          newBtn.innerHTML = '<span class="icon sm">remove_moderator</span>Revoke';
          newBtn.setAttribute('onclick', "toggleVerify('" + uid + "','unverify',this)");
          newBtn.disabled  = false;
        }
      }
      showToast('✅ Verified — badge granted!');
    } else {
      if (badge) {
        badge.className   = 'badge badge-unverified';
        badge.innerHTML   = '<span class="icon sm">remove_moderator</span>Unverified';
      }
      if (card) card.classList.remove('is-verified');
      if (actions) {
        const newBtn = actions.querySelector('button');
        if (newBtn) {
          newBtn.className = 'btn btn-sm btn-verify';
          newBtn.innerHTML = '<span class="icon sm" style="font-variation-settings:\'FILL\' 1,\'wght\' 600">verified</span>Verify';
          newBtn.setAttribute('onclick', "toggleVerify('" + uid + "','verify',this)");
          newBtn.disabled  = false;
        }
      }
      showToast('🔕 Verification revoked');
    }
  } catch(e) {
    btn.disabled  = false;
    btn.innerHTML = original;
    showToast('❌ Error — please try again');
  }
}

// ─── Checkbox / bulk selection ────────────────────────────────
function onCheck(cb, uid) {
  if (cb.checked) selected.add(uid);
  else            selected.delete(uid);
  updateBulkBar();
}

function updateBulkBar() {
  const bar  = document.getElementById('bulk-bar');
  const info = document.getElementById('bulk-info');
  const cont = document.getElementById('bulk-uid-inputs');
  if (selected.size > 0) {
    bar.classList.add('visible');
    info.textContent = selected.size + ' user' + (selected.size > 1 ? 's' : '') + ' selected';
    cont.innerHTML   = [...selected].map(u => '<input type="hidden" name="uids[]" value="' + u + '">').join('');
  } else {
    bar.classList.remove('visible');
    cont.innerHTML = '';
  }
}

function clearSelection() {
  selected.clear();
  document.querySelectorAll('.user-select').forEach(cb => cb.checked = false);
  updateBulkBar();
}

// Select all visible
document.addEventListener('DOMContentLoaded', () => {
  // Keyboard: Ctrl+A selects all visible checkboxes
  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'a' && document.activeElement.tagName !== 'INPUT') {
      e.preventDefault();
      document.querySelectorAll('.user-select').forEach(cb => {
        cb.checked = true;
        selected.add(cb.value);
      });
      updateBulkBar();
    }
  });
});

// ─── Toast ────────────────────────────────────────────────────
let _tt;
function showToast(msg, dur = 3200) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(_tt);
  _tt = setTimeout(() => t.classList.remove('show'), dur);
}

// ─── Spin keyframe ─────────────────────────────────────────── */
const style = document.createElement('style');
style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(style);
</script>

<?php endif; // end logged_in / is_admin ?>
</body>
</html>