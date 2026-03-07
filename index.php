<?php
// ============================================================
//  HaiFlash — "Ignite Your Story"
//  Single-file Social Media WebApp  |  index.php
//  UI: v2 Design System  |  Logic: v1 Full Feature Set
// ============================================================

session_start();
session_regenerate_id(false);

// ── Data paths ───────────────────────────────────────────────
define('DATA_DIR',    __DIR__ . '/data/');
define('USERS_FILE',  DATA_DIR . 'users.json');
define('POSTS_FILE',  DATA_DIR . 'posts.json');
define('NOTIF_FILE',  DATA_DIR . 'notifications.json');
define('FOLLOW_FILE', DATA_DIR . 'follow_requests.json');
define('DM_FILE',     DATA_DIR . 'messages.json');
define('CMNT_FILE',   DATA_DIR . 'comments.json');
define('AVATAR_DIR',  DATA_DIR . 'avatars/');
define('MEDIA_DIR',   DATA_DIR . 'media/');
define('AVATAR_URL',  'data/avatars/');
define('MEDIA_URL',   'data/media/');

// ── Bootstrap ────────────────────────────────────────────────
foreach ([DATA_DIR, AVATAR_DIR, MEDIA_DIR] as $d) {
    if (!is_dir($d)) mkdir($d, 0755, true);
}
foreach ([USERS_FILE, POSTS_FILE, NOTIF_FILE, FOLLOW_FILE, DM_FILE, CMNT_FILE] as $f) {
    if (!file_exists($f)) file_put_contents($f, '[]', LOCK_EX);
}

// ── Core Helpers ─────────────────────────────────────────────
function rj(string $f): array {
    $fp = fopen($f, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $data = json_decode(stream_get_contents($fp), true) ?? [];
    flock($fp, LOCK_UN);
    fclose($fp);
    return $data;
}
function wj(string $f, array $d): void {
    $fp = fopen($f, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    flock($fp, LOCK_UN);
    fclose($fp);
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function uid(): string { return uniqid('hf_', true); }
function now(): string { return date('c'); }
function logged_in(): bool { return !empty($_SESSION['uid']); }
function me(): array {
    return $_SESSION['user'] ?? ['uid'=>'','displayName'=>'','username'=>'','photoURL'=>'','coverURL'=>'','bio'=>'','followers'=>[],'following'=>[],'bookmarks'=>[],'postNotifs'=>[],'verified'=>false];
}
function time_ago(string $ts): string {
    $d = time() - strtotime($ts);
    if ($d < 60)     return $d . 's';
    if ($d < 3600)   return floor($d/60) . 'm';
    if ($d < 86400)  return floor($d/3600) . 'h';
    if ($d < 604800) return floor($d/86400) . 'd';
    return date('M j', strtotime($ts));
}
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function vcsrf(): void {
    if (($_POST['_t'] ?? '') !== ($_SESSION['csrf'] ?? '')) { http_response_code(403); die('Bad token'); }
}
function get_user_by_uid(string $uid): ?array {
    foreach (rj(USERS_FILE) as $u) { if ($u['uid'] === $uid) return $u; }
    return null;
}
function save_user_to_session(array $u): void {
    $_SESSION['uid']  = $u['uid'];
    $_SESSION['user'] = $u;
}
function update_user_fields(string $uid, array $fields): void {
    $users = rj(USERS_FILE);
    foreach ($users as &$u) {
        if ($u['uid'] === $uid) { $u = array_merge($u, $fields); $_SESSION['user'] = $u; break; }
    }
    wj(USERS_FILE, $users);
}
function push_notif(string $for_uid, string $type, string $from_uid, string $from_name, string $ref_id = ''): void {
    if ($for_uid === $from_uid) return;
    $notifs = rj(NOTIF_FILE);
    $notifs[] = ['id'=>uid(),'for'=>$for_uid,'type'=>$type,'from_uid'=>$from_uid,'from_name'=>$from_name,'ref_id'=>$ref_id,'read'=>false,'createdAt'=>now()];
    wj(NOTIF_FILE, $notifs);
}
function av(string $uid, string $name, string $size='40px', string $cls=''): string {
    $u     = $uid ? get_user_by_uid($uid) : null;
    $photo = $u['photoURL'] ?? '';
    $i     = strtoupper(mb_substr($name ?: '?', 0, 1));
    $fs    = 'calc('.$size.' * 0.38)';
    if ($photo && file_exists(AVATAR_DIR.basename($photo))) {
        $src = h(AVATAR_URL.basename($photo));
        return "<div class=\"av-wrap {$cls}\" style=\"width:{$size};height:{$size};border-radius:50%;flex-shrink:0;overflow:hidden\"><img src=\"{$src}\" style=\"width:100%;height:100%;object-fit:cover\" alt=\"\"></div>";
    }
    return "<div class=\"av-wrap {$cls}\" style=\"width:{$size};height:{$size};border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--navy) 0%,#1a1850 100%);border:1.5px solid var(--lav);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:{$fs};color:var(--bolt)\">{$i}</div>";
}

// ── Admin check ──────────────────────────────────────────────
function is_admin(): bool {
    if (!logged_in()) return false;
    $u = me();
    // First registered user OR username === 'admin' is the admin
    $all = rj(USERS_FILE);
    if (empty($all)) return false;
    return ($u['username'] === 'admin') || ($all[0]['uid'] === $u['uid']);
}

// ── Unread counts ─────────────────────────────────────────────
$unread_notifs = 0; $unread_dms = 0;
if (logged_in()) {
    foreach (rj(NOTIF_FILE) as $n) { if ($n['for']===$_SESSION['uid'] && !$n['read']) $unread_notifs++; }
    foreach (rj(DM_FILE) as $conv) {
        if (!in_array($_SESSION['uid'], $conv['participants'])) continue;
        foreach (($conv['messages']??[]) as $msg) {
            if ($msg['from']!==$_SESSION['uid'] && !($msg['read']??false)) $unread_dms++;
        }
    }
}

// ── Routing ───────────────────────────────────────────────────
$page   = $_GET['page'] ?? (logged_in() ? 'home' : 'auth');
$method = $_SERVER['REQUEST_METHOD'];
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

if (!logged_in() && !in_array($page, ['auth'])) {
    header('Location: ?page=auth'); exit;
}

// ═══════════════════════════════════════════════════════════
//  POST ACTION HANDLERS
// ═══════════════════════════════════════════════════════════

// ── Auth ─────────────────────────────────────────────────────
if ($method==='POST' && $page==='auth') {
    vcsrf();
    $mode=$_POST['mode']??'login'; $email=strtolower(trim($_POST['email']??''));
    $password=$_POST['password']??''; $username=trim(ltrim($_POST['username']??'','@'));
    $name=trim($_POST['display_name']??''); $errors=[];
    if ($mode==='signup') {
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) $errors[]='Invalid email.';
        if (strlen($password)<6) $errors[]='Password min 6 chars.';
        if (!preg_match('/^[a-zA-Z0-9_.]{2,30}$/',$username)) $errors[]='Username: 2-30 chars, letters/numbers/._';
        if (!$name) $errors[]='Display name required.';
        if (!$errors) {
            $users=rj(USERS_FILE);
            foreach ($users as $u) {
                if ($u['email']===$email){$errors[]='Email already registered.';break;}
                if ($u['username']===$username){$errors[]='Username taken.';break;}
            }
        }
        if (!$errors) {
            $new=['uid'=>uid(),'email'=>$email,'password'=>password_hash($password,PASSWORD_DEFAULT),
                  'username'=>$username,'displayName'=>$name,'photoURL'=>'','coverURL'=>'','bio'=>'',
                  'verified'=>false,'followers'=>[],'following'=>[],'bookmarks'=>[],'postNotifs'=>[],'createdAt'=>now()];
            $users=rj(USERS_FILE); $users[]=$new; wj(USERS_FILE,$users);
            save_user_to_session($new); $_SESSION['is_new']=true;
            header('Location: ?page=setup'); exit;
        }
    } else {
        $users=rj(USERS_FILE); $found=null;
        foreach ($users as $u) { if ($u['email']===$email && password_verify($password,$u['password'])){$found=$u;break;} }
        if ($found) { save_user_to_session($found); header('Location: ?page=home'); exit; }
        else $errors[]='Invalid email or password.';
    }
    $auth_mode=$mode; $auth_errors=$errors;
}

// ── Profile Setup ────────────────────────────────────────────
if ($method==='POST' && $page==='setup' && logged_in()) {
    vcsrf();
    $bio=mb_substr(trim($_POST['bio']??''),0,160); $dname=mb_substr(trim($_POST['display_name']??me()['displayName']),0,50);
    $photo='';
    if (!empty($_FILES['avatar']['tmp_name']) && $_FILES['avatar']['error']===UPLOAD_ERR_OK) {
        $f=$_FILES['avatar']; $mime=mime_content_type($f['tmp_name']);
        $ok=['image/jpeg','image/png','image/webp','image/gif'];
        if (in_array($mime,$ok) && $f['size']<=4*1024*1024) {
            $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$mime];
            $fn='av_'.md5($_SESSION['uid'].time()).'.'.$ext;
            move_uploaded_file($f['tmp_name'],AVATAR_DIR.$fn); $photo=$fn;
        }
    }
    $upd=['displayName'=>$dname,'bio'=>$bio]; if ($photo) $upd['photoURL']=$photo;
    update_user_fields($_SESSION['uid'],$upd); header('Location: ?page=home'); exit;
}

// ── Create Post ──────────────────────────────────────────────
if ($method==='POST' && $page==='create_post' && logged_in()) {
    vcsrf();
    $content=mb_substr(trim($_POST['content']??''),0,500);
    $imgurl=trim($_POST['image_url']??'');
    if ($content || $imgurl) {
        $u=me(); $local_img='';
        if (!empty($_FILES['post_img']['tmp_name']) && $_FILES['post_img']['error']===UPLOAD_ERR_OK) {
            $f=$_FILES['post_img']; $mime=mime_content_type($f['tmp_name']);
            $ok=['image/jpeg','image/png','image/webp','image/gif'];
            if (in_array($mime,$ok) && $f['size']<=8*1024*1024) {
                $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$mime];
                $fn='post_'.md5($u['uid'].time()).'.'.$ext;
                move_uploaded_file($f['tmp_name'],MEDIA_DIR.$fn); $local_img=MEDIA_URL.$fn;
            }
        }
        $post=['id'=>uid(),'userId'=>$u['uid'],'username'=>$u['username'],'displayName'=>$u['displayName'],
               'content'=>$content,'imageURL'=>$local_img?:$imgurl,'likes'=>[],'commentCount'=>0,
               'bookmarks'=>[],'createdAt'=>now()];
        $posts=rj(POSTS_FILE); array_unshift($posts,$post); wj(POSTS_FILE,$posts);
        foreach (rj(USERS_FILE) as $sub) {
            if ($sub['uid']===$u['uid']) continue;
            if (in_array($u['uid'],$sub['following']??[]) || in_array($u['uid'],$sub['postNotifs']??[])) {
                push_notif($sub['uid'],'post',$u['uid'],$u['displayName'],$post['id']);
            }
        }
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'id'=>$post['id']]); exit; }
        header('Location: ?page=home&posted=1'); exit;
    }
}

// ── Like Post ────────────────────────────────────────────────
if ($method==='POST' && $page==='like_post' && logged_in()) {
    $pid=trim($_POST['post_id']??''); $posts=rj(POSTS_FILE); $liked=false; $count=0;
    foreach ($posts as &$p) {
        if ($p['id']===$pid) {
            $me=$_SESSION['uid'];
            if (in_array($me,$p['likes'])) { $p['likes']=array_values(array_filter($p['likes'],fn($l)=>$l!==$me)); }
            else { $p['likes'][]=$me; $liked=true; push_notif($p['userId'],'like',$me,me()['displayName'],$pid); }
            $count=count($p['likes']); break;
        }
    }
    wj(POSTS_FILE,$posts); echo json_encode(['ok'=>true,'liked'=>$liked,'count'=>$count]); exit;
}

// ── Bookmark ─────────────────────────────────────────────────
if ($method==='POST' && $page==='bookmark' && logged_in()) {
    $pid=trim($_POST['post_id']??''); $users=rj(USERS_FILE); $saved=false;
    foreach ($users as &$u) {
        if ($u['uid']===$_SESSION['uid']) {
            if (in_array($pid,$u['bookmarks']??[])) { $u['bookmarks']=array_values(array_filter($u['bookmarks'],fn($b)=>$b!==$pid)); }
            else { $u['bookmarks'][]=$pid; $saved=true; }
            $_SESSION['user']=$u; break;
        }
    }
    wj(USERS_FILE,$users); echo json_encode(['ok'=>true,'saved'=>$saved]); exit;
}

// ── Follow ───────────────────────────────────────────────────
if ($method==='POST' && $page==='follow' && logged_in()) {
    $tuid=trim($_POST['target_uid']??''); $me_uid=$_SESSION['uid'];
    if ($tuid && $tuid!==$me_uid) {
        $users=rj(USERS_FILE); $action='';
        foreach ($users as &$u) {
            if ($u['uid']===$me_uid) {
                if (in_array($tuid,$u['following']??[])) {
                    $u['following']=array_values(array_filter($u['following'],fn($f)=>$f!==$tuid));
                    $action='unfollowed';
                } else { $u['following'][]=$tuid; $action='followed'; }
                $_SESSION['user']=$u; break;
            }
        }
        foreach ($users as &$u) {
            if ($u['uid']===$tuid) {
                if ($action==='followed') { if (!in_array($me_uid,$u['followers']??[])) $u['followers'][]=$me_uid; }
                else { $u['followers']=array_values(array_filter($u['followers']??[],fn($f)=>$f!==$me_uid)); }
                break;
            }
        }
        wj(USERS_FILE,$users);
        if ($action==='followed') push_notif($tuid,'follow',$me_uid,me()['displayName']);
        echo json_encode(['ok'=>true,'action'=>$action]);
    } else echo json_encode(['ok'=>false]);
    exit;
}

// ── Comment ──────────────────────────────────────────────────
if ($method==='POST' && $page==='comment' && logged_in()) {
    vcsrf();
    $pid=trim($_POST['post_id']??''); $body=mb_substr(trim($_POST['body']??''),0,280);
    if ($pid && $body) {
        $u=me();
        $cmnts=rj(CMNT_FILE);
        $cmnts[]=['id'=>uid(),'postId'=>$pid,'userId'=>$u['uid'],'username'=>$u['username'],
                  'displayName'=>$u['displayName'],'body'=>$body,'createdAt'=>now()]; // FIXED: was $now()
        wj(CMNT_FILE,$cmnts);
        $posts=rj(POSTS_FILE);
        foreach ($posts as &$p) { if ($p['id']===$pid) { push_notif($p['userId'],'comment',$u['uid'],$u['displayName'],$pid); break; } }
    }
    header('Location: ?page=post&id='.urlencode($pid)); exit;
}

// ── Delete Post ──────────────────────────────────────────────
if ($method==='POST' && $page==='delete_post' && logged_in()) {
    vcsrf();
    $pid=trim($_POST['post_id']??'');
    $posts=rj(POSTS_FILE);
    $posts=array_values(array_filter($posts,fn($p)=>!($p['id']===$pid && $p['userId']===$_SESSION['uid'])));
    wj(POSTS_FILE,$posts); echo json_encode(['ok'=>true]); exit;
}

// ── Send DM ──────────────────────────────────────────────────
if ($method==='POST' && $page==='send_dm' && logged_in()) {
    vcsrf();
    $to=trim($_POST['to_uid']??''); $body=mb_substr(trim($_POST['body']??''),0,500);
    if ($to && $body) {
        $me_uid=$_SESSION['uid']; $convs=rj(DM_FILE); $found=false;
        $msg=['id'=>uid(),'from'=>$me_uid,'body'=>$body,'read'=>false,'createdAt'=>now()];
        foreach ($convs as &$c) {
            if (in_array($me_uid,$c['participants']) && in_array($to,$c['participants'])) {
                $c['messages'][]=$msg; $c['lastMessage']=$body; $c['lastMessageTime']=now(); $found=true; break;
            }
        }
        if (!$found) $convs[]=['id'=>uid(),'participants'=>[$me_uid,$to],'messages'=>[$msg],'lastMessage'=>$body,'lastMessageTime'=>now()];
        wj(DM_FILE,$convs);
        if ($is_ajax) { echo json_encode(['ok'=>true,'msg'=>$msg]); exit; }
        header('Location: ?page=dm&uid='.$to); exit;
    }
}

// ── Mark Notifications Read ──────────────────────────────────
if ($method==='POST' && $page==='mark_notifs' && logged_in()) {
    $notifs=rj(NOTIF_FILE);
    foreach ($notifs as &$n) { if ($n['for']===$_SESSION['uid']) $n['read']=true; }
    wj(NOTIF_FILE,$notifs); echo json_encode(['ok'=>true]); exit;
}

// ── Toggle Post Notification ─────────────────────────────────
if ($method==='POST' && $page==='toggle_post_notif' && logged_in()) {
    $tuid=trim($_POST['target_uid']??''); $subscribed=false;
    if ($tuid && $tuid!==$_SESSION['uid']) {
        $users=rj(USERS_FILE);
        foreach ($users as &$u) {
            if ($u['uid']===$_SESSION['uid']) {
                $pn=$u['postNotifs']??[];
                if (in_array($tuid,$pn)) $u['postNotifs']=array_values(array_filter($pn,fn($n)=>$n!==$tuid));
                else { $u['postNotifs'][]=$tuid; $subscribed=true; }
                $_SESSION['user']=$u; break;
            }
        }
        wj(USERS_FILE,$users);
    }
    echo json_encode(['ok'=>true,'subscribed'=>$subscribed]); exit;
}

// ── Edit Profile ─────────────────────────────────────────────
if ($method==='POST' && $page==='edit_profile' && logged_in()) {
    vcsrf();
    $bio=mb_substr(trim($_POST['bio']??''),0,160); $dname=mb_substr(trim($_POST['display_name']??me()['displayName']),0,50);
    $photo=''; $cover=''; $ok_mimes=['image/jpeg','image/png','image/webp','image/gif'];
    if (!empty($_FILES['avatar']['tmp_name']) && $_FILES['avatar']['error']===UPLOAD_ERR_OK) {
        $f=$_FILES['avatar']; $mime=mime_content_type($f['tmp_name']);
        if (in_array($mime,$ok_mimes) && $f['size']<=4*1024*1024) {
            $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$mime];
            $fn='av_'.md5($_SESSION['uid'].time()).'.'.$ext;
            move_uploaded_file($f['tmp_name'],AVATAR_DIR.$fn); $photo=$fn;
        }
    }
    if (!empty($_FILES['cover']['tmp_name']) && $_FILES['cover']['error']===UPLOAD_ERR_OK) {
        $f=$_FILES['cover']; $mime=mime_content_type($f['tmp_name']);
        if (in_array($mime,$ok_mimes) && $f['size']<=8*1024*1024) {
            $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$mime];
            $fn='cover_'.md5($_SESSION['uid'].time()).'.'.$ext;
            move_uploaded_file($f['tmp_name'],MEDIA_DIR.$fn); $cover=MEDIA_URL.$fn;
        }
    }
    $upd=['displayName'=>$dname,'bio'=>$bio];
    if ($photo) $upd['photoURL']=$photo;
    if ($cover) $upd['coverURL']=$cover;
    update_user_fields($_SESSION['uid'],$upd); header('Location: ?page=profile'); exit;
}

// ── Change Password ──────────────────────────────────────────
if ($method==='POST' && $page==='change_password' && logged_in()) {
    vcsrf();
    $old=$_POST['old_pw']??''; $new=$_POST['new_pw']??'';
    $u=get_user_by_uid($_SESSION['uid']);
    if (password_verify($old,$u['password']) && strlen($new)>=6) {
        update_user_fields($_SESSION['uid'],['password'=>password_hash($new,PASSWORD_DEFAULT)]);
        $pw_success='Password updated successfully.';
    } else { $pw_error=strlen($new)<6?'New password too short (min 6).':'Wrong current password.'; }
}

// ── Delete Account ───────────────────────────────────────────
if ($method==='POST' && $page==='delete_account' && logged_in()) {
    vcsrf();
    if (trim($_POST['confirm']??'')===me()['username']) {
        $uid=$_SESSION['uid'];
        wj(USERS_FILE,array_values(array_filter(rj(USERS_FILE),fn($u)=>$u['uid']!==$uid)));
        wj(POSTS_FILE,array_values(array_filter(rj(POSTS_FILE),fn($p)=>$p['userId']!==$uid)));
        session_destroy(); header('Location: ?page=auth'); exit;
    } else $del_error='Username did not match.';
}

// ── Logout ───────────────────────────────────────────────────
if ($page==='logout') { session_destroy(); header('Location: ?page=auth'); exit; }

// ── Poll Feed (AJAX) ─────────────────────────────────────────
if ($page==='poll_feed' && logged_in()) {
    $me_data=get_user_by_uid($_SESSION['uid'])??me();
    $subs=$me_data['postNotifs']??[];
    $out=[];
    foreach (rj(POSTS_FILE) as $p) {
        $pu=get_user_by_uid($p['userId']);
        $out[]=['id'=>$p['id'],'userId'=>$p['userId'],'displayName'=>$p['displayName'],'username'=>$p['username'],
                'body'=>$p['content'],'imageURL'=>$p['imageURL']??'','likes'=>count($p['likes']),
                'liked'=>in_array($_SESSION['uid'],$p['likes']),'avatar_file'=>$pu?($pu['photoURL']??''):'',
                'subscribed'=>in_array($p['userId'],$subs),'timestamp'=>$p['createdAt']];
    }
    header('Content-Type: application/json'); echo json_encode(['posts'=>$out]); exit;
}

// ── Read DMs ─────────────────────────────────────────────────
if ($method==='POST' && $page==='read_dm' && logged_in()) {
    $to=trim($_POST['to_uid']??''); $convs=rj(DM_FILE);
    foreach ($convs as &$c) {
        if (in_array($_SESSION['uid'],$c['participants']) && in_array($to,$c['participants'])) {
            foreach ($c['messages'] as &$m) { if ($m['from']!==$_SESSION['uid']) $m['read']=true; }
        }
    }
    wj(DM_FILE,$convs); echo json_encode(['ok'=>true]); exit;
}

// ── Respond Follow ───────────────────────────────────────────
if ($method==='POST' && $page==='respond_follow' && logged_in()) {
    vcsrf();
    $rid=trim($_POST['req_id']??''); $action=trim($_POST['action']??'');
    $reqs=rj(FOLLOW_FILE);
    foreach ($reqs as &$r) {
        if ($r['id']===$rid && $r['to']===$_SESSION['uid']) {
            $r['status']=$action==='accept'?'accepted':'declined';
            if ($action==='accept') {
                $users=rj(USERS_FILE);
                foreach ($users as &$u) {
                    if ($u['uid']===$r['from'] && !in_array($r['to'],$u['following']??[])) $u['following'][]=$r['to'];
                    if ($u['uid']===$r['to']   && !in_array($r['from'],$u['followers']??[])) $u['followers'][]=$r['from'];
                }
                wj(USERS_FILE,$users);
                push_notif($r['from'],'follow',$r['to'],me()['displayName']);
            }
            break;
        }
    }
    wj(FOLLOW_FILE,$reqs); header('Location: ?page=requests'); exit;
}

// ── Admin: Toggle Verification ───────────────────────────────
if ($method==='POST' && $page==='admin_verify' && is_admin()) {
    vcsrf();
    $tuid   = trim($_POST['target_uid']??'');
    $action = trim($_POST['action']??'');   // 'verify' | 'unverify'
    if ($tuid && in_array($action,['verify','unverify'])) {
        $users = rj(USERS_FILE);
        foreach ($users as &$u) {
            if ($u['uid'] === $tuid) {
                $u['verified'] = ($action === 'verify');
                // Refresh session if admin is modifying their own record
                if ($tuid === $_SESSION['uid']) $_SESSION['user'] = $u;
                break;
            }
        }
        wj(USERS_FILE, $users);
        if ($is_ajax) {
            echo json_encode(['ok'=>true,'verified'=>($action==='verify')]);
            exit;
        }
        header('Location: ?page=admin_panel&saved=1'); exit;
    }
    header('Location: ?page=admin_panel'); exit;
}

// ── Admin: Delete Any Post ────────────────────────────────────
if ($method==='POST' && $page==='admin_delete_post' && is_admin()) {
    vcsrf();
    $pid   = trim($_POST['post_id']??'');
    $posts = rj(POSTS_FILE);
    $posts = array_values(array_filter($posts, fn($p)=>$p['id']!==$pid));
    wj(POSTS_FILE, $posts);
    if ($is_ajax) { echo json_encode(['ok'=>true]); exit; }
    header('Location: ?page=admin_panel&tab=posts&saved=1'); exit;
}

// ── Admin: Delete Any User ────────────────────────────────────
if ($method==='POST' && $page==='admin_delete_user' && is_admin()) {
    vcsrf();
    $tuid = trim($_POST['target_uid']??'');
    if ($tuid && $tuid !== $_SESSION['uid']) {
        wj(USERS_FILE, array_values(array_filter(rj(USERS_FILE), fn($u)=>$u['uid']!==$tuid)));
        wj(POSTS_FILE, array_values(array_filter(rj(POSTS_FILE), fn($p)=>$p['userId']!==$tuid)));
        wj(CMNT_FILE,  array_values(array_filter(rj(CMNT_FILE),  fn($c)=>$c['userId']!==$tuid)));
    }
    if ($is_ajax) { echo json_encode(['ok'=>true]); exit; }
    header('Location: ?page=admin_panel&saved=1'); exit;
}

// ── Pre-load for views ────────────────────────────────────────
$all_posts  = rj(POSTS_FILE);
$all_notifs = rj(NOTIF_FILE);
$all_users  = rj(USERS_FILE);
$all_convs  = rj(DM_FILE);
$all_reqs   = rj(FOLLOW_FILE);
$all_cmnts  = rj(CMNT_FILE);

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
<title>HaiFlash</title>
<link rel="icon" type="image/x-icon" href="favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════
   HAIFIRE — Lightning Design System v2.1
   UI: v2 refined aesthetic
   Features: v1 complete feature set
═══════════════════════════════════════════════════ */
:root {
  --bg:      #05060b;
  --surface: #09091a;
  --card:    #0e0f1e;
  --card2:   #14152a;
  --border:  rgba(144,144,224,0.13);
  --border2: rgba(144,144,224,0.28);
  --ind:     #5856d6;
  --ind2:    #4240b0;
  --lav:     #9090e0;
  --lav2:    #c0c0f8;
  --bolt:    #a8a8f8;
  --navy:    #0d0e2e;
  --pink:    #ff3b60;
  --green:   #34c77a;
  --yellow:  #ffcc02;
  --red:     #ff453a;
  --text:    #f0f0f8;
  --text2:   #a8a8c0;
  --muted:   #58587a;
  --radius:  20px;
  --radius-sm: 14px;
  --radius-xs: 9px;
  --nav-w:   272px;
  --top-h:   60px;
  --tab-h:   68px;
  --shadow:  0 12px 48px rgba(0,0,0,0.65);
  --glow:    0 0 24px rgba(144,144,224,0.18);
  --font:    'Inter', system-ui, -apple-system, sans-serif;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
  height: 100%; background: var(--bg); color: var(--text);
  font-family: var(--font); font-size: 15px; line-height: 1.5;
  overflow-x: hidden; -webkit-tap-highlight-color: transparent;
}
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 10px; }
a { color: inherit; text-decoration: none; }
img { display: block; max-width: 100%; }
button { font-family: var(--font); border: none; background: none; cursor: pointer; }

/* ── Icons ──────────────────────────── */
.icon {
  font-family: 'Material Symbols Outlined'; font-size: 23px;
  display: inline-flex; align-items: center; justify-content: center;
  user-select: none; line-height: 1;
  font-variation-settings: 'FILL' 0, 'wght' 300, 'GRAD' 0, 'opsz' 24;
}
.icon.filled { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
.icon.sm  { font-size: 18px; }
.icon.lg  { font-size: 28px; }
.icon.xl  { font-size: 44px; }

/* ── Sidebar ─────────────────────────── */
.sidebar {
  width: var(--nav-w); position: fixed; top: 0; left: 0; bottom: 0;
  background: var(--surface); border-right: 1px solid var(--border);
  display: flex; flex-direction: column; z-index: 90;
  padding: 28px 12px 18px;
}
.sidebar-logo { padding: 0 16px 32px; display: flex; align-items: center; gap: 12px; }
.logo-bolt {
  width: 42px; height: 42px; background: var(--navy); border-radius: 13px;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
  box-shadow: 0 0 0 2.5px var(--lav), var(--glow);
}
.logo-bolt .icon { color: var(--bolt); font-size: 22px; font-variation-settings: 'FILL' 1, 'wght' 600, 'GRAD' 0, 'opsz' 24; }
.logo-text { font-size: 20px; font-weight: 800; color: var(--text); letter-spacing: -.5px; }
.logo-sub  { font-size: 11px; color: var(--muted); margin-top: 1px; }

.nav-list { flex: 1; display: flex; flex-direction: column; gap: 2px; }
.nav-item {
  display: flex; align-items: center; gap: 15px; padding: 13px 16px;
  border-radius: var(--radius-sm); color: var(--text2); font-size: 15px; font-weight: 500;
  transition: all .18s; position: relative; cursor: pointer; width: 100%;
}
.nav-item:hover { background: rgba(144,144,224,.07); color: var(--text); transform: translateX(3px); }
.nav-item.active { background: rgba(144,144,224,.12); color: var(--lav2); font-weight: 600; }
.nav-item.active .icon { font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 24; color: var(--lav); }
.nav-badge { position: absolute; right: 12px; background: var(--pink); color: #fff; font-size: 10px; font-weight: 800; padding: 2px 6px; border-radius: 10px; }

.nav-create {
  margin: 14px 8px; background: linear-gradient(135deg, var(--ind), var(--ind2));
  color: #fff; border-radius: var(--radius-sm); padding: 14px 18px; font-size: 15px; font-weight: 700;
  display: flex; align-items: center; justify-content: center; gap: 10px;
  transition: all .2s; font-family: var(--font); box-shadow: 0 6px 24px rgba(88,86,214,.35);
}
.nav-create:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(88,86,214,.45); }

.sidebar-footer { padding: 14px 8px 0; border-top: 1px solid var(--border); margin-top: auto; }
.sidebar-user { display: flex; align-items: center; gap: 11px; padding: 11px 14px; border-radius: var(--radius-sm); cursor: pointer; transition: background .15s; }
.sidebar-user:hover { background: rgba(255,255,255,.04); }
.sidebar-user-name { font-size: 13px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sidebar-user-handle { font-size: 11px; color: var(--muted); }

/* Collapsed sidebar at tablet */
@media (max-width: 1100px) and (min-width: 769px) {
  :root { --nav-w: 80px; }
  .sidebar { padding: 28px 8px 18px; align-items: center; }
  .sidebar-logo { padding: 0 0 28px; justify-content: center; }
  .logo-text, .logo-sub { display: none; }
  .nav-item { justify-content: center; padding: 14px; }
  .nav-item span:not(.icon):not(.nav-badge) { display: none; }
  .nav-create { width: 50px; height: 50px; padding: 0; border-radius: 15px; margin: 14px auto; }
  .nav-create span:not(.icon) { display: none; }
  .sidebar-footer { display: none; }
  .nav-badge { right: 8px; top: 8px; }
}

/* Mobile Top Bar */
.topbar {
  display: none; height: var(--top-h); position: fixed; top: 0; left: 0; right: 0;
  background: rgba(5,6,11,.88); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--border); z-index: 90;
  align-items: center; padding: 0 16px; justify-content: space-between;
}
.topbar-logo { display: flex; align-items: center; gap: 10px; }
.topbar-logo-bolt { width: 32px; height: 32px; background: var(--navy); border-radius: 10px; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 0 2px var(--lav); }
.topbar-logo-bolt .icon { color: var(--bolt); font-size: 17px; font-variation-settings: 'FILL' 1, 'wght' 600, 'GRAD' 0, 'opsz' 24; }

/* Mobile Tab Bar */
.tabbar {
  display: none; height: var(--tab-h); position: fixed; bottom: 0; left: 0; right: 0;
  background: rgba(9,9,26,.94); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
  border-top: 1px solid var(--border); align-items: center; z-index: 90;
  padding-bottom: env(safe-area-inset-bottom, 0px);
}
.tab-btn { flex: 1; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; color: var(--muted); font-size: 10px; font-weight: 600; position: relative; transition: color .2s; font-family: var(--font); }
.tab-btn .icon { font-size: 24px; transition: transform .2s; }
.tab-btn.active { color: var(--lav); }
.tab-btn.active .icon { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; transform: scale(1.12); }
.tab-badge { position: absolute; top: 10px; right: calc(50% - 18px); background: var(--pink); color: #fff; font-size: 9px; font-weight: 800; padding: 1px 4px; border-radius: 10px; min-width: 16px; text-align: center; }

/* ── Main Layout ─────────────────────── */
.main-content { margin-left: var(--nav-w); padding: 36px 24px; min-height: 100vh; }
.main-inner { max-width: 640px; margin: 0 auto; }

/* ── Buttons ─────────────────────────── */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  padding: 11px 22px; border-radius: 100px; font-size: 14px; font-weight: 700;
  transition: all .18s cubic-bezier(.4,0,.2,1); white-space: nowrap; font-family: var(--font); border: none;
}
.btn-primary { background: var(--ind); color: #fff; box-shadow: 0 4px 16px rgba(88,86,214,.3); }
.btn-primary:hover { background: var(--ind2); transform: scale(1.03); box-shadow: 0 6px 22px rgba(88,86,214,.42); }
.btn-ghost { background: transparent; color: var(--lav); border: 1.5px solid var(--border2); }
.btn-ghost:hover { background: rgba(144,144,224,.08); border-color: var(--lav); }
.btn-danger { background: rgba(255,69,58,.1); color: var(--red); border: 1.5px solid rgba(255,69,58,.22); }
.btn-danger:hover { background: var(--red); color: #fff; }
.btn-sm  { padding: 8px 18px; font-size: 13px; }
.btn-lg  { padding: 14px 28px; font-size: 16px; }
.btn-block { width: 100%; }
.btn:disabled { opacity: .4; cursor: not-allowed; transform: none !important; }

.icon-btn { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; color: var(--text2); transition: all .15s; cursor: pointer; }
.icon-btn:hover { background: rgba(144,144,224,.1); color: var(--text); }

/* ── Forms ───────────────────────────── */
.form-group { margin-bottom: 18px; }
.form-label { display: block; font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .7px; margin-bottom: 7px; }
.form-input {
  width: 100%; background: var(--card2); border: 1.5px solid var(--border); color: var(--text);
  border-radius: var(--radius-sm); padding: 13px 16px; font-size: 15px; font-family: var(--font); outline: none;
  transition: border-color .2s, box-shadow .2s;
}
.form-input:focus { border-color: var(--ind); box-shadow: 0 0 0 3px rgba(88,86,214,.12); }
.form-input::placeholder { color: var(--muted); }
textarea.form-input { resize: none; line-height: 1.65; }
.form-error   { background: rgba(255,69,58,.08); border: 1px solid rgba(255,69,58,.3); color: #ff8080; border-radius: var(--radius-xs); padding: 12px 16px; font-size: 13px; margin-bottom: 16px; text-align: center; }
.form-success { background: rgba(52,199,121,.08); border: 1px solid rgba(52,199,121,.3); color: var(--green); border-radius: var(--radius-xs); padding: 12px 16px; font-size: 13px; margin-bottom: 16px; text-align: center; }

/* ── Cards ───────────────────────────── */
.card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; }

/* ── Auth ────────────────────────────── */
.auth-screen {
  min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
  background: radial-gradient(ellipse 80% 50% at 50% 0%, rgba(88,86,214,.12), transparent), var(--bg);
}
.auth-card { width: 100%; max-width: 420px; animation: fadeUp .4s ease; }
.auth-brand { text-align: center; margin-bottom: 40px; }
.auth-bolt {
  width: 86px; height: 86px; background: var(--navy); border-radius: 24px;
  display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;
  box-shadow: 0 0 0 3px var(--lav), var(--glow), var(--shadow);
}
.auth-bolt .icon { color: var(--bolt); font-size: 46px; font-variation-settings: 'FILL' 1, 'wght' 600, 'GRAD' 0, 'opsz' 24; }
.auth-logo { font-size: 38px; font-weight: 800; color: var(--text); letter-spacing: -1.5px; }
.auth-toggle { display: flex; background: var(--card2); padding: 5px; border-radius: 100px; border: 1px solid var(--border); margin-bottom: 28px; gap: 4px; }
.auth-toggle-btn { flex: 1; padding: 11px; border-radius: 100px; font-size: 14px; font-weight: 600; color: var(--muted); transition: all .2s; font-family: var(--font); }
.auth-toggle-btn.active { background: var(--ind); color: #fff; box-shadow: 0 4px 12px rgba(88,86,214,.3); }

/* ── Post Cards ──────────────────────── */
.post-card {
  background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
  margin-bottom: 14px; overflow: hidden;
  transition: border-color .2s, transform .15s;
  animation: fadeUp .35s ease both;
}
.post-card:hover { border-color: var(--border2); }
.post-header { display: flex; align-items: center; gap: 11px; padding: 16px 18px 10px; }
.post-author-wrap { flex: 1; min-width: 0; }
.post-author-name { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 5px; line-height: 1.2; }
.post-author-handle { font-size: 12px; color: var(--muted); }
.post-body { padding: 4px 18px 16px; font-size: 16px; line-height: 1.7; color: rgba(240,240,248,.9); word-break: break-word; }
.post-image { width: 100%; max-height: 540px; object-fit: cover; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
.post-actions { display: flex; align-items: center; padding: 8px 10px; border-top: 1px solid var(--border); gap: 2px; }
.post-act { display: flex; align-items: center; gap: 6px; padding: 9px 12px; border-radius: 100px; color: var(--text2); font-size: 13px; font-weight: 600; transition: all .15s; cursor: pointer; }
.post-act:hover { background: rgba(144,144,224,.09); color: var(--lav2); }
.post-act.liked { color: var(--pink); }
.post-act.liked .icon { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
.post-act.bookmarked .icon { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; color: var(--yellow); }
.post-act .icon { font-size: 20px; }

/* Create Box */
.create-box { background: var(--card2); border: 1px solid var(--border2); border-radius: var(--radius); padding: 20px; margin-bottom: 20px; }
.create-row { display: flex; gap: 14px; align-items: flex-start; }
.create-textarea { flex: 1; background: transparent; border: none; outline: none; color: var(--text); font-family: var(--font); font-size: 17px; resize: none; min-height: 68px; line-height: 1.65; padding-top: 6px; }
.create-textarea::placeholder { color: var(--muted); }
.create-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border); }
.char-counter { font-size: 12px; font-weight: 700; color: var(--muted); }
.char-counter.warn   { color: var(--yellow); }
.char-counter.danger { color: var(--red); }

/* Feed Tabs */
.feed-tabs { display: flex; gap: 6px; background: var(--surface); padding: 5px; border-radius: 100px; border: 1px solid var(--border); margin-bottom: 22px; }
.feed-tab { flex: 1; padding: 10px; text-align: center; font-size: 14px; font-weight: 600; color: var(--muted); border-radius: 100px; cursor: pointer; transition: all .2s; font-family: var(--font); border: none; background: none; }
.feed-tab.active { background: var(--card); color: var(--lav2); box-shadow: 0 2px 12px rgba(0,0,0,.2); }

/* ── Profile ─────────────────────────── */
.profile-header { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 20px; }
.profile-cover { height: 210px; background: linear-gradient(135deg,#0d0e30 0%,#1a1560 50%,#0a0a1e 100%); position: relative; overflow: hidden; cursor: pointer; }
.profile-cover img { width: 100%; height: 100%; object-fit: cover; transition: transform .4s; }
.profile-cover:hover img { transform: scale(1.04); }
.profile-cover-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0); display: flex; align-items: center; justify-content: center; gap: 8px; color: #fff; font-size: 14px; font-weight: 600; opacity: 0; transition: all .2s; pointer-events: none; }
.profile-cover:hover .profile-cover-overlay { background: rgba(0,0,0,.42); opacity: 1; }
.profile-info-row { display: flex; align-items: flex-end; gap: 14px; padding: 0 22px; margin-top: -52px; margin-bottom: 12px; position: relative; z-index: 2; }
.profile-av-ring {
  width: 100px; height: 100px; border-radius: 50%; padding: 3px; flex-shrink: 0;
  background: var(--navy); border: 3px solid var(--card); cursor: pointer;
  box-shadow: 0 0 0 2.5px var(--lav), var(--shadow);
}
.profile-av-inner { width: 100%; height: 100%; border-radius: 50%; overflow: hidden; background: var(--card2); }
.profile-av-inner img { width: 100%; height: 100%; object-fit: cover; }
.profile-av-initials { width: 100%; height: 100%; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 34px; font-weight: 800; color: var(--bolt); }
.profile-actions { margin-left: auto; display: flex; gap: 8px; padding-bottom: 8px; flex-wrap: wrap; justify-content: flex-end; align-items: center; }
.profile-name { font-size: 20px; font-weight: 800; display: flex; align-items: center; gap: 7px; margin-bottom: 2px; }
.profile-handle { color: var(--muted); font-size: 14px; margin-bottom: 8px; }
.profile-bio { font-size: 14px; line-height: 1.65; color: var(--text2); }
.profile-stats { display: flex; border-top: 1px solid var(--border); }
.profile-stat { flex: 1; text-align: center; padding: 14px 0; cursor: pointer; transition: background .15s; }
.profile-stat:hover { background: rgba(144,144,224,.05); }
.profile-stat-num { font-size: 19px; font-weight: 800; color: var(--text); }
.profile-stat-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .7px; font-weight: 700; }
.posts-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 3px; }
.post-grid-item { aspect-ratio: 1; overflow: hidden; background: var(--card2); cursor: pointer; border-radius: 4px; }
.post-grid-item img { width: 100%; height: 100%; object-fit: cover; transition: transform .3s; }
.post-grid-item:hover img { transform: scale(1.06); }
.post-grid-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 4px; padding: 10px; font-size: 11px; color: var(--muted); text-align: center; }

/* Notif Bell */
.notif-bell-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 100px; font-size: 13px; font-weight: 600; border: 1.5px solid var(--border); color: var(--muted); cursor: pointer; transition: all .2s; font-family: var(--font); }
.notif-bell-btn:hover, .notif-bell-btn.on { border-color: var(--lav); color: var(--lav); background: rgba(144,144,224,.09); }
.notif-bell-btn.on .icon { font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 24; }

/* ── Single Post View ─────────────────── */
.back-btn { display: flex; align-items: center; gap: 8px; color: var(--text2); margin-bottom: 18px; font-size: 14px; font-weight: 600; cursor: pointer; background: none; border: none; font-family: var(--font); transition: color .15s; }
.back-btn:hover { color: var(--text); }
.comment-item { display: flex; gap: 11px; padding: 14px 0; border-bottom: 1px solid var(--border); }
.comment-item:last-child { border-bottom: none; }
.comment-body-wrap { flex: 1; }
.comment-header { display: flex; gap: 7px; align-items: baseline; margin-bottom: 4px; flex-wrap: wrap; }
.comment-name { font-size: 13px; font-weight: 700; }
.comment-handle, .comment-time { font-size: 12px; color: var(--muted); }
.comment-text { font-size: 14px; line-height: 1.65; word-break: break-word; color: var(--text2); }
.comment-input-wrap { background: var(--card); border-top: 1px solid var(--border); padding: 12px 18px; display: flex; gap: 10px; align-items: center; position: sticky; bottom: var(--tab-h); z-index: 10; }
.comment-input { flex: 1; background: var(--card2); border: 1.5px solid var(--border); color: var(--text); border-radius: 100px; padding: 10px 18px; font-size: 14px; font-family: var(--font); outline: none; transition: border-color .2s; }
.comment-input:focus { border-color: var(--ind); }

/* ── Explore / Search ─────────────────── */
.search-bar-wrap { position: relative; margin-bottom: 22px; }
.search-input { width: 100%; background: var(--card); border: 1.5px solid var(--border); color: var(--text); border-radius: 100px; padding: 13px 22px 13px 50px; font-size: 15px; font-family: var(--font); outline: none; transition: border-color .2s, box-shadow .2s; }
.search-input:focus { border-color: var(--ind); box-shadow: 0 0 0 3px rgba(88,86,214,.1); }
.search-icon { position: absolute; left: 17px; top: 50%; transform: translateY(-50%); color: var(--muted); }
.user-row { display: flex; align-items: center; gap: 13px; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 13px 16px; margin-bottom: 8px; cursor: pointer; transition: border-color .2s; }
.user-row:hover { border-color: var(--border2); background: rgba(144,144,224,.03); }
.user-row-info { flex: 1; min-width: 0; }
.user-row-name { font-size: 14px; font-weight: 700; }
.user-row-handle { font-size: 12px; color: var(--muted); }

/* ── Messages / DM ───────────────────── */
.conv-item { display: flex; align-items: center; gap: 13px; padding: 13px 16px; border-radius: var(--radius-sm); cursor: pointer; transition: background .15s; }
.conv-item:hover { background: rgba(144,144,224,.05); }
.conv-info { flex: 1; min-width: 0; }
.conv-name { font-size: 14px; font-weight: 700; margin-bottom: 2px; }
.conv-last { font-size: 13px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px; }
.conv-unread { width: 9px; height: 9px; border-radius: 50%; background: var(--ind); flex-shrink: 0; }
.msg-bubble { max-width: 75%; padding: 11px 16px; border-radius: 20px; font-size: 15px; line-height: 1.5; word-break: break-word; }
.msg-me    { background: var(--ind); color: #fff; align-self: flex-end; border-bottom-right-radius: 5px; }
.msg-other { background: var(--card2); color: var(--text); align-self: flex-start; border-bottom-left-radius: 5px; }

/* ── Notifications ───────────────────── */
.notif-item { display: flex; align-items: flex-start; gap: 13px; padding: 14px 16px; border-radius: var(--radius-sm); transition: background .15s; cursor: pointer; }
.notif-item.unread { background: rgba(88,86,214,.07); }
.notif-item:hover { background: rgba(144,144,224,.05); }
.notif-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--ind); flex-shrink: 0; margin-top: 6px; }
.notif-icon-wrap { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.notif-icon-like    { background: rgba(255,59,96,.12); color: var(--pink); }
.notif-icon-comment { background: rgba(144,144,224,.12); color: var(--lav); }
.notif-icon-follow  { background: rgba(88,86,214,.12); color: var(--lav2); }
.notif-icon-post    { background: rgba(52,199,121,.12); color: var(--green); }
.notif-text  { font-size: 14px; line-height: 1.5; color: var(--text2); }
.notif-text strong { font-weight: 700; color: var(--lav2); }
.notif-time-label { font-size: 11px; color: var(--muted); margin-top: 2px; }

/* ── Requests ────────────────────────── */
.req-item { display: flex; align-items: center; gap: 13px; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 16px; margin-bottom: 10px; }
.req-info { flex: 1; min-width: 0; }
.req-name { font-size: 14px; font-weight: 700; }
.req-handle { font-size: 12px; color: var(--muted); }
.req-actions { display: flex; gap: 8px; }

/* ── Settings ────────────────────────── */
.settings-section { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 16px; }
.settings-title { padding: 16px 22px; border-bottom: 1px solid var(--border); font-size: 14px; font-weight: 700; color: var(--lav2); }
.settings-row { display: flex; align-items: center; gap: 14px; padding: 15px 22px; border-bottom: 1px solid var(--border); transition: background .15s; cursor: pointer; }
.settings-row:last-child { border-bottom: none; }
.settings-row:hover { background: rgba(144,144,224,.05); }
.settings-row-label { flex: 1; font-size: 14px; font-weight: 500; }
.settings-row-desc  { font-size: 12px; color: var(--muted); margin-top: 1px; }

/* ── Page containers ─────────────────── */
.page-container { display: none; min-height: 80vh; }
.page-container.active { display: block; }
.page-header { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; }
.page-header h1 { font-size: 24px; font-weight: 800; letter-spacing: -.5px; flex: 1; }

/* ── Empty States ────────────────────── */
.empty-state { text-align: center; padding: 64px 24px; color: var(--muted); }
.empty-state .icon { font-size: 54px; display: block; margin-bottom: 14px; opacity: .18; }
.empty-state p { font-size: 15px; font-weight: 600; line-height: 1.5; }

/* ── Toast ───────────────────────────── */
.toast {
  position: fixed; bottom: 90px; left: 50%; transform: translateX(-50%) translateY(10px);
  background: var(--card2); border: 1px solid var(--border2); color: var(--text);
  padding: 12px 24px; border-radius: 100px; font-size: 14px; font-weight: 600;
  z-index: 500; opacity: 0; pointer-events: none; transition: all .3s; white-space: nowrap;
  box-shadow: var(--shadow);
}
.toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* ── Live Banner ─────────────────────── */
.live-banner {
  position: fixed; top: calc(var(--top-h) + 12px); left: 50%; transform: translateX(-50%) translateY(-20px);
  width: calc(100% - 28px); max-width: 520px; background: rgba(14,15,30,.96);
  backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
  border: 1px solid var(--border2); border-radius: 18px; padding: 14px 18px;
  display: flex; align-items: center; gap: 13px; z-index: 200;
  opacity: 0; transition: all .38s cubic-bezier(.4,0,.2,1); pointer-events: none;
  box-shadow: var(--glow), var(--shadow);
}
.live-banner.show { transform: translateX(-50%) translateY(0); opacity: 1; pointer-events: all; }
.live-banner-icon { width: 42px; height: 42px; border-radius: 13px; background: var(--navy); border: 1.5px solid var(--lav); display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: var(--glow); overflow: hidden; }
.live-banner-icon .icon { color: var(--bolt); font-size: 20px; font-variation-settings: 'FILL' 1, 'wght' 500; }
.live-banner-icon img { width: 100%; height: 100%; object-fit: cover; }
.live-banner-text  { flex: 1; min-width: 0; }
.live-banner-title { font-size: 13px; font-weight: 800; color: var(--text); margin-bottom: 2px; }
.live-banner-body  { font-size: 12px; color: var(--text2); overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.live-banner-close { background: none; border: none; color: var(--muted); cursor: pointer; padding: 4px; border-radius: 50%; transition: background .15s; }
.live-banner-close:hover { background: var(--border); color: var(--text); }

/* ── Misc ────────────────────────────── */
.badge-verified { color: var(--lav); font-size: 16px; font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
.spinner { width: 18px; height: 18px; border: 2px solid var(--border); border-top-color: var(--lav); border-radius: 50%; animation: spin .7s linear infinite; }
.section-label { font-size: 11px; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: .8px; margin-bottom: 12px; }
@keyframes spin    { to { transform: rotate(360deg); } }
@keyframes fadeUp  { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }

/* ── Responsive ──────────────────────── */
@media (max-width: 768px) {
  .sidebar  { display: none; }
  .topbar, .tabbar { display: flex; }
  .main-content { margin-left: 0; padding: calc(var(--top-h) + 14px) 14px calc(var(--tab-h) + 18px); }
  .create-box { padding: 16px; }
  .posts-grid { grid-template-columns: repeat(2,1fr); }
}
@media (max-width: 420px) {
  .card { padding: 16px; }
  .post-body { font-size: 15px; }
}
/* ── Admin Panel ─────────────────────── */
.admin-stat-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 28px; }
.admin-stat-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 20px 18px; text-align: center; }
.admin-stat-num  { font-size: 28px; font-weight: 800; color: var(--lav2); }
.admin-stat-label{ font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .8px; margin-top: 4px; }
.admin-tabs { display: flex; gap: 6px; background: var(--surface); padding: 5px; border-radius: 100px; border: 1px solid var(--border); margin-bottom: 22px; }
.admin-tab { flex: 1; padding: 9px; text-align: center; font-size: 13px; font-weight: 700; color: var(--muted); border-radius: 100px; cursor: pointer; transition: all .2s; font-family: var(--font); border: none; background: none; }
.admin-tab.active { background: var(--card); color: var(--lav2); box-shadow: 0 2px 10px rgba(0,0,0,.2); }
.admin-user-row { display: flex; align-items: center; gap: 13px; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 16px; margin-bottom: 8px; transition: border-color .2s; }
.admin-user-row:hover { border-color: var(--border2); }
.admin-user-info { flex: 1; min-width: 0; }
.admin-user-name { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 6px; }
.admin-user-meta { font-size: 12px; color: var(--muted); margin-top: 2px; }
.admin-actions   { display: flex; gap: 7px; flex-wrap: wrap; justify-content: flex-end; }
.admin-post-row  { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 16px; margin-bottom: 8px; transition: border-color .2s; }
.admin-post-row:hover { border-color: var(--border2); }
.admin-post-author { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.admin-post-body   { font-size: 14px; color: var(--text2); line-height: 1.5; margin-bottom: 10px; word-break: break-word; }
.admin-post-footer { display: flex; align-items: center; gap: 14px; justify-content: space-between; }
.admin-post-stats  { display: flex; gap: 14px; font-size: 12px; color: var(--muted); }
.admin-badge-verified { display: inline-flex; align-items: center; gap: 3px; background: rgba(168,168,248,.12); color: var(--lav2); font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 100px; border: 1px solid rgba(168,168,248,.2); }
.admin-badge-unverified { display: inline-flex; align-items: center; gap: 3px; background: rgba(99,99,122,.1); color: var(--muted); font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 100px; border: 1px solid rgba(99,99,122,.2); }
.admin-search { width: 100%; background: var(--card); border: 1.5px solid var(--border); color: var(--text); border-radius: 100px; padding: 11px 20px 11px 46px; font-size: 14px; font-family: var(--font); outline: none; transition: border-color .2s; margin-bottom: 18px; }
.admin-search:focus { border-color: var(--ind); }
.admin-guard { min-height: 60vh; display: flex; align-items: center; justify-content: center; text-align: center; }
@media (max-width: 640px) {
  .admin-stat-grid { grid-template-columns: repeat(2,1fr); }
  .admin-actions { gap: 5px; }
}
</style>
</head>
<body>

<?php if ($page==='auth'): ?>
<!-- ══════════════════ AUTH ══════════════════ -->
<div class="auth-screen">
  <div class="auth-card">
    <div class="auth-brand">
      <div class="auth-bolt"><span class="icon">bolt</span></div>
      <div class="auth-logo">HaiFlash</div>
      <div style="color:var(--muted);font-size:14px;font-weight:500;margin-top:6px">Ignite Your Story</div>
    </div>
    <?php if (!empty($auth_errors??[])): ?>
    <div class="form-error"><?= h(implode(' ', $auth_errors)) ?></div>
    <?php endif; ?>
    <div class="auth-toggle">
      <button class="auth-toggle-btn <?= ($auth_mode??'login')==='login'?'active':'' ?>" onclick="switchAuth('login')">Sign In</button>
      <button class="auth-toggle-btn <?= ($auth_mode??'login')==='signup'?'active':'' ?>" onclick="switchAuth('signup')">Create Account</button>
    </div>

    <form method="POST" action="?page=auth" id="login-form" style="<?= ($auth_mode??'login')==='signup'?'display:none':'' ?>">
      <input type="hidden" name="_t" value="<?= h(csrf()) ?>">
      <input type="hidden" name="mode" value="login">
      <div class="form-group">
        <label class="form-label">Email Address</label>
        <input class="form-input" type="email" name="email" placeholder="you@example.com" required value="<?= h($_POST['email']??'') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Password</label>
        <input class="form-input" type="password" name="password" placeholder="••••••••" required>
      </div>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Sign In ⚡</button>
    </form>

    <form method="POST" action="?page=auth" id="signup-form" style="<?= ($auth_mode??'login')==='login'?'display:none':'' ?>">
      <input type="hidden" name="_t" value="<?= h(csrf()) ?>">
      <input type="hidden" name="mode" value="signup">
      <div class="form-group">
        <label class="form-label">Display Name</label>
        <input class="form-input" type="text" name="display_name" placeholder="Your Name" required value="<?= h($_POST['display_name']??'') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">@Username</label>
        <input class="form-input" type="text" name="username" placeholder="yourhandle" required value="<?= h(ltrim($_POST['username']??'','@')) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Email Address</label>
        <input class="form-input" type="email" name="email" placeholder="you@example.com" required value="<?= h($_POST['email']??'') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Password</label>
        <input class="form-input" type="password" name="password" placeholder="Min. 6 characters" required>
      </div>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Create Account ⚡</button>
    </form>
  </div>
</div>
<script>
function switchAuth(m){
  document.querySelectorAll('.auth-toggle-btn').forEach((b,i)=>b.classList.toggle('active',(i===0&&m==='login')||(i===1&&m==='signup')));
  document.getElementById('login-form').style.display=m==='login'?'':'none';
  document.getElementById('signup-form').style.display=m==='signup'?'':'none';
}
</script>

<?php elseif ($page==='setup'): ?>
<!-- ══════════════════ SETUP ══════════════════ -->
<div class="auth-screen">
  <div class="card" style="width:100%;max-width:440px;animation:fadeUp .4s ease">
    <div style="text-align:center;margin-bottom:28px">
      <div style="font-size:11px;font-weight:800;color:var(--ind);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Step 2 of 2</div>
      <h2 style="font-size:24px;font-weight:800;margin-bottom:6px">Set up your profile</h2>
      <p style="color:var(--muted);font-size:14px">Add a photo and bio to get started.</p>
    </div>
    <form method="POST" action="?page=setup" enctype="multipart/form-data">
      <input type="hidden" name="_t" value="<?= h(csrf()) ?>">
      <div style="text-align:center;margin-bottom:24px">
        <div style="position:relative;width:100px;height:100px;margin:0 auto 12px;cursor:pointer" onclick="document.getElementById('av-input').click()">
          <div id="av-preview" style="width:100%;height:100%;border-radius:50%;background:var(--card2);border:2.5px solid var(--lav);display:flex;align-items:center;justify-content:center;overflow:hidden;box-shadow:var(--glow)">
            <span class="icon lg" style="color:var(--muted)">add_a_photo</span>
          </div>
          <div style="position:absolute;bottom:0;right:0;background:var(--ind);color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid var(--bg)">
            <span class="icon sm">edit</span>
          </div>
        </div>
        <div style="font-size:13px;color:var(--ind);cursor:pointer;font-weight:600" onclick="document.getElementById('av-input').click()">Upload Photo</div>
        <input type="file" id="av-input" name="avatar" accept="image/*" style="display:none"
          onchange="if(this.files[0]){const r=new FileReader();r.onload=e=>document.getElementById('av-preview').innerHTML='<img src=\''+e.target.result+'\' style=\'width:100%;height:100%;object-fit:cover\'>';r.readAsDataURL(this.files[0])}">
      </div>
      <div class="form-group">
        <label class="form-label">Display Name</label>
        <input class="form-input" type="text" name="display_name" value="<?= h(me()['displayName']) ?>" maxlength="50" required>
      </div>
      <div class="form-group">
        <label class="form-label">Bio <span style="font-weight:400;text-transform:none">(optional)</span></label>
        <textarea class="form-input" name="bio" rows="3" maxlength="160" placeholder="Tell the world about yourself…"></textarea>
      </div>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Complete Setup ⚡</button>
      <a href="?page=home" style="display:block;text-align:center;margin-top:16px;font-size:13px;color:var(--muted);font-weight:600">Skip for now</a>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ══════════════════ MAIN APP ══════════════════ -->

<!-- Desktop Sidebar -->
<nav class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-bolt"><span class="icon">bolt</span></div>
    <div>
      <div class="logo-text">HaiFlash</div>
      <div class="logo-sub">Ignite Your Story</div>
    </div>
  </div>
  <div class="nav-list">
    <button class="nav-item <?= $page==='home'?'active':'' ?>" onclick="goTo('home')"><span class="icon">home</span><span>Home</span></button>
    <button class="nav-item <?= $page==='search'?'active':'' ?>" onclick="goTo('search')"><span class="icon">search</span><span>Explore</span></button>
    <button class="nav-item <?= $page==='notifications'?'active':'' ?>" onclick="goTo('notifications')">
      <span class="icon">notifications</span><span>Notifications</span>
      <?php if($unread_notifs>0):?><span class="nav-badge"><?=$unread_notifs?></span><?php endif;?>
    </button>
    <button class="nav-item <?= $page==='chat'?'active':'' ?>" onclick="goTo('chat')">
      <span class="icon">mail</span><span>Messages</span>
      <?php if($unread_dms>0):?><span class="nav-badge"><?=$unread_dms?></span><?php endif;?>
    </button>
    <button class="nav-item <?= $page==='requests'?'active':'' ?>" onclick="goTo('requests')"><span class="icon">person_add</span><span>Requests</span></button>
    <button class="nav-item <?= $page==='profile'?'active':'' ?>" onclick="goTo('profile')"><span class="icon">person</span><span>Profile</span></button>
    <button class="nav-item <?= $page==='account'?'active':'' ?>" onclick="goTo('account')"><span class="icon">settings</span><span>Settings</span></button>
    <?php if(is_admin()):?>
    <button class="nav-item <?= $page==='admin_panel'?'active':'' ?>" onclick="location.href='?page=admin_panel'" style="color:var(--yellow)"><span class="icon" style="color:var(--yellow)">admin_panel_settings</span><span>Admin Panel</span></button>
    <?php endif;?>
  </div>
  <button class="nav-create" onclick="openCreateModal()"><span class="icon sm">bolt</span><span>Create Post</span></button>
  <div class="sidebar-footer">
    <div class="sidebar-user" onclick="goTo('profile')">
      <?= av(me()['uid'],me()['displayName'],'38px') ?>
      <div style="flex:1;min-width:0">
        <div class="sidebar-user-name"><?= h(me()['displayName']) ?></div>
        <div class="sidebar-user-handle">@<?= h(me()['username']) ?></div>
      </div>
      <a href="?page=logout" onclick="event.stopPropagation()" title="Logout"><span class="icon sm" style="color:var(--muted)">logout</span></a>
    </div>
  </div>
</nav>

<!-- Mobile Top Bar -->
<header class="topbar">
  <div class="topbar-logo">
    <div class="topbar-logo-bolt"><span class="icon" style="font-size:17px">bolt</span></div>
    <span style="font-weight:800;font-size:18px;letter-spacing:-.3px">HaiFlash</span>
  </div>
  <div style="display:flex;gap:4px;align-items:center">
    <button class="icon-btn" onclick="openCreateModal()"><span class="icon">bolt</span></button>
    <button class="icon-btn" style="position:relative" onclick="goTo('notifications')">
      <span class="icon">notifications</span>
      <?php if($unread_notifs>0):?><span class="tab-badge"><?=$unread_notifs?></span><?php endif;?>
    </button>
    <button class="icon-btn" style="position:relative" onclick="goTo('chat')">
      <span class="icon">mail</span>
      <?php if($unread_dms>0):?><span class="tab-badge"><?=$unread_dms?></span><?php endif;?>
    </button>
  </div>
</header>

<main class="main-content">
<div class="main-inner">

<!-- ─── HOME FEED ─────────────────────────────────── -->
<div class="page-container <?= $page==='home'?'active':'' ?>" id="page-home">
  <div class="feed-tabs">
    <button class="feed-tab active" id="tab-foryou"    onclick="switchTab('foryou')">For You</button>
    <button class="feed-tab"        id="tab-following" onclick="switchTab('following')">Following</button>
  </div>

  <!-- Quick Compose -->
  <div class="create-box">
    <div class="create-row">
      <?= av(me()['uid'],me()['displayName'],'44px') ?>
      <textarea class="create-textarea" id="quick-post-body" placeholder="What's igniting your world? ⚡" maxlength="500" oninput="updateQuickCounter(this)"></textarea>
    </div>
    <img id="quick-img-preview" style="max-height:220px;border-radius:var(--radius-sm);margin-top:12px;object-fit:cover;width:100%;display:none" alt="">
    <div class="create-footer">
      <div style="display:flex;align-items:center;gap:6px">
        <label class="icon-btn" for="quick-img-input" title="Add image"><span class="icon" style="color:var(--ind)">image</span></label>
        <input type="file" id="quick-img-input" accept="image/*" style="display:none" onchange="previewQuickImg(this)">
      </div>
      <div style="display:flex;align-items:center;gap:14px">
        <span class="char-counter" id="quick-counter">0/500</span>
        <button class="btn btn-primary btn-sm" onclick="submitQuickPost()" id="quick-post-btn"><span class="icon sm">bolt</span>Post</button>
      </div>
    </div>
  </div>

  <!-- For You Feed -->
  <div id="feed-for-you">
    <?php if(empty($all_posts)):?>
    <div class="empty-state"><span class="icon">bolt</span><p>No posts yet. Be the first to ignite!</p></div>
    <?php else: foreach($all_posts as $idx=>$post):
      $pu   = get_user_by_uid($post['userId']);
      $liked= in_array(me()['uid'], $post['likes']);
      $saved= in_array($post['id'], me()['bookmarks']??[]);
      $pc   = count(array_filter($all_cmnts,fn($c)=>$c['postId']===$post['id']));
    ?>
    <div class="post-card" id="post-<?= h($post['id']) ?>" style="animation-delay:<?= min($idx*.04,.4) ?>s">
      <div class="post-header">
        <div style="cursor:pointer" onclick="location.href='?page=user_profile&uid=<?= h($post['userId']) ?>'">
          <?= av($post['userId'],$post['displayName'],'42px') ?>
        </div>
        <div class="post-author-wrap">
          <div class="post-author-name">
            <span style="cursor:pointer" onclick="location.href='?page=user_profile&uid=<?= h($post['userId']) ?>'"><?= h($post['displayName']) ?></span>
            <?php if($pu&&($pu['verified']??false)):?><span class="badge-verified icon sm">verified</span><?php endif;?>
          </div>
          <div class="post-author-handle">@<?= h($post['username']) ?> · <?= time_ago($post['createdAt']) ?></div>
        </div>
        <?php if($post['userId']===me()['uid']):?>
        <button class="icon-btn" onclick="deletePost('<?= h($post['id']) ?>')" title="Delete"><span class="icon sm">delete</span></button>
        <?php endif;?>
      </div>
      <?php if($post['content']):?><div class="post-body"><?= nl2br(h($post['content'])) ?></div><?php endif;?>
      <?php if($post['imageURL']):?><img class="post-image" src="<?= h($post['imageURL']) ?>" alt="" loading="lazy" onerror="this.style.display='none'"><?php endif;?>
      <div class="post-actions">
        <button class="post-act <?= $liked?'liked':'' ?>" id="like-btn-<?= h($post['id']) ?>" onclick="toggleLike('<?= h($post['id']) ?>')">
          <span class="icon">favorite</span><span id="like-count-<?= h($post['id']) ?>"><?= count($post['likes']) ?></span>
        </button>
        <button class="post-act" onclick="location.href='?page=post&id=<?= h($post['id']) ?>'">
          <span class="icon">chat_bubble_outline</span><span><?= $pc ?></span>
        </button>
        <button class="post-act" onclick="sharePost('<?= h($post['id']) ?>')"><span class="icon">share</span></button>
        <div style="flex:1"></div>
        <button class="post-act <?= $saved?'bookmarked':'' ?>" id="bm-btn-<?= h($post['id']) ?>" onclick="toggleBookmark('<?= h($post['id']) ?>')">
          <span class="icon">bookmark</span>
        </button>
      </div>
    </div>
    <?php endforeach; endif;?>
  </div>

  <!-- Following Feed -->
  <div id="feed-following" style="display:none">
    <?php $foll_posts=array_filter($all_posts,fn($p)=>in_array($p['userId'],me()['following']??[]));
    if(empty($foll_posts)):?>
    <div class="empty-state"><span class="icon">group</span><p>Follow people to see their posts here.</p></div>
    <?php else: foreach($foll_posts as $post):
      $pu  =get_user_by_uid($post['userId']);$liked=in_array(me()['uid'],$post['likes']);
      $pc=count(array_filter($all_cmnts,fn($c)=>$c['postId']===$post['id']));
    ?>
    <div class="post-card">
      <div class="post-header">
        <?= av($post['userId'],$post['displayName'],'42px') ?>
        <div class="post-author-wrap">
          <div class="post-author-name"><?= h($post['displayName']) ?><?php if($pu&&($pu['verified']??false)):?><span class="badge-verified icon sm">verified</span><?php endif;?></div>
          <div class="post-author-handle">@<?= h($post['username']) ?> · <?= time_ago($post['createdAt']) ?></div>
        </div>
      </div>
      <?php if($post['content']):?><div class="post-body"><?= nl2br(h($post['content'])) ?></div><?php endif;?>
      <?php if($post['imageURL']):?><img class="post-image" src="<?= h($post['imageURL']) ?>" alt="" loading="lazy"><?php endif;?>
      <div class="post-actions">
        <button class="post-act <?= $liked?'liked':'' ?>" onclick="toggleLike('<?= h($post['id']) ?>')"><span class="icon">favorite</span><?= count($post['likes']) ?></button>
        <button class="post-act" onclick="location.href='?page=post&id=<?= h($post['id']) ?>'"><span class="icon">chat_bubble_outline</span><?= $pc ?></button>
      </div>
    </div>
    <?php endforeach; endif;?>
  </div>
</div><!-- /page-home -->

<!-- ─── EXPLORE ───────────────────────────────────── -->
<?php
$search_q=$_GET['q']??''; $search_users=[]; $search_posts=[];
if($search_q){$sq=strtolower($search_q);
  foreach($all_users as $u){if($u['uid']===me()['uid'])continue;if(str_contains(strtolower($u['displayName']),$sq)||str_contains(strtolower($u['username']),$sq))$search_users[]=$u;}
  foreach($all_posts as $p){if(str_contains(strtolower($p['content']),$sq))$search_posts[]=$p;}
}?>
<div class="page-container <?= $page==='search'?'active':'' ?>" id="page-search">
  <div class="page-header"><h1>Explore</h1></div>
  <div class="search-bar-wrap">
    <span class="icon search-icon">search</span>
    <form method="GET">
      <input type="hidden" name="page" value="search">
      <input class="search-input" type="text" name="q" placeholder="Search people or posts…" value="<?= h($search_q) ?>" autocomplete="off">
    </form>
  </div>

  <?php if($search_q): ?>
    <?php if(!empty($search_users)):?>
    <div class="section-label">People (<?= count($search_users) ?>)</div>
    <div style="margin-bottom:24px">
      <?php foreach($search_users as $su):$isf=in_array($su['uid'],me()['following']??[]);?>
      <div class="user-row" onclick="location.href='?page=user_profile&uid=<?= h($su['uid']) ?>'">
        <?= av($su['uid'],$su['displayName'],'46px') ?>
        <div class="user-row-info">
          <div class="user-row-name"><?= h($su['displayName']) ?><?php if($su['verified']??false):?> <span class="badge-verified icon sm">verified</span><?php endif;?></div>
          <div class="user-row-handle">@<?= h($su['username']) ?> · <?= count($su['followers']??[]) ?> followers</div>
        </div>
        <button class="btn btn-sm <?= $isf?'btn-ghost':'btn-primary' ?>" onclick="event.stopPropagation();followUser('<?= h($su['uid']) ?>',this)"><?= $isf?'Following':'Follow' ?></button>
      </div>
      <?php endforeach;?>
    </div>
    <?php endif;?>
    <?php if(!empty($search_posts)):?>
    <div class="section-label">Posts (<?= count($search_posts) ?>)</div>
    <?php foreach($search_posts as $sp):$liked=in_array(me()['uid'],$sp['likes']);$spc=count(array_filter($all_cmnts,fn($c)=>$c['postId']===$sp['id']));?>
    <div class="post-card" onclick="location.href='?page=post&id=<?= h($sp['id']) ?>'">
      <div class="post-header">
        <?= av($sp['userId'],$sp['displayName'],'42px') ?>
        <div class="post-author-wrap">
          <div class="post-author-name"><?= h($sp['displayName']) ?></div>
          <div class="post-author-handle">@<?= h($sp['username']) ?> · <?= time_ago($sp['createdAt']) ?></div>
        </div>
      </div>
      <?php if($sp['content']):?><div class="post-body"><?= nl2br(h($sp['content'])) ?></div><?php endif;?>
      <?php if($sp['imageURL']):?><img class="post-image" src="<?= h($sp['imageURL']) ?>" alt="" loading="lazy" onerror="this.style.display='none'"><?php endif;?>
      <div class="post-actions">
        <button class="post-act <?= $liked?'liked':'' ?>" onclick="event.stopPropagation();toggleLike('<?= h($sp['id']) ?>')"><span class="icon">favorite</span><?= count($sp['likes']) ?></button>
        <button class="post-act"><span class="icon">chat_bubble_outline</span><?= $spc ?></button>
      </div>
    </div>
    <?php endforeach;?>
    <?php endif;?>
    <?php if(empty($search_users)&&empty($search_posts)):?>
    <div class="empty-state"><span class="icon">search_off</span><p>No results for "<?= h($search_q) ?>"</p></div>
    <?php endif;?>

  <?php else:?>
    <div class="section-label">Suggested People</div>
    <div style="margin-bottom:26px">
      <?php $sugg=array_slice(array_values(array_filter($all_users,fn($u)=>$u['uid']!==me()['uid'])),0,6);
      foreach($sugg as $su):$isf=in_array($su['uid'],me()['following']??[]);?>
      <div class="user-row" onclick="location.href='?page=user_profile&uid=<?= h($su['uid']) ?>'">
        <?= av($su['uid'],$su['displayName'],'46px') ?>
        <div class="user-row-info">
          <div class="user-row-name"><?= h($su['displayName']) ?><?php if($su['verified']??false):?> <span class="badge-verified icon sm">verified</span><?php endif;?></div>
          <div class="user-row-handle">@<?= h($su['username']) ?></div>
        </div>
        <button class="btn btn-sm <?= $isf?'btn-ghost':'btn-primary' ?>" onclick="event.stopPropagation();followUser('<?= h($su['uid']) ?>',this)"><?= $isf?'Following':'Follow' ?></button>
      </div>
      <?php endforeach;
      if(empty($sugg)):?><div class="empty-state"><span class="icon">group</span><p>No other users yet.</p></div><?php endif;?>
    </div>
    <div class="section-label">Explore Posts</div>
    <div class="posts-grid">
      <?php foreach(array_slice($all_posts,0,24) as $ep):?>
      <div class="post-grid-item" onclick="location.href='?page=post&id=<?= h($ep['id']) ?>'">
        <?php if($ep['imageURL']):?><img src="<?= h($ep['imageURL']) ?>" alt="" loading="lazy">
        <?php else:?>
        <div class="post-grid-placeholder">
          <?= av($ep['userId'],$ep['displayName'],'28px') ?>
          <span style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden"><?= h(mb_substr($ep['content'],0,60)) ?></span>
        </div>
        <?php endif;?>
      </div>
      <?php endforeach;?>
    </div>
  <?php endif;?>
</div><!-- /page-search -->

<!-- ─── SINGLE POST ──────────────────────────────── -->
<?php $vpid=$_GET['id']??''; $vpost=null;
foreach($all_posts as $vp){if($vp['id']===$vpid){$vpost=$vp;break;}}
$pcmnts=array_filter($all_cmnts,fn($c)=>$c['postId']===$vpid);?>
<div class="page-container <?= $page==='post'?'active':'' ?>" id="page-post">
  <button class="back-btn" onclick="history.back()"><span class="icon sm">arrow_back</span>Back</button>
  <?php if($vpost):?>
  <?php $vpu=get_user_by_uid($vpost['userId']); $vlike=in_array(me()['uid'],$vpost['likes']); $vsave=in_array($vpost['id'],me()['bookmarks']??[]);?>
  <div class="post-card" style="margin-bottom:0;border-radius:var(--radius) var(--radius) 0 0">
    <div class="post-header">
      <?= av($vpost['userId'],$vpost['displayName'],'46px') ?>
      <div class="post-author-wrap">
        <div class="post-author-name" style="font-size:15px"><?= h($vpost['displayName']) ?><?php if($vpu&&($vpu['verified']??false)):?><span class="badge-verified icon sm">verified</span><?php endif;?></div>
        <div class="post-author-handle">@<?= h($vpost['username']) ?> · <?= time_ago($vpost['createdAt']) ?></div>
      </div>
    </div>
    <?php if($vpost['content']):?><div class="post-body" style="font-size:18px"><?= nl2br(h($vpost['content'])) ?></div><?php endif;?>
    <?php if($vpost['imageURL']):?><img class="post-image" src="<?= h($vpost['imageURL']) ?>" alt=""><?php endif;?>
    <div class="post-actions">
      <button class="post-act <?= $vlike?'liked':'' ?>" id="like-btn-detail" onclick="toggleLike('<?= h($vpost['id']) ?>')">
        <span class="icon">favorite</span><span id="like-count-detail"><?= count($vpost['likes']) ?></span>
      </button>
      <button class="post-act"><span class="icon">chat_bubble_outline</span><?= count($pcmnts) ?></button>
      <button class="post-act" onclick="sharePost('<?= h($vpost['id']) ?>')"><span class="icon">share</span></button>
      <div style="flex:1"></div>
      <button class="post-act <?= $vsave?'bookmarked':'' ?>" onclick="toggleBookmark('<?= h($vpost['id']) ?>')"><span class="icon">bookmark</span></button>
    </div>
  </div>
  <!-- Comments section -->
  <div style="background:var(--card);border:1px solid var(--border);border-top:none;border-radius:0 0 var(--radius) var(--radius);padding:18px;margin-bottom:0">
    <?php if(empty($pcmnts)):?>
    <p style="text-align:center;padding:28px;color:var(--muted);font-size:14px;font-weight:500">No comments yet. Start the conversation!</p>
    <?php else: foreach($pcmnts as $cm):?>
    <div class="comment-item">
      <?= av($cm['userId'],$cm['displayName'],'36px') ?>
      <div class="comment-body-wrap">
        <div class="comment-header">
          <span class="comment-name"><?= h($cm['displayName']) ?></span>
          <span class="comment-handle">@<?= h($cm['username']) ?></span>
          <span class="comment-time">· <?= time_ago($cm['createdAt']) ?></span>
        </div>
        <div class="comment-text"><?= nl2br(h($cm['body'])) ?></div>
      </div>
    </div>
    <?php endforeach; endif;?>
  </div>
  <div class="comment-input-wrap">
    <?= av(me()['uid'],me()['displayName'],'36px') ?>
    <form method="POST" action="?page=comment" style="flex:1;display:flex;gap:8px">
      <input type="hidden" name="_t" value="<?= h(csrf()) ?>">
      <input type="hidden" name="post_id" value="<?= h($vpost['id']) ?>">
      <input class="comment-input" type="text" name="body" placeholder="Add a comment…" maxlength="280" required>
      <button class="btn btn-primary btn-sm" type="submit"><span class="icon sm">send</span></button>
    </form>
  </div>
  <?php else:?><div class="empty-state"><span class="icon">error</span><p>Post not found.</p></div><?php endif;?>
</div><!-- /page-post -->

<!-- ─── OWN PROFILE ─────────────────────────────── -->
<?php
$me_user = get_user_by_uid(me()['uid'])??me();
$my_posts = array_filter($all_posts,fn($p)=>$p['userId']===me()['uid']);
$my_saved = array_filter($all_posts,fn($p)=>in_array($p['id'],me()['bookmarks']??[]));
$mav=$me_user['photoURL']??''; $mcov=$me_user['coverURL']??'';?>
<div class="page-container <?= $page==='profile'?'active':'' ?>" id="page-profile">
  <div class="profile-header">
    <div class="profile-cover" onclick="document.getElementById('cover-input').click()" title="Change cover">
      <?php if($mcov && file_exists(__DIR__.'/'.$mcov)):?>
        <img src="<?= h($mcov) ?>" alt="" id="cover-preview-img">
      <?php else:?>
        <img src="" alt="" id="cover-preview-img" style="display:none">
      <?php endif;?>
      <div class="profile-cover-overlay"><span class="icon sm">add_photo_alternate</span>Change Cover</div>
    </div>
    <div class="profile-info-row">
      <div class="profile-av-ring" onclick="document.getElementById('avatar-input').click()" title="Change photo">
        <div class="profile-av-inner">
          <?php if($mav && file_exists(AVATAR_DIR.basename($mav))):?>
            <img src="<?= h(AVATAR_URL.basename($mav)) ?>" alt="">
          <?php else:?><div class="profile-av-initials"><?= strtoupper(mb_substr(me()['displayName'],0,1)) ?></div><?php endif;?>
        </div>
      </div>
      <div class="profile-actions">
        <button class="btn btn-ghost btn-sm" onclick="openEditProfile()"><span class="icon sm">edit</span>Edit Profile</button>
        <a href="?page=logout" class="icon-btn" title="Logout"><span class="icon sm">logout</span></a>
      </div>
    </div>
    <!-- Hidden upload form -->
    <form id="quick-profile-form" method="POST" action="?page=edit_profile" enctype="multipart/form-data" style="display:none">
      <input type="hidden" name="_t" value="<?= h(csrf()) ?>">
      <input type="hidden" name="display_name" value="<?= h(me()['displayName']) ?>">
      <input type="hidden" name="bio" value="<?= h($me_user['bio']??'') ?>">
      <input type="file" id="cover-input"  name="cover"  accept="image/*" onchange="submitProfileFile('cover')">
      <input type="file" id="avatar-input" name="avatar" accept="image/*" onchange="submitProfileFile('avatar')">
    </form>
    <div style="padding:0 22px 6px">
      <div class="profile-name"><?= h(me()['displayName']) ?><?php if($me_user['verified']??false):?><span class="badge-verified icon">verified</span><?php endif;?></div>
      <div class="profile-handle">@<?= h(me()['username']) ?></div>
      <?php if($me_user['bio']??''):?><div class="profile-bio" style="margin-top:8px"><?= nl2br(h($me_user['bio'])) ?></div><?php endif;?>
    </div>
    <div class="profile-stats">
      <div class="profile-stat"><div class="profile-stat-num"><?= count($my_posts) ?></div><div class="profile-stat-label">Posts</div></div>
      <div class="profile-stat"><div class="profile-stat-num"><?= count($me_user['followers']??[]) ?></div><div class="profile-stat-label">Followers</div></div>
      <div class="profile-stat"><div class="profile-stat-num"><?= count($me_user['following']??[]) ?></div><div class="profile-stat-label">Following</div></div>
    </div>
  </div>

  <div class="feed-tabs">
    <button class="feed-tab active" id="ptab-posts" onclick="switchPTab('posts')"><span class="icon sm">grid_on</span> Posts</button>
    <button class="feed-tab"        id="ptab-saved" onclick="switchPTab('saved')"><span class="icon sm">bookmark</span> Saved</button>
  </div>

  <div id="ptab-panel-posts">
    <div class="posts-grid">
      <?php foreach($my_posts as $mp):?>
      <div class="post-grid-item" onclick="location.href='?page=post&id=<?= h($mp['id']) ?>'">
        <?php if($mp['imageURL']):?><img src="<?= h($mp['imageURL']) ?>" alt="" loading="lazy">
        <?php else:?><div class="post-grid-placeholder"><span class="icon sm">article</span><span style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden"><?= h(mb_substr($mp['content'],0,60)) ?></span></div><?php endif;?>
      </div>
      <?php endforeach;
      if(empty($my_posts)):?><div class="empty-state" style="grid-column:1/-1"><span class="icon">bolt</span><p>No posts yet.</p></div><?php endif;?>
    </div>
  </div>
  <div id="ptab-panel-saved" style="display:none">
    <div class="posts-grid">
      <?php foreach($my_saved as $sp):?>
      <div class="post-grid-item" onclick="location.href='?page=post&id=<?= h($sp['id']) ?>'">
        <?php if($sp['imageURL']):?><img src="<?= h($sp['imageURL']) ?>" alt="" loading="lazy">
        <?php else:?><div class="post-grid-placeholder"><span class="icon sm">article</span><span style="overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical"><?= h(mb_substr($sp['content'],0,60)) ?></span></div><?php endif;?>
      </div>
      <?php endforeach;
      if(empty($my_saved)):?><div class="empty-state" style="grid-column:1/-1"><span class="icon">bookmark</span><p>No saved posts yet.</p></div><?php endif;?>
    </div>
  </div>
</div><!-- /page-profile -->

<!-- ─── PUBLIC USER PROFILE ─────────────────────── -->
<?php
$view_uid  = $_GET['uid']??'';
$view_user = $view_uid ? get_user_by_uid($view_uid) : null;
$view_uposts = $view_user ? array_filter($all_posts,fn($p)=>$p['userId']===$view_uid) : [];
$is_following  = $view_user ? in_array($view_uid,me()['following']??[]) : false;
$is_subscribed = $view_user ? in_array($view_uid,me()['postNotifs']??[]) : false;
$vcov=$view_user['coverURL']??''; $vuav=$view_user['photoURL']??'';?>
<div class="page-container <?= $page==='user_profile'?'active':'' ?>" id="page-user_profile">
  <button class="back-btn" onclick="history.back()"><span class="icon sm">arrow_back</span>Back</button>
  <?php if($view_user):?>
  <div class="profile-header">
    <div class="profile-cover" style="cursor:default">
      <?php if($vcov && file_exists(__DIR__.'/'.$vcov)):?><img src="<?= h($vcov) ?>" alt=""><?php endif;?>
    </div>
    <div class="profile-info-row">
      <div class="profile-av-ring" style="cursor:default">
        <div class="profile-av-inner">
          <?php if($vuav && file_exists(AVATAR_DIR.basename($vuav))):?>
            <img src="<?= h(AVATAR_URL.basename($vuav)) ?>" alt="">
          <?php else:?><div class="profile-av-initials"><?= strtoupper(mb_substr($view_user['displayName'],0,1)) ?></div><?php endif;?>
        </div>
      </div>
      <div class="profile-actions">
        <button class="btn btn-sm <?= $is_following?'btn-ghost':'btn-primary' ?>" id="follow-btn-profile" onclick="followUser('<?= h($view_uid) ?>',this)">
          <?= $is_following?'<span class="icon sm">check</span>Following':'<span class="icon sm">person_add</span>Follow' ?>
        </button>
        <button class="notif-bell-btn <?= $is_subscribed?'on':'' ?>" id="post-notif-btn" onclick="togglePostNotif('<?= h($view_uid) ?>',this)" title="Post notifications">
          <span class="icon sm">notifications<?= $is_subscribed?'_active':'' ?></span><?= $is_subscribed?'Notified':'Notify' ?>
        </button>
        <button class="icon-btn" onclick="location.href='?page=dm&uid=<?= h($view_uid) ?>'" title="Message"><span class="icon">mail</span></button>
      </div>
    </div>
    <div style="padding:0 22px 6px">
      <div class="profile-name"><?= h($view_user['displayName']) ?><?php if($view_user['verified']??false):?><span class="badge-verified icon">verified</span><?php endif;?></div>
      <div class="profile-handle">@<?= h($view_user['username']) ?></div>
      <?php if($view_user['bio']??''):?><div class="profile-bio" style="margin-top:8px"><?= nl2br(h($view_user['bio'])) ?></div><?php endif;?>
    </div>
    <div class="profile-stats">
      <div class="profile-stat"><div class="profile-stat-num"><?= count($view_uposts) ?></div><div class="profile-stat-label">Posts</div></div>
      <div class="profile-stat"><div class="profile-stat-num"><?= count($view_user['followers']??[]) ?></div><div class="profile-stat-label">Followers</div></div>
      <div class="profile-stat"><div class="profile-stat-num"><?= count($view_user['following']??[]) ?></div><div class="profile-stat-label">Following</div></div>
    </div>
  </div>
  <div class="posts-grid" style="margin-top:4px">
    <?php foreach($view_uposts as $up):?>
    <div class="post-grid-item" onclick="location.href='?page=post&id=<?= h($up['id']) ?>'">
      <?php if($up['imageURL']):?><img src="<?= h($up['imageURL']) ?>" alt="" loading="lazy">
      <?php else:?><div class="post-grid-placeholder"><span class="icon sm">article</span><span style="overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical"><?= h(mb_substr($up['content'],0,60)) ?></span></div><?php endif;?>
    </div>
    <?php endforeach;
    if(empty($view_uposts)):?><div class="empty-state" style="grid-column:1/-1"><span class="icon">bolt</span><p>No posts yet.</p></div><?php endif;?>
  </div>
  <?php else:?><div class="empty-state"><span class="icon">person_off</span><p>User not found.</p></div><?php endif;?>
</div><!-- /page-user_profile -->

<!-- ─── NOTIFICATIONS ─────────────────────────────── -->
<?php $my_notifs=array_reverse(array_values(array_filter($all_notifs,fn($n)=>$n['for']===me()['uid'])));?>
<div class="page-container <?= $page==='notifications'?'active':'' ?>" id="page-notifications">
  <div class="page-header">
    <h1>Notifications</h1>
    <?php if(!empty($my_notifs)):?><button class="btn btn-sm btn-ghost" onclick="markAllRead()">Mark all read</button><?php endif;?>
  </div>
  <?php if(empty($my_notifs)):?>
  <div class="empty-state"><span class="icon">notifications_off</span><p>All caught up!</p></div>
  <?php else:?>
  <div style="display:flex;flex-direction:column;gap:2px">
    <?php foreach($my_notifs as $nn):
      $icons=['like'=>'favorite','comment'=>'chat_bubble','follow'=>'person_add','post'=>'bolt'];
      $cls  =['like'=>'notif-icon-like','comment'=>'notif-icon-comment','follow'=>'notif-icon-follow','post'=>'notif-icon-post'];
      $msgs =['like'=>' liked your post','comment'=>' commented on your post','follow'=>' started following you','post'=>' just posted something new'];
      $ic=$icons[$nn['type']]??'notifications'; $cl=$cls[$nn['type']]??''; $msg=$msgs[$nn['type']]??'';
      $link=($nn['ref_id']??'')?'?page=post&id='.$nn['ref_id']:'?page=user_profile&uid='.$nn['from_uid'];?>
    <div class="notif-item <?= !$nn['read']?'unread':'' ?>" onclick="location.href='<?= h($link) ?>'">
      <div class="notif-icon-wrap <?= $cl ?>"><span class="icon sm <?= $nn['type']==='like'?'filled':'' ?>"><?= $ic ?></span></div>
      <?= av($nn['from_uid'],$nn['from_name'],'40px') ?>
      <div style="flex:1">
        <div class="notif-text"><strong><?= h($nn['from_name']) ?></strong><?= h($msg) ?></div>
        <div class="notif-time-label"><?= time_ago($nn['createdAt']) ?></div>
      </div>
      <?php if(!$nn['read']):?><div class="notif-dot"></div><?php endif;?>
    </div>
    <?php endforeach;?>
  </div>
  <?php endif;?>
</div><!-- /page-notifications -->

<!-- ─── FOLLOW REQUESTS ───────────────────────────── -->
<?php $my_reqs=array_values(array_filter($all_reqs,fn($r)=>$r['to']===me()['uid']&&$r['status']==='pending'));?>
<div class="page-container <?= $page==='requests'?'active':'' ?>" id="page-requests">
  <div class="page-header"><h1>Follow Requests</h1></div>
  <?php if(empty($my_reqs)):?>
  <div class="empty-state"><span class="icon">person_add</span><p>No pending requests.</p></div>
  <?php else: foreach($my_reqs as $req):?>
  <div class="req-item">
    <?= av($req['from'],$req['fromName']??'?','46px') ?>
    <div class="req-info">
      <div class="req-name"><?= h($req['fromName']??'') ?></div>
      <div class="req-handle">@<?= h($req['fromUsername']??'') ?> · <?= time_ago($req['createdAt']) ?></div>
    </div>
    <div class="req-actions">
      <form method="POST" action="?page=respond_follow" style="display:inline">
        <input type="hidden" name="_t" value="<?= h(csrf()) ?>">
        <input type="hidden" name="req_id" value="<?= h($req['id']) ?>">
        <input type="hidden" name="action" value="accept">
        <button class="btn btn-primary btn-sm" type="submit">Accept</button>
      </form>
      <form method="POST" action="?page=respond_follow" style="display:inline">
        <input type="hidden" name="_t" value="<?= h(csrf()) ?>">
        <input type="hidden" name="req_id" value="<?= h($req['id']) ?>">
        <input type="hidden" name="action" value="decline">
        <button class="btn btn-danger btn-sm" type="submit">Decline</button>
      </form>
    </div>
  </div>
  <?php endforeach; endif;?>
</div><!-- /page-requests -->

<!-- ─── MESSAGES LIST ─────────────────────────────── -->
<?php
$my_convs=array_values(array_filter($all_convs,fn($c)=>in_array(me()['uid'],$c['participants'])));
usort($my_convs,fn($a,$b)=>strcmp($b['lastMessageTime']??'0',$a['lastMessageTime']??'0'));?>
<div class="page-container <?= $page==='chat'?'active':'' ?>" id="page-chat">
  <div class="page-header"><h1>Messages</h1></div>
  <?php if(empty($my_convs)):?>
  <div class="empty-state"><span class="icon">mail</span><p>No conversations yet. Message someone!</p></div>
  <?php else:?>
  <div style="display:flex;flex-direction:column;gap:2px">
    <?php foreach($my_convs as $conv):
      $other_uid=array_values(array_filter($conv['participants'],fn($p)=>$p!==me()['uid']))[0]??null;
      if(!$other_uid)continue; $other=get_user_by_uid($other_uid); if(!$other)continue;
      $unread=count(array_filter($conv['messages']??[],fn($m)=>$m['from']!==me()['uid']&&!($m['read']??false)));?>
    <div class="conv-item" onclick="location.href='?page=dm&uid=<?= h($other_uid) ?>'">
      <?= av($other_uid,$other['displayName'],'50px') ?>
      <div class="conv-info">
        <div class="conv-name"><?= h($other['displayName']) ?></div>
        <div class="conv-last"><?= h(mb_substr($conv['lastMessage']??'',0,50)) ?></div>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px">
        <div style="font-size:11px;color:var(--muted)"><?= $conv['lastMessageTime']?time_ago($conv['lastMessageTime']):'' ?></div>
        <?php if($unread>0):?><div class="conv-unread"></div><?php endif;?>
      </div>
    </div>
    <?php endforeach;?>
  </div>
  <?php endif;?>
</div><!-- /page-chat -->

<!-- ─── DIRECT MESSAGE ───────────────────────────── -->
<?php
$dm_uid =$_GET['uid']??''; $dm_user=$dm_uid?get_user_by_uid($dm_uid):null; $dm_conv=null;
if($dm_uid){foreach($all_convs as $c){if(in_array(me()['uid'],$c['participants'])&&in_array($dm_uid,$c['participants'])){$dm_conv=$c;break;}}}?>
<div class="page-container <?= $page==='dm'?'active':'' ?>" id="page-dm">
  <?php if($dm_user):?>
  <div style="display:flex;flex-direction:column;height:calc(100vh - var(--top-h) - var(--tab-h) - 40px)">
    <div style="display:flex;align-items:center;gap:13px;padding:12px 0;border-bottom:1px solid var(--border);margin-bottom:12px;flex-shrink:0">
      <button class="icon-btn" onclick="goTo('chat')"><span class="icon">arrow_back</span></button>
      <?= av($dm_uid,$dm_user['displayName'],'42px') ?>
      <div>
        <div style="font-weight:700;font-size:15px"><?= h($dm_user['displayName']) ?></div>
        <div style="font-size:12px;color:var(--muted)">@<?= h($dm_user['username']) ?></div>
      </div>
    </div>
    <div class="dm-messages-list" id="dm-messages" style="flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:9px;padding-bottom:12px">
      <?php if($dm_conv): foreach(($dm_conv['messages']??[]) as $msg): $isme=$msg['from']===me()['uid'];?>
      <div style="display:flex;flex-direction:column;align-items:<?= $isme?'flex-end':'flex-start' ?>">
        <div class="msg-bubble <?= $isme?'msg-me':'msg-other' ?>"><?= nl2br(h($msg['body'])) ?></div>
        <div style="font-size:10px;color:var(--muted);margin-top:3px;padding:0 5px"><?= time_ago($msg['createdAt']) ?></div>
      </div>
      <?php endforeach; else:?><div class="empty-state"><span class="icon">chat_bubble_outline</span><p>Start a conversation!</p></div><?php endif;?>
    </div>
    <form method="POST" action="?page=send_dm" style="display:flex;gap:8px;align-items:center;padding-top:10px;border-top:1px solid var(--border);flex-shrink:0">
      <input type="hidden" name="_t" value="<?= h(csrf()) ?>">
      <input type="hidden" name="to_uid" value="<?= h($dm_uid) ?>">
      <?= av(me()['uid'],me()['displayName'],'36px') ?>
      <input class="comment-input" type="text" name="body" placeholder="Message <?= h($dm_user['displayName']) ?>…" autocomplete="off" required>
      <button class="btn btn-primary btn-sm" type="submit"><span class="icon sm">send</span></button>
    </form>
  </div>
  <?php else:?><div class="empty-state"><span class="icon">person_off</span><p>User not found.</p></div><?php endif;?>
</div><!-- /page-dm -->

<!-- ─── SETTINGS ─────────────────────────────────── -->
<div class="page-container <?= $page==='account'?'active':'' ?>" id="page-account">
  <div class="page-header"><h1>Settings</h1></div>
  <?php if(!empty($pw_success??'')):?><div class="form-success"><?= h($pw_success) ?></div><?php endif;?>
  <?php if(!empty($pw_error??'')):?><div class="form-error"><?= h($pw_error) ?></div><?php endif;?>

  <!-- Edit Profile Section -->
  <div class="settings-section">
    <div class="settings-title">Edit Profile</div>
    <div style="padding:22px">
      <!-- Collapsed view -->
      <div id="edit-profile-placeholder">
        <div style="display:flex;align-items:center;gap:15px;margin-bottom:18px">
          <?= av(me()['uid'],me()['displayName'],'58px') ?>
          <div>
            <div style="font-weight:700;font-size:16px"><?= h(me()['displayName']) ?></div>
            <div style="color:var(--muted);font-size:13px">@<?= h(me()['username']) ?></div>
            <?php if($me_user['bio']??''):?><div style="font-size:13px;margin-top:4px;color:var(--text2)"><?= h(mb_substr($me_user['bio'],0,80)) ?></div><?php endif;?>
          </div>
        </div>
        <button class="btn btn-ghost btn-sm" onclick="toggleEditProfile(true)"><span class="icon sm">edit</span>Edit Profile</button>
      </div>
      <!-- Expanded form -->
      <form method="POST" action="?page=edit_profile" enctype="multipart/form-data" id="edit-profile-form" style="display:none">
        <input type="hidden" name="_t" value="<?= h(csrf()) ?>">
        <!-- Cover -->
        <div style="margin-bottom:20px">
          <label class="form-label">Cover Banner</label>
          <div style="height:120px;border-radius:var(--radius-sm);overflow:hidden;background:linear-gradient(135deg,#0d0d2b,#1a1050);position:relative;cursor:pointer;border:1.5px dashed var(--border2)" onclick="document.getElementById('ep-cover-input').click()">
            <img id="ep-cover-preview" src="<?= h($me_user['coverURL']??'') ?>" alt="" style="width:100%;height:100%;object-fit:cover;display:<?= ($me_user['coverURL']??'')?'block':'none' ?>">
            <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:8px;color:var(--ind);font-size:13px;font-weight:700"><span class="icon sm">add_photo_alternate</span><?= ($me_user['coverURL']??'')?'Change Cover':'Add Cover' ?></div>
          </div>
          <input type="file" id="ep-cover-input" name="cover" accept="image/*" style="display:none" onchange="previewEpCover(this)">
        </div>
        <!-- Avatar -->
        <div style="text-align:center;margin-bottom:20px">
          <div style="position:relative;width:90px;height:90px;margin:0 auto 12px;cursor:pointer;border-radius:50%;border:2.5px solid var(--lav);box-shadow:var(--glow)" onclick="document.getElementById('ep-av-input').click()">
            <div id="ep-av-preview" style="width:100%;height:100%;border-radius:50%;overflow:hidden;background:var(--card2);display:flex;align-items:center;justify-content:center">
              <?php $epav=$me_user['photoURL']??''; if($epav&&file_exists(AVATAR_DIR.basename($epav))):?>
              <img src="<?= h(AVATAR_URL.basename($epav)) ?>" alt="" style="width:100%;height:100%;object-fit:cover">
              <?php else:?><span style="font-size:32px;font-weight:800;color:var(--bolt)"><?= strtoupper(mb_substr(me()['displayName'],0,1)) ?></span><?php endif;?>
            </div>
            <div style="position:absolute;bottom:0;right:0;background:var(--ind);color:#fff;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid var(--card)"><span class="icon sm">photo_camera</span></div>
          </div>
          <input type="file" id="ep-av-input" name="avatar" accept="image/*" style="display:none" onchange="previewEpAv(this)">
        </div>
        <div class="form-group">
          <label class="form-label">Display Name</label>
          <input class="form-input" type="text" name="display_name" value="<?= h(me()['displayName']) ?>" maxlength="50" required>
        </div>
        <div class="form-group">
          <label class="form-label">Bio</label>
          <textarea class="form-input" name="bio" rows="3" maxlength="160"><?= h($me_user['bio']??'') ?></textarea>
        </div>
        <div style="display:flex;gap:10px">
          <button class="btn btn-primary" type="submit">Save Changes</button>
          <button class="btn btn-ghost" type="button" onclick="toggleEditProfile(false)">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Security -->
  <div class="settings-section">
    <div class="settings-title">Security</div>
    <div style="padding:22px">
      <form method="POST" action="?page=change_password">
        <input type="hidden" name="_t" value="<?= h(csrf()) ?>">
        <div class="form-group"><label class="form-label">Current Password</label><input class="form-input" type="password" name="old_pw" required></div>
        <div class="form-group"><label class="form-label">New Password</label><input class="form-input" type="password" name="new_pw" required minlength="6"></div>
        <button class="btn btn-primary btn-sm" type="submit">Update Password</button>
      </form>
    </div>
  </div>

  <!-- About -->
  <div class="settings-section">
    <div class="settings-title">About</div>
    <div class="settings-row"><span class="icon" style="color:var(--ind)">bolt</span><div class="settings-row-label"><div>HaiFlash</div><div class="settings-row-desc">Ignite Your Story · v2.1</div></div></div>
    <div class="settings-row" onclick="goTo('requests')"><span class="icon" style="color:var(--lav)">person_add</span><div class="settings-row-label">Follow Requests</div><span class="icon sm" style="color:var(--muted)">chevron_right</span></div>
    <div class="settings-row" onclick="location.href='?page=verification'"><span class="icon" style="color:var(--yellow)">verified</span><div class="settings-row-label">Request Verification</div><span class="icon sm" style="color:var(--muted)">chevron_right</span></div>
  </div>

  <!-- Danger Zone -->
  <div class="settings-section" style="border-color:rgba(255,69,58,.25)">
    <div class="settings-title" style="color:var(--red)">Danger Zone</div>
    <div class="settings-row" onclick="location.href='?page=delete_account'"><span class="icon" style="color:var(--red)">delete_forever</span><div class="settings-row-label" style="color:var(--red)">Delete Account</div><span class="icon sm" style="color:var(--muted)">chevron_right</span></div>
  </div>
</div><!-- /page-account -->

<!-- ─── DELETE ACCOUNT ───────────────────────────── -->
<div class="page-container <?= $page==='delete_account'?'active':'' ?>" id="page-delete_account">
  <button class="back-btn" onclick="goTo('account')"><span class="icon sm">arrow_back</span>Back to Settings</button>
  <div class="card" style="border-color:rgba(255,69,58,.3);max-width:480px;margin:0 auto;text-align:center">
    <span class="icon xl" style="color:var(--red);display:block;margin-bottom:10px">delete_forever</span>
    <h2 style="font-size:22px;font-weight:800;color:var(--red);margin-bottom:8px">Delete Account</h2>
    <p style="color:var(--muted);font-size:14px;margin-bottom:22px">This is permanent. All your posts and data will be erased forever.</p>
    <?php if(!empty($del_error??'')):?><div class="form-error"><?= h($del_error) ?></div><?php endif;?>
    <form method="POST" action="?page=delete_account">
      <input type="hidden" name="_t" value="<?= h(csrf()) ?>">
      <div class="form-group" style="text-align:left">
        <label class="form-label">Type your username to confirm: <strong style="color:var(--red)"><?= h(me()['username']) ?></strong></label>
        <input class="form-input" type="text" name="confirm" placeholder="<?= h(me()['username']) ?>" required style="border-color:rgba(255,69,58,.35)">
      </div>
      <button class="btn btn-danger btn-block" type="submit" onclick="return confirm('This cannot be undone. All data will be permanently deleted.')">Delete My Account Forever</button>
    </form>
  </div>
</div><!-- /page-delete_account -->

<!-- ─── VERIFICATION ─────────────────────────────── -->
<div class="page-container <?= $page==='verification'?'active':'' ?>" id="page-verification">
  <button class="back-btn" onclick="goTo('account')"><span class="icon sm">arrow_back</span>Back to Settings</button>
  <div class="card" style="max-width:480px;margin:0 auto;text-align:center">
    <span class="icon xl" style="color:var(--yellow);display:block;margin-bottom:14px">verified</span>
    <h2 style="font-size:22px;font-weight:800;margin-bottom:8px">Request Verification</h2>
    <p style="color:var(--muted);font-size:14px;margin-bottom:24px">Verified accounts get a badge and increased visibility. Requests are reviewed manually.</p>
    <button class="btn btn-primary btn-block" onclick="showToast('✓ Request submitted. We will review it soon.')">Submit Request</button>
  </div>
</div><!-- /page-verification -->

</div></main><!-- /main-inner /main-content -->

<!-- ── Mobile Tab Bar ────────────────────────────── -->
<nav class="tabbar">
  <button class="tab-btn <?= $page==='home'?'active':'' ?>"    onclick="goTo('home')"><span class="icon">home</span>Home</button>
  <button class="tab-btn <?= $page==='search'?'active':'' ?>"  onclick="goTo('search')"><span class="icon">search</span>Explore</button>
  <button class="tab-btn" onclick="openCreateModal()">
    <span class="icon lg" style="background:var(--ind);color:#fff;border-radius:14px;padding:6px;box-shadow:0 4px 16px rgba(88,86,214,.4);font-variation-settings:'FILL' 1,'wght' 600">bolt</span>
  </button>
  <button class="tab-btn <?= $page==='chat'?'active':'' ?>" onclick="goTo('chat')" style="position:relative">
    <span class="icon">mail</span>Chat
    <?php if($unread_dms>0):?><span class="tab-badge"><?=$unread_dms?></span><?php endif;?>
  </button>
  <button class="tab-btn <?= $page==='profile'?'active':'' ?>" onclick="goTo('profile')"><span class="icon">person</span>Me</button>
</nav>

<!-- ══ CREATE POST MODAL ════════════════════════════ -->
<div id="create-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.78);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);z-index:300;align-items:flex-end;justify-content:center" onclick="if(event.target===this)closeCreateModal()">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:22px 22px 0 0;width:100%;max-width:620px;animation:fadeUp .3s ease;max-height:92vh;overflow-y:auto">
    <div style="display:flex;align-items:center;padding:18px 22px;border-bottom:1px solid var(--border)">
      <h3 style="flex:1;font-size:17px;font-weight:800">New Post</h3>
      <button class="icon-btn" onclick="closeCreateModal()"><span class="icon">close</span></button>
    </div>
    <div style="padding:18px 22px">
      <div style="display:flex;gap:14px;align-items:flex-start">
        <?= av(me()['uid'],me()['displayName'],'46px') ?>
        <div style="flex:1">
          <div style="font-weight:700;font-size:15px;margin-bottom:6px;color:var(--lav2)"><?= h(me()['displayName']) ?></div>
          <textarea id="modal-post-body" style="width:100%;background:transparent;border:none;outline:none;color:var(--text);font-family:var(--font);font-size:17px;resize:none;min-height:130px;line-height:1.65" placeholder="What's igniting your world? ⚡" maxlength="500" oninput="updateModalCounter(this)"></textarea>
        </div>
      </div>
      <img id="modal-img-preview" style="width:100%;max-height:260px;object-fit:cover;border-radius:var(--radius-sm);margin-top:14px;display:none" alt="">
      <div style="display:flex;align-items:center;gap:10px;margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
        <label class="icon-btn" for="modal-img-input"><span class="icon" style="color:var(--ind)">image</span></label>
        <input type="file" id="modal-img-input" accept="image/*" style="display:none" onchange="previewModalImg(this)">
        <span style="font-size:13px;font-weight:700;color:var(--muted)" id="modal-counter">0/500</span>
        <div style="flex:1"></div>
        <button class="btn btn-primary" id="modal-post-btn" onclick="submitModalPost()"><span class="icon sm">bolt</span>Ignite</button>
      </div>
    </div>
  </div>
</div>

<!-- ─── ADMIN PANEL ──────────────────────────────────── -->
<?php
$admin_tab   = $_GET['tab'] ?? 'users';
$admin_saved = isset($_GET['saved']);
// Compute stats
$total_users  = count($all_users);
$total_posts  = count($all_posts);
$total_verified = count(array_filter($all_users, fn($u)=>($u['verified']??false)));
$total_comments = count($all_cmnts);
?>
<div class="page-container <?= $page==='admin_panel'?'active':'' ?>" id="page-admin_panel">
<?php if(!is_admin()): ?>
  <div class="admin-guard">
    <div>
      <span class="icon xl" style="color:var(--red);opacity:.5;display:block;margin-bottom:14px">lock</span>
      <h2 style="font-size:22px;font-weight:800;color:var(--red);margin-bottom:8px">Access Denied</h2>
      <p style="color:var(--muted);font-size:14px;margin-bottom:20px">You do not have permission to access the Admin Panel.</p>
      <button class="btn btn-ghost btn-sm" onclick="goTo('home')">Go Home</button>
    </div>
  </div>
<?php else: ?>

  <!-- Header -->
  <div style="display:flex;align-items:center;gap:14px;margin-bottom:28px;flex-wrap:wrap">
    <div style="width:46px;height:46px;background:rgba(255,204,2,.1);border:1.5px solid rgba(255,204,2,.3);border-radius:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <span class="icon" style="color:var(--yellow)">admin_panel_settings</span>
    </div>
    <div style="flex:1;min-width:0">
      <h1 style="font-size:24px;font-weight:800;letter-spacing:-.5px">Admin Panel</h1>
      <div style="font-size:13px;color:var(--muted);margin-top:1px">Logged in as <strong style="color:var(--lav2)"><?= h(me()['displayName']) ?></strong></div>
    </div>
    <?php if($admin_saved):?>
    <div style="background:rgba(52,199,121,.1);border:1px solid rgba(52,199,121,.3);color:var(--green);padding:8px 16px;border-radius:100px;font-size:13px;font-weight:700">
      <span class="icon sm">check_circle</span> Saved
    </div>
    <?php endif;?>
  </div>

  <!-- Stats Grid -->
  <div class="admin-stat-grid">
    <div class="admin-stat-card">
      <div class="admin-stat-num"><?= $total_users ?></div>
      <div class="admin-stat-label">Total Users</div>
    </div>
    <div class="admin-stat-card">
      <div class="admin-stat-num" style="color:var(--yellow)"><?= $total_verified ?></div>
      <div class="admin-stat-label">Verified</div>
    </div>
    <div class="admin-stat-card">
      <div class="admin-stat-num" style="color:var(--green)"><?= $total_posts ?></div>
      <div class="admin-stat-label">Posts</div>
    </div>
    <div class="admin-stat-card">
      <div class="admin-stat-num" style="color:var(--lav)"><?= $total_comments ?></div>
      <div class="admin-stat-label">Comments</div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="admin-tabs">
    <button class="admin-tab <?= $admin_tab==='users'?'active':'' ?>" onclick="switchAdminTab('users')">
      <span class="icon sm">group</span> Users (<?= $total_users ?>)
    </button>
    <button class="admin-tab <?= $admin_tab==='posts'?'active':'' ?>" onclick="switchAdminTab('posts')">
      <span class="icon sm">article</span> Posts (<?= $total_posts ?>)
    </button>
    <button class="admin-tab <?= $admin_tab==='verif'?'active':'' ?>" onclick="switchAdminTab('verif')">
      <span class="icon sm">verified</span> Verify (<?= count(array_filter($all_users,fn($u)=>!($u['verified']??false)&&$u['uid']!==me()['uid'])) ?>)
    </button>
  </div>

  <!-- ── USERS TAB ── -->
  <div id="admin-tab-users" style="display:<?= $admin_tab==='users'?'block':'none' ?>">
    <div style="position:relative">
      <span class="icon" style="position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--muted)">search</span>
      <input class="admin-search" type="text" id="admin-user-search" placeholder="Search users by name or @handle…" oninput="filterAdminUsers(this.value)">
    </div>
    <div id="admin-users-list">
      <?php foreach($all_users as $i => $au):
        $au_posts = count(array_filter($all_posts,fn($p)=>$p['userId']===$au['uid']));
        $is_self  = ($au['uid'] === me()['uid']);
      ?>
      <div class="admin-user-row" id="admin-u-<?= h($au['uid']) ?>" data-name="<?= h(strtolower($au['displayName'])) ?>" data-handle="<?= h(strtolower($au['username'])) ?>">
        <?= av($au['uid'],$au['displayName'],'46px') ?>
        <div class="admin-user-info">
          <div class="admin-user-name">
            <?= h($au['displayName']) ?>
            <?php if($au['verified']??false):?>
            <span class="admin-badge-verified"><span class="icon sm">verified</span>Verified</span>
            <?php else:?>
            <span class="admin-badge-unverified">Unverified</span>
            <?php endif;?>
            <?php if($is_self):?><span style="font-size:10px;background:rgba(88,86,214,.15);color:var(--lav);padding:2px 7px;border-radius:100px;font-weight:700">YOU</span><?php endif;?>
          </div>
          <div class="admin-user-meta">
            @<?= h($au['username']) ?> · <?= h($au['email']) ?> · <?= $au_posts ?> posts · <?= count($au['followers']??[]) ?> followers · Joined <?= date('M j, Y',strtotime($au['createdAt'])) ?>
          </div>
        </div>
        <div class="admin-actions">
          <?php if(!($au['verified']??false)):?>
          <button class="btn btn-sm btn-primary" style="background:rgba(255,204,2,.18);color:var(--yellow);box-shadow:none;border:1px solid rgba(255,204,2,.25)" onclick="adminVerify('<?= h($au['uid']) ?>','verify',this)">
            <span class="icon sm">verified</span>Verify
          </button>
          <?php else:?>
          <button class="btn btn-sm btn-ghost" style="color:var(--muted);border-color:var(--border)" onclick="adminVerify('<?= h($au['uid']) ?>','unverify',this)">
            <span class="icon sm">remove_moderator</span>Unverify
          </button>
          <?php endif;?>
          <button class="btn btn-sm" style="background:rgba(88,86,214,.12);color:var(--lav);border:1px solid var(--border)" onclick="location.href='?page=user_profile&uid=<?= h($au['uid']) ?>'">
            <span class="icon sm">open_in_new</span>View
          </button>
          <?php if(!$is_self):?>
          <button class="btn btn-sm btn-danger" onclick="adminDeleteUser('<?= h($au['uid']) ?>','<?= h(addslashes($au['displayName'])) ?>',this)">
            <span class="icon sm">delete</span>
          </button>
          <?php endif;?>
        </div>
      </div>
      <?php endforeach; if(empty($all_users)):?>
      <div class="empty-state"><span class="icon">group_off</span><p>No users yet.</p></div>
      <?php endif;?>
    </div>
  </div>

  <!-- ── POSTS TAB ── -->
  <div id="admin-tab-posts" style="display:<?= $admin_tab==='posts'?'block':'none' ?>">
    <div style="position:relative">
      <span class="icon" style="position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--muted)">search</span>
      <input class="admin-search" type="text" id="admin-post-search" placeholder="Search posts by content or author…" oninput="filterAdminPosts(this.value)">
    </div>
    <div id="admin-posts-list">
      <?php foreach($all_posts as $ap):
        $apu = get_user_by_uid($ap['userId']);
        $apc = count(array_filter($all_cmnts,fn($c)=>$c['postId']===$ap['id']));
      ?>
      <div class="admin-post-row" id="admin-p-<?= h($ap['id']) ?>" data-content="<?= h(strtolower($ap['content'])) ?>" data-author="<?= h(strtolower($ap['displayName'])) ?>">
        <div class="admin-post-author">
          <?= av($ap['userId'],$ap['displayName'],'36px') ?>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:700"><?= h($ap['displayName']) ?></div>
            <div style="font-size:11px;color:var(--muted)">@<?= h($ap['username']) ?> · <?= time_ago($ap['createdAt']) ?></div>
          </div>
          <?php if($ap['imageURL']):?>
          <span style="font-size:11px;color:var(--lav);background:rgba(144,144,224,.1);padding:2px 8px;border-radius:100px">📷 image</span>
          <?php endif;?>
        </div>
        <?php if($ap['content']):?>
        <div class="admin-post-body"><?= nl2br(h(mb_substr($ap['content'],0,220))) ?><?= mb_strlen($ap['content'])>220?'…':'' ?></div>
        <?php endif;?>
        <div class="admin-post-footer">
          <div class="admin-post-stats">
            <span><span class="icon sm" style="color:var(--pink);vertical-align:middle">favorite</span> <?= count($ap['likes']) ?></span>
            <span><span class="icon sm" style="color:var(--lav);vertical-align:middle">chat_bubble</span> <?= $apc ?></span>
            <span style="color:var(--muted)"><?= date('M j, Y',strtotime($ap['createdAt'])) ?></span>
          </div>
          <div style="display:flex;gap:7px">
            <button class="btn btn-sm" style="background:rgba(88,86,214,.12);color:var(--lav);border:1px solid var(--border)" onclick="location.href='?page=post&id=<?= h($ap['id']) ?>'">
              <span class="icon sm">open_in_new</span>View
            </button>
            <button class="btn btn-sm btn-danger" onclick="adminDeletePost('<?= h($ap['id']) ?>',this)">
              <span class="icon sm">delete</span>Delete
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; if(empty($all_posts)):?>
      <div class="empty-state"><span class="icon">article</span><p>No posts yet.</p></div>
      <?php endif;?>
    </div>
  </div>

  <!-- ── VERIFICATION TAB ── -->
  <div id="admin-tab-verif" style="display:<?= $admin_tab==='verif'?'block':'none' ?>">
    <div style="background:rgba(255,204,2,.06);border:1px solid rgba(255,204,2,.2);border-radius:var(--radius-sm);padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px">
      <span class="icon" style="color:var(--yellow)">info</span>
      <div style="font-size:13px;color:var(--text2)">
        Verify users to give them a <strong style="color:var(--lav2)">⚡ verified badge</strong>. Only grant this to real identities or notable accounts.
      </div>
    </div>

    <!-- Pending (unverified) users -->
    <?php $unverified = array_values(array_filter($all_users,fn($u)=>!($u['verified']??false)));
          $verified_u = array_values(array_filter($all_users,fn($u)=>($u['verified']??false)));?>

    <?php if(!empty($unverified)):?>
    <div class="section-label" style="margin-bottom:12px">Unverified Users (<?= count($unverified) ?>)</div>
    <?php foreach($unverified as $au):
      $au_posts=count(array_filter($all_posts,fn($p)=>$p['userId']===$au['uid']));
      $is_self=($au['uid']===me()['uid']);?>
    <div class="admin-user-row" id="vrow-<?= h($au['uid']) ?>">
      <?= av($au['uid'],$au['displayName'],'46px') ?>
      <div class="admin-user-info">
        <div class="admin-user-name">
          <?= h($au['displayName']) ?>
          <span class="admin-badge-unverified"><span class="icon sm">remove_moderator</span>Unverified</span>
          <?php if($is_self):?><span style="font-size:10px;background:rgba(88,86,214,.15);color:var(--lav);padding:2px 7px;border-radius:100px;font-weight:700">YOU</span><?php endif;?>
        </div>
        <div class="admin-user-meta">@<?= h($au['username']) ?> · <?= $au_posts ?> posts · <?= count($au['followers']??[]) ?> followers · Joined <?= date('M j, Y',strtotime($au['createdAt'])) ?></div>
      </div>
      <div class="admin-actions">
        <button class="btn btn-primary btn-sm" style="background:rgba(255,204,2,.2);color:var(--yellow);box-shadow:none;border:1px solid rgba(255,204,2,.3)" onclick="adminVerify('<?= h($au['uid']) ?>','verify',this)">
          <span class="icon sm">verified</span>Grant Verification
        </button>
      </div>
    </div>
    <?php endforeach;?>
    <?php else:?>
    <div class="empty-state" style="padding:32px 20px"><span class="icon">verified_user</span><p>All users are verified!</p></div>
    <?php endif;?>

    <?php if(!empty($verified_u)):?>
    <div class="section-label" style="margin:24px 0 12px">Currently Verified (<?= count($verified_u) ?>)</div>
    <?php foreach($verified_u as $au):
      $au_posts=count(array_filter($all_posts,fn($p)=>$p['userId']===$au['uid']));
      $is_self=($au['uid']===me()['uid']);?>
    <div class="admin-user-row" id="vrow2-<?= h($au['uid']) ?>">
      <?= av($au['uid'],$au['displayName'],'46px') ?>
      <div class="admin-user-info">
        <div class="admin-user-name">
          <?= h($au['displayName']) ?>
          <span class="admin-badge-verified"><span class="icon sm">verified</span>Verified</span>
          <?php if($is_self):?><span style="font-size:10px;background:rgba(88,86,214,.15);color:var(--lav);padding:2px 7px;border-radius:100px;font-weight:700">YOU</span><?php endif;?>
        </div>
        <div class="admin-user-meta">@<?= h($au['username']) ?> · <?= $au_posts ?> posts · <?= count($au['followers']??[]) ?> followers</div>
      </div>
      <div class="admin-actions">
        <button class="btn btn-ghost btn-sm" style="color:var(--red);border-color:rgba(255,69,58,.25)" onclick="adminVerify('<?= h($au['uid']) ?>','unverify',this)">
          <span class="icon sm">remove_moderator</span>Revoke
        </button>
      </div>
    </div>
    <?php endforeach;?>
    <?php endif;?>
  </div>

<?php endif; // is_admin ?>
</div><!-- /page-admin_panel -->

<!-- ══ LIVE BANNER ═════════════════════════════════ -->
<div class="live-banner" id="live-banner">
  <div class="live-banner-icon" id="lb-avatar"><span class="icon">bolt</span></div>
  <div class="live-banner-text">
    <div class="live-banner-title" id="lb-title"></div>
    <div class="live-banner-body"  id="lb-body"></div>
  </div>
  <button class="live-banner-close" onclick="closeLiveBanner()"><span class="icon sm">close</span></button>
</div>

<!-- ══ TOAST ═══════════════════════════════════════ -->
<div class="toast" id="toast"></div>

<?php endif; // end main app ?>

<!-- ════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════ -->
<script>
const ME_UID = '<?= logged_in()?h(me()['uid']):'' ?>';
const CSRF_T = '<?= h(csrf()) ?>';

// ─── ROUTER ──────────────────────────────────────
const JS_PAGES = new Set(['home','search','notifications','requests','profile','account','chat','admin_panel']);
function goTo(page, qs='') {
  if (!JS_PAGES.has(page)) { location.href='?page='+page+(qs?'&'+qs:''); return; }
  document.querySelectorAll('.page-container').forEach(p=>p.classList.remove('active'));
  const el = document.getElementById('page-'+page);
  if (el) el.classList.add('active');
  document.querySelectorAll('.nav-item,.tab-btn').forEach(b=>{
    const o = b.getAttribute('onclick')||'';
    b.classList.toggle('active', o.includes("'"+page+"'"));
  });
  history.pushState({page}, '', '?page='+page+(qs?'&'+qs:''));
  window.scrollTo(0,0);
}
window.addEventListener('popstate', e=>{
  if (e.state?.page) {
    if (!JS_PAGES.has(e.state.page)) { location.reload(); return; }
    document.querySelectorAll('.page-container').forEach(p=>p.classList.remove('active'));
    document.getElementById('page-'+e.state.page)?.classList.add('active');
  }
});

// ─── TOAST ───────────────────────────────────────
let _tt;
function showToast(msg, dur=3200){
  const t=document.getElementById('toast'); t.textContent=msg; t.classList.add('show');
  clearTimeout(_tt); _tt=setTimeout(()=>t.classList.remove('show'),dur);
}

// ─── FEED TABS ────────────────────────────────────
function switchTab(tab){
  document.getElementById('tab-foryou').classList.toggle('active', tab==='foryou');
  document.getElementById('tab-following').classList.toggle('active', tab==='following');
  document.getElementById('feed-for-you').style.display   = tab==='foryou'   ? '' : 'none';
  document.getElementById('feed-following').style.display = tab==='following' ? '' : 'none';
}
function switchPTab(tab){
  document.getElementById('ptab-posts').classList.toggle('active', tab==='posts');
  document.getElementById('ptab-saved').classList.toggle('active', tab==='saved');
  document.getElementById('ptab-panel-posts').style.display = tab==='posts' ? '' : 'none';
  document.getElementById('ptab-panel-saved').style.display = tab==='saved' ? '' : 'none';
}

// ─── QUICK POST ───────────────────────────────────
let quickImgFile = null;
function previewQuickImg(i){
  quickImgFile=i.files[0]; if(!quickImgFile)return;
  const r=new FileReader(); r.onload=e=>{const p=document.getElementById('quick-img-preview');p.src=e.target.result;p.style.display='block';}; r.readAsDataURL(quickImgFile);
}
function updateQuickCounter(el){
  const c=document.getElementById('quick-counter'); const l=el.value.length;
  c.textContent=l+'/500'; c.className='char-counter'+(l>450?' warn':'')+(l>480?' danger':'');
}
async function submitQuickPost(){
  const body=document.getElementById('quick-post-body').value.trim();
  if(!body&&!quickImgFile){showToast('Write something first!');return;}
  const btn=document.getElementById('quick-post-btn'); btn.disabled=true;
  btn.innerHTML='<span class="spinner"></span>';
  const fd=new FormData(); fd.append('_t',CSRF_T); fd.append('content',body);
  if(quickImgFile) fd.append('post_img',quickImgFile);
  try {
    const r=await fetch('?page=create_post',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
    const d=await r.json();
    if(d.ok){document.getElementById('quick-post-body').value='';document.getElementById('quick-img-preview').style.display='none';document.getElementById('quick-counter').textContent='0/500';quickImgFile=null;showToast('⚡ Post published!');setTimeout(()=>location.reload(),650);}
  } catch(e){showToast('Failed to post.');}
  btn.disabled=false; btn.innerHTML='<span class="icon sm">bolt</span>Post';
}

// ─── MODAL POST ───────────────────────────────────
let modalImgFile = null;
function openCreateModal(){document.getElementById('create-overlay').style.display='flex';setTimeout(()=>document.getElementById('modal-post-body').focus(),150);}
function closeCreateModal(){
  document.getElementById('create-overlay').style.display='none';
  document.getElementById('modal-post-body').value='';
  document.getElementById('modal-img-preview').style.display='none';
  document.getElementById('modal-counter').textContent='0/500';
  modalImgFile=null;
}
function previewModalImg(i){
  modalImgFile=i.files[0]; if(!modalImgFile)return;
  const r=new FileReader(); r.onload=e=>{const img=document.getElementById('modal-img-preview');img.src=e.target.result;img.style.display='block';}; r.readAsDataURL(modalImgFile);
}
function updateModalCounter(el){
  const c=document.getElementById('modal-counter'); const l=el.value.length;
  c.textContent=l+'/500'; c.className='char-counter'+(l>450?' warn':'')+(l>480?' danger':'');
}
async function submitModalPost(){
  const body=document.getElementById('modal-post-body').value.trim();
  if(!body&&!modalImgFile){showToast('Write something first!');return;}
  const btn=document.getElementById('modal-post-btn'); btn.disabled=true;
  btn.innerHTML='<span class="spinner"></span>';
  const fd=new FormData(); fd.append('_t',CSRF_T); fd.append('content',body);
  if(modalImgFile) fd.append('post_img',modalImgFile);
  try {
    const r=await fetch('?page=create_post',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
    const d=await r.json();
    if(d.ok){closeCreateModal();showToast('⚡ Post published!');setTimeout(()=>location.reload(),600);}
  } catch(e){showToast('Failed to post.');}
  btn.disabled=false; btn.innerHTML='<span class="icon sm">bolt</span>Ignite';
}

// ─── LIKE ─────────────────────────────────────────
async function toggleLike(pid){
  const fd=new FormData(); fd.append('post_id',pid);
  try {
    const r=await fetch('?page=like_post',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){
      ['like-btn-'+pid,'like-btn-detail'].forEach(id=>{
        const el=document.getElementById(id); if(!el)return;
        el.className='post-act'+(d.liked?' liked':'');
      });
      ['like-count-'+pid,'like-count-detail'].forEach(id=>{
        const el=document.getElementById(id); if(el)el.textContent=d.count;
      });
    }
  } catch(e){}
}

// ─── BOOKMARK ─────────────────────────────────────
async function toggleBookmark(pid){
  const fd=new FormData(); fd.append('post_id',pid);
  try {
    const r=await fetch('?page=bookmark',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){
      const btn=document.getElementById('bm-btn-'+pid);
      if(btn) btn.className='post-act'+(d.saved?' bookmarked':'');
      showToast(d.saved?'🔖 Saved to bookmarks':'Removed from bookmarks');
    }
  } catch(e){}
}

// ─── FOLLOW ───────────────────────────────────────
async function followUser(uid, btn){
  if(!btn)return;
  const fd=new FormData(); fd.append('target_uid',uid);
  try {
    const r=await fetch('?page=follow',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){
      if(d.action==='unfollowed'){
        btn.className=btn.className.replace('btn-ghost','btn-primary');
        btn.innerHTML='<span class="icon sm">person_add</span>Follow';
        showToast('Unfollowed');
      } else {
        btn.className=btn.className.replace('btn-primary','btn-ghost');
        btn.innerHTML='<span class="icon sm">check</span>Following';
        showToast('Following!');
      }
    }
  } catch(e){}
}

// ─── DELETE POST ──────────────────────────────────
async function deletePost(pid){
  if(!confirm('Delete this post permanently?'))return;
  const fd=new FormData(); fd.append('_t',CSRF_T); fd.append('post_id',pid);
  try {
    const r=await fetch('?page=delete_post',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){
      const el=document.getElementById('post-'+pid);
      if(el){el.style.opacity='0';el.style.transform='scale(.94)';el.style.transition='all .25s';setTimeout(()=>el.remove(),260);}
      showToast('Post deleted');
    }
  } catch(e){}
}

// ─── SHARE ────────────────────────────────────────
function sharePost(pid){
  const url=location.origin+location.pathname+'?page=post&id='+pid;
  if(navigator.share) navigator.share({title:'HaiFlash Post',url}).catch(()=>{});
  else navigator.clipboard.writeText(url).then(()=>showToast('🔗 Link copied!'));
}

// ─── MARK NOTIFS READ ─────────────────────────────
async function markAllRead(){
  const fd=new FormData(); fd.append('_t',CSRF_T);
  await fetch('?page=mark_notifs',{method:'POST',body:fd}).catch(()=>{});
  document.querySelectorAll('.notif-dot').forEach(d=>d.style.opacity='0');
  document.querySelectorAll('.nav-badge,.tab-badge').forEach(b=>b.remove());
  document.querySelectorAll('.notif-item.unread').forEach(n=>n.classList.remove('unread'));
}

// ─── EDIT PROFILE TOGGLE ──────────────────────────
function openEditProfile(){toggleEditProfile(true);goTo('account');}
function toggleEditProfile(show){
  document.getElementById('edit-profile-form').style.display        = show ? '' : 'none';
  document.getElementById('edit-profile-placeholder').style.display = show ? 'none' : '';
}
function previewEpAv(i){
  if(!i.files[0])return;
  const r=new FileReader(); r.onload=e=>{ const el=document.getElementById('ep-av-preview'); el.innerHTML='<img src="'+e.target.result+'" style="width:100%;height:100%;object-fit:cover">'; }; r.readAsDataURL(i.files[0]);
}
function previewEpCover(i){
  if(!i.files[0])return;
  const r=new FileReader(); r.onload=e=>{const img=document.getElementById('ep-cover-preview');img.src=e.target.result;img.style.display='block';}; r.readAsDataURL(i.files[0]);
}
function submitProfileFile(field){
  const form=document.getElementById('quick-profile-form'); if(!form)return;
  const input=document.getElementById(field+'-input'); if(!input||!input.files[0])return;
  const r=new FileReader();
  r.onload=e=>{
    if(field==='cover'){const img=document.getElementById('cover-preview-img');if(img){img.src=e.target.result;img.style.display='block';}}
    else{const inner=document.querySelector('#page-profile .profile-av-inner');if(inner)inner.innerHTML='<img src="'+e.target.result+'" style="width:100%;height:100%;object-fit:cover;border-radius:50%">';}
  };
  r.readAsDataURL(input.files[0]);
  showToast('Uploading…'); form.submit();
}

// ─── POST NOTIF TOGGLE ────────────────────────────
async function togglePostNotif(uid, btn){
  const fd=new FormData(); fd.append('target_uid',uid);
  try {
    const r=await fetch('?page=toggle_post_notif',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){
      if(d.subscribed){
        btn.className='notif-bell-btn on';
        btn.innerHTML='<span class="icon sm">notifications_active</span>Notified';
        showToast('🔔 Post notifications on');
        if(Notification&&Notification.permission==='default') Notification.requestPermission();
      } else {
        btn.className='notif-bell-btn';
        btn.innerHTML='<span class="icon sm">notifications</span>Notify';
        showToast('🔕 Notifications off');
      }
    }
  } catch(e){showToast('Could not update.');}
}

// ─── LIVE BANNER ──────────────────────────────────
const knownPostIds = new Set([<?php echo implode(',', array_map(fn($p)=>'"'.h($p['id']).'"', $all_posts)); ?>]);
let _bt;
function showLiveBanner(avUrl, title, body){
  const b=document.getElementById('live-banner');
  const lba=document.getElementById('lb-avatar');
  if(avUrl) lba.innerHTML='<img src="'+avUrl+'" style="width:100%;height:100%;object-fit:cover;border-radius:50%" alt="">';
  else lba.innerHTML='<span class="icon" style="font-variation-settings:\'FILL\' 1,\'wght\' 600;color:var(--bolt)">bolt</span>';
  document.getElementById('lb-title').textContent = title;
  document.getElementById('lb-body').textContent  = body||'';
  b.classList.add('show');
  clearTimeout(_bt); _bt=setTimeout(closeLiveBanner, 6500);
}
function closeLiveBanner(){ document.getElementById('live-banner').classList.remove('show'); }

// Poll for new posts every 12s
<?php if(logged_in()): ?>
setInterval(async()=>{
  try {
    const r=await fetch('?page=poll_feed',{headers:{'X-Requested-With':'XMLHttpRequest'}});
    const d=await r.json(); if(!d.posts)return;
    d.posts.forEach(p=>{
      if(!knownPostIds.has(p.id) && p.userId!==ME_UID){
        knownPostIds.add(p.id);
        const avUrl = p.avatar_file ? ('<?= h(AVATAR_URL) ?>'+p.avatar_file) : '';
        const preview = (p.body||'').substring(0,90);
        showLiveBanner(avUrl, p.displayName+' just posted ⚡', preview);
        if(p.subscribed && Notification.permission==='granted'){
          const n=new Notification('⚡ '+p.displayName+' posted',{body:preview||'New post',icon:avUrl,tag:'hf-post-'+p.id});
          n.onclick=()=>{window.focus();location.href='?page=post&id='+p.id;n.close();};
        }
      }
    });
  } catch(e){}
}, 12000);
<?php endif; ?>

// ─── ADMIN PANEL ──────────────────────────────────
function switchAdminTab(tab) {
  ['users','posts','verif'].forEach(t => {
    const panel = document.getElementById('admin-tab-'+t);
    const btn   = document.querySelector('.admin-tab[onclick="switchAdminTab(\''+t+'\')"]');
    if (panel) panel.style.display = (t===tab) ? 'block' : 'none';
    if (btn)   btn.classList.toggle('active', t===tab);
  });
}

function filterAdminUsers(q) {
  const lq = q.trim().toLowerCase();
  document.querySelectorAll('#admin-users-list .admin-user-row').forEach(row => {
    const name   = row.dataset.name   || '';
    const handle = row.dataset.handle || '';
    row.style.display = (!lq || name.includes(lq) || handle.includes(lq)) ? '' : 'none';
  });
}

function filterAdminPosts(q) {
  const lq = q.trim().toLowerCase();
  document.querySelectorAll('#admin-posts-list .admin-post-row').forEach(row => {
    const content = row.dataset.content || '';
    const author  = row.dataset.author  || '';
    row.style.display = (!lq || content.includes(lq) || author.includes(lq)) ? '' : 'none';
  });
}

async function adminVerify(uid, action, btn) {
  const original = btn.innerHTML;
  btn.disabled   = true;
  btn.innerHTML  = '<span class="spinner"></span>';
  const fd = new FormData();
  fd.append('_t', CSRF_T);
  fd.append('target_uid', uid);
  fd.append('action', action);
  try {
    const r = await fetch('?page=admin_verify', { method:'POST', body:fd });
    const d = await r.json();
    if (d.ok) {
      // Update ALL badge instances for this user on the page
      ['admin-u-'+uid, 'vrow-'+uid, 'vrow2-'+uid].forEach(id => {
        const row = document.getElementById(id);
        if (!row) return;
        // Swap badge
        const badge = row.querySelector('.admin-badge-verified, .admin-badge-unverified');
        if (badge) {
          if (d.verified) {
            badge.className  = 'admin-badge-verified';
            badge.innerHTML  = '<span class="icon sm">verified</span>Verified';
          } else {
            badge.className  = 'admin-badge-unverified';
            badge.innerHTML  = '<span class="icon sm">remove_moderator</span>Unverified';
          }
        }
        // Swap verify/unverify button
        if (d.verified) {
          btn.style.background = 'rgba(99,99,122,.1)';
          btn.style.color      = 'var(--muted)';
          btn.style.border     = '1px solid rgba(99,99,122,.2)';
          btn.innerHTML        = '<span class="icon sm">remove_moderator</span>Unverify';
          btn.setAttribute('onclick', "adminVerify('"+uid+"','unverify',this)");
        } else {
          btn.style.background = 'rgba(255,204,2,.18)';
          btn.style.color      = 'var(--yellow)';
          btn.style.border     = '1px solid rgba(255,204,2,.25)';
          btn.innerHTML        = '<span class="icon sm">verified</span>Verify';
          btn.setAttribute('onclick', "adminVerify('"+uid+"','verify',this)");
        }
      });
      showToast(d.verified ? '✅ User verified!' : '🔕 Verification revoked');
    } else { btn.disabled=false; btn.innerHTML=original; showToast('Error — try again'); }
  } catch(e) { btn.disabled=false; btn.innerHTML=original; showToast('Network error'); }
  btn.disabled = false;
}

async function adminDeleteUser(uid, name, btn) {
  if (!confirm('Permanently delete user "'+name+'" and all their posts?\n\nThis cannot be undone.')) return;
  const fd = new FormData();
  fd.append('_t', CSRF_T);
  fd.append('target_uid', uid);
  try {
    const r = await fetch('?page=admin_delete_user', { method:'POST', body:fd });
    const d = await r.json();
    if (d.ok) {
      const row = document.getElementById('admin-u-'+uid);
      if (row) { row.style.opacity='0'; row.style.transition='opacity .25s'; setTimeout(()=>row.remove(),260); }
      showToast('🗑️ User deleted');
    } else showToast('Error — try again');
  } catch(e) { showToast('Network error'); }
}

async function adminDeletePost(pid, btn) {
  if (!confirm('Permanently delete this post?')) return;
  const original = btn.innerHTML;
  btn.disabled   = true;
  btn.innerHTML  = '<span class="spinner"></span>';
  const fd = new FormData();
  fd.append('_t', CSRF_T);
  fd.append('post_id', pid);
  try {
    const r = await fetch('?page=admin_delete_post', { method:'POST', body:fd });
    const d = await r.json();
    if (d.ok) {
      const row = document.getElementById('admin-p-'+pid);
      if (row) { row.style.opacity='0'; row.style.transition='opacity .25s'; setTimeout(()=>row.remove(),260); }
      showToast('🗑️ Post deleted');
    } else { btn.disabled=false; btn.innerHTML=original; showToast('Error — try again'); }
  } catch(e) { btn.disabled=false; btn.innerHTML=original; showToast('Network error'); }
}

// ─── INIT ─────────────────────────────────────────
document.addEventListener('DOMContentLoaded',()=>{
  // Scroll DM to bottom
  const dm=document.getElementById('dm-messages'); if(dm) dm.scrollTop=dm.scrollHeight;

  <?php if(isset($_GET['posted'])):?>showToast('⚡ Post published!');<?php endif;?>

  <?php $mf=get_user_by_uid(me()['uid'])??me(); if(!empty($mf['postNotifs']??[])):?>
  if(Notification && Notification.permission==='default') Notification.requestPermission();
  <?php endif;?>

  // Keyboard shortcut: N = new post
  document.addEventListener('keydown',e=>{
    if(e.key==='n' && !e.ctrlKey && !e.metaKey && document.activeElement.tagName!=='INPUT' && document.activeElement.tagName!=='TEXTAREA'){
      openCreateModal();
    }
  });
});
</script>
</body>
</html>