<?php
session_start();
date_default_timezone_set("Asia/Kolkata");

define('SIGN_SECRET',            'YOUR_SECRET_TOKEN_HERE');
define('SIGNED_TTL',             7200);
define('LOG_DEDUPE_WINDOW',      5);
define('ROUTER_NAME',            'v.php');

define('ROUTER_ENABLED',         true);
define('ROUTE_CREATION_ENABLED', false);
define('ACCESS_LOG_ENABLED',     true);
define('SECURITY_LOG_ENABLED',   true);
define('TOKEN_SECURITY_ENABLED', true);
define('BOT_PROTECTION_ENABLED', true);
define('AUTO_SIGN_REDIRECT',     true);

define('URL_MODE',               'short');
define('SHORT_BASE',             '/');

define('RATE_LIMIT_MAX',         40);
define('RATE_LIMIT_WINDOW',      60);
define('TOKEN_REPLAY_WINDOW',    7200);
define('MAX_UNIQUE_IP_TRACK',    100);
define('STEALTH_BAN_TIME',       900);
define('BOT_SCORE_BASE',         60);
define('JSON_MAX_AGE',           604800);

define('SIGNED_EXTRA_PARAMS',    array('file','page','lang','mode'));

define('CLEAN_U', 86400);
define('CLEAN_K', 604800);
define('CLEAN_B', 1209600);
define('CLEAN_R', 3600);

define('CP_PASSWORD', 'admin1234');
define('CP_PATH',     '__cp__');

if (!ROUTER_ENABLED) {
    http_response_code(503);
    exit('Router disabled');
}

$runtimeDir  = __DIR__ . '/.runtime';
if (!is_dir($runtimeDir)) {
    mkdir($runtimeDir, 0777, true);
}

$mapFile     = $runtimeDir . '/m.json';
$accessLog   = $runtimeDir . '/a.log';
$securityLog = $runtimeDir . '/s.log';
$ipFailFile  = $runtimeDir . '/r.json';
$tokenFile   = $runtimeDir . '/u.json';
$botFile     = $runtimeDir . '/b.json';
$stealthFile = $runtimeDir . '/x.json';
$leakFile    = $runtimeDir . '/k.json';
$errorPage   = __DIR__ . '/error.html';

if (!isset($_SESSION['VISITOR_ID'])) {
    $_SESSION['VISITOR_ID'] = 'VIS-' . substr(md5(uniqid('', true)), 0, 6);
}
$VISITOR_ID = $_SESSION['VISITOR_ID'];


function client_ip() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))  return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
}

function ua_short() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (stripos($ua, 'Edg')     !== false) return 'Edge';
    if (stripos($ua, 'Chrome')  !== false) return 'Chrome';
    if (stripos($ua, 'Firefox') !== false) return 'Firefox';
    if (stripos($ua, 'Safari')  !== false) return 'Safari';
    return 'Other';
}

function json_clean_load($file) {
    if (!file_exists($file)) return array();
    $d = json_decode(file_get_contents($file), true);
    if (!is_array($d)) return array();
    $now = time();
    foreach ($d as $k => $v) {
        if (is_array($v) && isset($v['ts']) && ($now - $v['ts']) > JSON_MAX_AGE) unset($d[$k]);
        elseif (!is_array($v) && is_numeric($v) && ($now - $v) > JSON_MAX_AGE)   unset($d[$k]);
    }
    file_put_contents($file, json_encode($d), LOCK_EX);
    return $d;
}

function json_clean_per_file($file, $maxAge) {
    if (!file_exists($file)) return array();
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return array();
    $now = time(); $changed = false;
    foreach ($data as $k => $v) {
        $old = false;
        if (is_array($v) && isset($v['ts']))    $old = ($now - $v['ts']) > $maxAge;
        elseif (!is_array($v) && is_numeric($v)) $old = ($now - $v) > $maxAge;
        if ($old) { unset($data[$k]); $changed = true; }
    }
    if ($changed) file_put_contents($file, json_encode($data), LOCK_EX);
    return $data;
}

function write_block($file, $kv) {
    $txt = "\n" . str_repeat('=', 50) . "\n";
    foreach ($kv as $k => $v) $txt .= str_pad($k, 14) . ' : ' . $v . "\n";
    $txt .= str_repeat('=', 50) . "\n";
    file_put_contents($file, $txt, FILE_APPEND | LOCK_EX);
}

function log_once($file, $kv) {
    $h    = hash('sha256', json_encode($kv));
    $now  = time();
    $last = isset($_SESSION['LAST_LOG']) ? $_SESSION['LAST_LOG'] : null;
    if ($last && isset($last['h'], $last['t']) && $last['h'] === $h && ($now - $last['t']) <= LOG_DEDUPE_WINDOW) return;
    $_SESSION['LAST_LOG'] = array('h' => $h, 't' => $now);
    write_block($file, $kv);
}

function bot_score_add($ip, $p) {
    global $botFile;
    $d = json_clean_load($botFile);
    if (!isset($d[$ip])) $d[$ip] = array('score' => 0, 'ts' => time());
    $d[$ip]['score'] += $p;
    $d[$ip]['ts']     = time();
    file_put_contents($botFile, json_encode($d), LOCK_EX);
    return $d[$ip]['score'];
}

function bot_score_get($ip) {
    global $botFile;
    $d = json_clean_load($botFile);
    return isset($d[$ip]['score']) ? $d[$ip]['score'] : 0;
}

function adaptive_bot_threshold() {
    global $botFile;
    $d = json_clean_load($botFile);
    if (empty($d)) return BOT_SCORE_BASE;
    $scores = array_column($d, 'score');
    sort($scores);
    $mid = isset($scores[floor(count($scores) / 2)]) ? $scores[floor(count($scores) / 2)] : BOT_SCORE_BASE;
    return max(BOT_SCORE_BASE, $mid * 1.5);
}

function stealth_check_ip($ip) {
    global $stealthFile, $errorPage;
    $d = json_clean_load($stealthFile);
    if (isset($d[$ip]) && $d[$ip] > time()) {
        http_response_code(404);
        if (file_exists($errorPage)) readfile($errorPage); else echo 'Not Found';
        exit;
    }
}

function stealth_add_ip($ip) {
    global $stealthFile;
    $d      = json_clean_load($stealthFile);
    $d[$ip] = time() + STEALTH_BAN_TIME;
    file_put_contents($stealthFile, json_encode($d), LOCK_EX);
}

function ip_rate_ok($ip) {
    global $ipFailFile;
    $d   = json_clean_load($ipFailFile);
    $now = time();
    if (!isset($d[$ip])) $d[$ip] = array();
    $d[$ip] = array_values(array_filter($d[$ip], function($t) use ($now) {
        return ($now - $t) < RATE_LIMIT_WINDOW;
    }));
    file_put_contents($ipFailFile, json_encode($d), LOCK_EX);
    return count($d[$ip]) < RATE_LIMIT_MAX;
}

function ip_rate_add($ip) {
    global $ipFailFile;
    $d       = json_clean_load($ipFailFile);
    $d[$ip][] = time();
    file_put_contents($ipFailFile, json_encode($d), LOCK_EX);
}

function token_used_check($sig) {
    global $tokenFile, $securityLog, $VISITOR_ID;
    $d = json_clean_load($tokenFile);
    if (!isset($d[$sig])) return false;
    $rec = $d[$sig];
    if (!is_array($rec))               return false;
    if (time() > (isset($rec['exp']) ? $rec['exp'] : 0)) return false;
    if (isset($rec['sid']) && $rec['sid'] === session_id()) {
        if (isset($rec['ip']) && $rec['ip'] !== client_ip()) {
            bot_score_add($rec['ip'], 1);
            bot_score_add(client_ip(), 2);
        }
        $curUA = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        if (isset($rec['ua']) && $rec['ua'] !== $curUA) {
            bot_score_add(client_ip(), 3);
            token_leak_track($sig, client_ip());
            log_once($securityLog, array(
                'TIME'       => date('Y-m-d H:i:s'),
                'SESSION'    => $VISITOR_ID,
                'IP'         => client_ip(),
                'EVENT'      => 'DEVICE_CHANGE_DETECTED',
                'OLD_DEVICE' => $rec['ua'],
                'NEW_DEVICE' => $curUA,
            ));
        }
        $d[$sig]['rc'] = (isset($rec['rc']) ? $rec['rc'] : 0) + 1;
        $d[$sig]['ts'] = time();
        file_put_contents($tokenFile, json_encode($d), LOCK_EX);
        return $d[$sig]['rc'] > 20;
    }
    return true;
}

function token_used_add($sig) {
    global $tokenFile;
    $d = json_clean_load($tokenFile);
    if (!isset($d[$sig])) {
        $d[$sig] = array(
            'exp' => time() + TOKEN_REPLAY_WINDOW,
            'ip'  => client_ip(),
            'sid' => session_id(),
            'ua'  => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
            'ts'  => time(),
            'rc'  => 0,
        );
        file_put_contents($tokenFile, json_encode($d), LOCK_EX);
    }
}

function token_leak_track($sig, $ip) {
    global $leakFile;
    $d = json_clean_load($leakFile);
    if (!isset($d[$sig])) $d[$sig] = array('ips' => array(), 'ts' => time());
    if (!in_array($ip, $d[$sig]['ips'])) $d[$sig]['ips'][] = $ip;
    file_put_contents($leakFile, json_encode($d), LOCK_EX);
    return count($d[$sig]['ips']);
}

function get_net_info($ip) {
    if (!empty($_SESSION['NET_INFO'])
        && $_SESSION['NET_INFO']['ip'] === $ip
        && (time() - (isset($_SESSION['NET_INFO']['ts']) ? $_SESSION['NET_INFO']['ts'] : 0)) < 86400) {
        return $_SESSION['NET_INFO'];
    }
    $res = array('ip' => $ip, 'country' => 'Unknown', 'city' => 'Unknown',
                 'isp' => 'Unknown ISP', 'proxy' => false, 'hosting' => false, 'mobile' => false, 'ts' => time());
    $r = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,city,isp,proxy,hosting,mobile");
    if ($r) {
        $d = @json_decode($r, true);
        if (!empty($d) && isset($d['status']) && $d['status'] === 'success') {
            foreach (array('country','city','isp') as $f) if (!empty($d[$f])) $res[$f] = $d[$f];
            $res['proxy']   = !empty($d['proxy']);
            $res['hosting'] = !empty($d['hosting']);
            $res['mobile']  = !empty($d['mobile']);
        }
    }
    $_SESSION['NET_INFO'] = $res;
    return $res;
}

function build_sig_base($params) {
    ksort($params);
    return http_build_query($params);
}

function sign_url($id, $extra = array(), $ttl = SIGNED_TTL) {
    $clean = array();
    foreach (SIGNED_EXTRA_PARAMS as $k) {
        if (isset($extra[$k])) $clean[$k] = $extra[$k];
    }
    $params = array_merge(array('id' => $id, 'exp' => time() + $ttl, 'router' => ROUTER_NAME), $clean);
    $base   = build_sig_base($params);
    $sig    = hash_hmac('sha256', $base, SIGN_SECRET);
    if (URL_MODE === 'short') {
        $payload = $base . '&sig=' . $sig;
        $token   = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        return SHORT_BASE . '?r=' . $token;
    }
    return '/' . ROUTER_NAME . '?' . $base . '&sig=' . $sig;
}

function decode_short_token($token) {
    $pad = str_repeat('=', (4 - strlen($token) % 4) % 4);
    $raw = base64_decode(strtr($token, '-_', '+/') . $pad);
    if ($raw === false) return null;
    parse_str($raw, $p);
    return (count($p) > 0) ? $p : null;
}

function verify_signature_details($params = array()) {
    if (empty($params)) $params = $_GET;
    $reasons = array();
    $now     = time();
    if (!isset($params['sig']) || !isset($params['exp'])) {
        return array('ok' => false, 'reasons' => array('missing_sig_or_exp'));
    }
    if ((int)$params['exp'] < $now)            $reasons[] = 'expired';
    if (!isset($params['router']))             $reasons[] = 'missing_router_field';
    elseif ($params['router'] !== ROUTER_NAME) $reasons[] = 'router_mismatch';
    $allowed = array_merge(array('id','exp','router'), SIGNED_EXTRA_PARAMS);
    $data    = array();
    foreach ($allowed as $k) if (isset($params[$k])) $data[$k] = $params[$k];
    $sig  = $params['sig'];
    $base = build_sig_base($data);
    $calc = hash_hmac('sha256', $base, SIGN_SECRET);
    if (!hash_equals($calc, $sig)) $reasons[] = 'signature_mismatch';
    return array('ok' => empty($reasons), 'reasons' => $reasons);
}

function classify_access_type($ev, $routeId) {
    $now  = time();
    $last = isset($_SESSION['LAST_CLASSIFY']) ? $_SESSION['LAST_CLASSIFY'] : null;
    $type = 'USER_ACTION';
    if (strpos($ev, 'REDIRECT') !== false) $type = 'REDIRECT_CHAIN';
    if ($routeId === '0' && strpos($ev, 'SIMPLE_ID_REDIRECT') !== false
        && isset($last['route']) && $last['route'] !== '0') $type = 'SESSION_EXPIRE';
    if ($last) {
        $diff = $now - (isset($last['time']) ? $last['time'] : 0);
        if (isset($last['route']) && $last['route'] === '0'       && $routeId !== '0' && $diff <= 3)  $type = 'BACKGROUND_FETCH';
        if (isset($last['route']) && $last['route'] === $routeId  && $diff <= 2)                      $type = 'AUTO_BROWSER';
        if (isset($last['route']) && $last['route'] === $routeId  && $diff > 2 && $diff <= 20)        $type = 'BACKGROUND_FETCH';
    }
    $_SESSION['LAST_CLASSIFY'] = array('route' => $routeId, 'time' => $now, 'event' => $ev);
    return $type;
}

function set_pending_log($d)  { $_SESSION['PENDING_LOG'] = $d; }
function get_pending_log()    { return isset($_SESSION['PENDING_LOG']) ? $_SESSION['PENDING_LOG'] : null; }
function clear_pending_log()  { unset($_SESSION['PENDING_LOG']); }

function normalize_rel_path($path) {
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^\./#', '', $path);
    $path = preg_replace('#/+#', '/', $path);
    return trim($path, '/');
}

function safe_realpath_within($baseDir, $relPath) {
    $c = realpath($baseDir . '/' . ltrim($relPath, '/'));
    if ($c === false) return false;
    $b = realpath($baseDir);
    if ($b === false || strpos($c, $b) !== 0) return false;
    return $c;
}

function resolve_directory_index($absPath) {
    if (!is_dir($absPath)) return $absPath;
    foreach (array('index.php', 'index.html', 'index.htm') as $i) {
        $t = $absPath . DIRECTORY_SEPARATOR . $i;
        if (file_exists($t) && !is_dir($t)) return $t;
    }
    return false;
}

function load_map($file) {
    if (!file_exists($file)) return array();
    $d = @json_decode(@file_get_contents($file), true);
    if (!is_array($d)) return array();
    foreach ($d as $k => $v) {
        if (is_string($v)) {
            $d[$k] = array('url' => $v, 'count' => 0, 'last_used' => 0,
                           'daily' => array(), 'unique_ips' => array(), 'access_types' => array(), 'bot_hits' => 0);
        }
        foreach (array('daily','unique_ips','access_types') as $f) {
            if (!isset($d[$k][$f])) $d[$k][$f] = array();
        }
        if (!isset($d[$k]['bot_hits'])) $d[$k]['bot_hits'] = 0;
    }
    return $d;
}

function save_map($file, $map) {
    file_put_contents($file, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function update_route_metrics(&$map, $mapFile, $id, $ip, $accessType) {
    if (!isset($map[$id])) return;
    $today = date('Y-m-d');
    $map[$id]['count']++;
    $map[$id]['last_used'] = time();
    if (!isset($map[$id]['daily'][$today])) $map[$id]['daily'][$today] = 0;
    $map[$id]['daily'][$today]++;
    if (!in_array($ip, $map[$id]['unique_ips'])) {
        $map[$id]['unique_ips'][] = $ip;
        if (count($map[$id]['unique_ips']) > MAX_UNIQUE_IP_TRACK) array_shift($map[$id]['unique_ips']);
    }
    if (!isset($map[$id]['access_types'][$accessType])) $map[$id]['access_types'][$accessType] = 0;
    $map[$id]['access_types'][$accessType]++;
    if (bot_score_get($ip) > adaptive_bot_threshold()) $map[$id]['bot_hits']++;
    save_map($mapFile, $map);
}

function respond_error_and_log($label, $info) {
    global $securityLog, $errorPage, $VISITOR_ID, $IP, $NET, $UA_SHORT, $UA_FULL;
    $ip_  = isset($IP)       ? $IP       : client_ip();
    $uas_ = isset($UA_SHORT) ? $UA_SHORT : 'Other';
    $uaf_ = isset($UA_FULL)  ? $UA_FULL  : (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown');
    $net_ = isset($NET)      ? $NET      : array('country'=>'Unknown','city'=>'Unknown','isp'=>'Unknown ISP','proxy'=>false,'hosting'=>false,'mobile'=>false);
    $vis_ = isset($VISITOR_ID) ? $VISITOR_ID : 'unknown';
    $full = array(
        'TIME'     => date('Y-m-d H:i:s'),
        'SESSION'  => $vis_,
        'IP'       => $ip_,
        'DEVICE'   => $uaf_,
        'LOCATION' => $net_['country'] . ', ' . $net_['city'],
        'ISP'      => $net_['isp'],
        'BROWSER'  => $uas_,
        'NETWORK'  => ($net_['proxy'] ? 'VPN/Proxy' : ($net_['hosting'] ? 'Hosting' : ($net_['mobile'] ? 'Mobile' : 'Normal'))),
        'EVENT'    => $label,
    );
    $full = array_merge($full, $info);
    if (SECURITY_LOG_ENABLED) log_once($securityLog, $full);
    http_response_code(404);
    if (file_exists($errorPage)) readfile($errorPage); else echo 'Not Found';
    exit;
}

/* =============================================================
   AUTO JSON CLEAN
   ============================================================= */
json_clean_per_file($runtimeDir . '/u.json', CLEAN_U);
json_clean_per_file($runtimeDir . '/k.json', CLEAN_K);
json_clean_per_file($runtimeDir . '/b.json', CLEAN_B);
json_clean_per_file($runtimeDir . '/r.json', CLEAN_R);

/* =============================================================
   SVG ICON LIBRARY
   ============================================================= */
function svg($name, $size = 16) {
    $s = (int)$size;
    static $icons = null;
    if ($icons === null) {
        $icons = array(
            'dashboard' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
            'routemap'  => '<polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/>',
            'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'log'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
            'tools'     => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
            'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
            'plus'      => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'edit'      => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
            'trash'     => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
            'refresh'   => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
            'ban'       => '<circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>',
            'check'     => '<polyline points="20 6 9 17 4 12"/>',
            'copy'      => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
            'link'      => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'activity'  => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
            'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'clock'     => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'globe'     => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
            'zap'       => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
            'warning'   => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'save'      => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
            'key'       => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
            'server'    => '<rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>',
            'cpu'       => '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/>',
        );
    }
    if (!isset($icons[$name])) return '';
    return '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0">' . $icons[$name] . '</svg>';
}

function cp_css() {
    $out  = '<style>';
    $out .= '*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}';
    $out .= ':root{';
    $out .= '--n950:#f5f0e8;--n900:#ede8de;--n850:#e4ddd2;--n800:#d8d0c4;--n750:#cec5b8;';
    $out .= '--n700:#c4baac;--n600:#b8ad9e;--n500:#a89e8e;';
    $out .= '--accent:#1a2e5a;--accent-h:#2a4a8a;--teal:#b8972a;';
    $out .= '--red:#c0392b;--amber:#c9860a;--green:#1e7e44;';
    $out .= '--text:#0f1d38;--text-2:#3a4f70;--text-3:#7a8fa8;';
    $out .= '--border:rgba(26,46,90,0.15);--border-h:rgba(26,46,90,0.35);';
    $out .= '--card:rgba(255,252,245,0.92);--card2:rgba(240,236,226,0.97);';
    $out .= '--gold:#c9a84c;--gold-h:#e2c06a;';
    $out .= '--r:7px;--font:"Inter","Segoe UI",system-ui,sans-serif;}';
    $out .= 'html,body{height:100%;}';
    $out .= 'body{background:var(--n950);background-image:radial-gradient(ellipse 70% 50% at 15% 10%,rgba(26,46,90,0.08) 0,transparent 100%),radial-gradient(ellipse 50% 40% at 85% 90%,rgba(201,168,76,0.06) 0,transparent 100%);color:var(--text);font-family:var(--font);font-size:13.5px;line-height:1.55;}';
    $out .= 'a{color:var(--accent);text-decoration:none;}a:hover{color:var(--accent-h);}';
    $out .= 'code{font-family:"Cascadia Code","Fira Mono","Consolas",monospace;font-size:12px;}';
    /* Shell */
    $out .= '.shell{display:flex;height:100vh;overflow:hidden;}';
    /* Sidebar */
    $out .= '.sidebar{width:224px;flex-shrink:0;background:var(--n900);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;}';
    $out .= '.sb-brand{padding:18px 18px 14px;border-bottom:1px solid var(--border);}';
    $out .= '.sb-brand-row{display:flex;align-items:center;gap:10px;}';
    $out .= '.sb-icon{width:32px;height:32px;border-radius:7px;background:linear-gradient(135deg,var(--accent),var(--accent-h));display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0;}';
    $out .= '.sb-name{font-size:14px;font-weight:700;color:var(--accent);letter-spacing:.2px;}';
    $out .= '.sb-sub{font-size:11px;color:var(--text-3);margin-top:1px;}';
    $out .= '.sb-nav{flex:1;padding:8px 0;overflow-y:auto;}';
    $out .= '.nav-lbl{font-size:10px;font-weight:700;letter-spacing:.9px;color:var(--text-3);text-transform:uppercase;padding:14px 18px 4px;}';
    $out .= '.nav-btn{display:flex;align-items:center;gap:9px;padding:9px 18px;color:var(--text-2);font-size:13px;font-weight:500;cursor:pointer;border:none;border-left:2px solid transparent;background:none;width:100%;text-align:left;transition:all .13s;font-family:var(--font);}';
    $out .= '.nav-btn:hover{color:var(--accent);background:rgba(26,46,90,.06);border-left-color:rgba(26,46,90,.3);}';
    $out .= '.nav-btn.active{color:var(--accent);background:rgba(26,46,90,.1);border-left-color:var(--accent);}';
    $out .= '.sb-footer{padding:12px 14px;border-top:1px solid var(--border);}';
    $out .= '.main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;}';
    $out .= '.topbar{background:var(--n900);border-bottom:1px solid var(--border);padding:0 26px;height:54px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}';
    $out .= '.topbar-l{display:flex;align-items:center;gap:8px;}';
    $out .= '.topbar-title{font-size:15px;font-weight:600;color:var(--accent);}';
    $out .= '.topbar-r{display:flex;align-items:center;gap:14px;}';
    $out .= '.tb-meta{font-size:11.5px;color:var(--text-3);display:flex;align-items:center;gap:5px;}';
    $out .= '.dot-green{width:7px;height:7px;border-radius:50%;background:var(--green);box-shadow:0 0 5px var(--green);flex-shrink:0;}';
    $out .= '.content{flex:1;overflow-y:auto;padding:22px 26px;}';
    /* Stat cards */
    $out .= '.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:13px;margin-bottom:22px;}';
    $out .= '.sc{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:16px;position:relative;overflow:hidden;}';
    $out .= '.sc::before{content:"";position:absolute;top:0;left:0;right:0;height:2px;}';
    $out .= '.sc.b::before{background:var(--accent)}.sc.t::before{background:var(--teal)}.sc.g::before{background:var(--green)}.sc.r::before{background:var(--red)}.sc.a::before{background:var(--amber)}';
    $out .= '.sc-ico{width:30px;height:30px;border-radius:6px;display:flex;align-items:center;justify-content:center;margin-bottom:10px;}';
    $out .= '.sc.b .sc-ico{background:rgba(26,46,90,.12);color:var(--accent)}.sc.t .sc-ico{background:rgba(201,168,76,.14);color:var(--teal)}.sc.g .sc-ico{background:rgba(30,126,68,.12);color:var(--green)}.sc.r .sc-ico{background:rgba(192,57,43,.12);color:var(--red)}.sc.a .sc-ico{background:rgba(201,134,10,.12);color:var(--amber)}';
    $out .= '.sc-val{font-size:25px;font-weight:700;color:var(--accent);line-height:1;}';
    $out .= '.sc-lbl{font-size:11px;color:var(--text-3);margin-top:4px;text-transform:uppercase;letter-spacing:.5px;}';
    /* Panel */
    $out .= '.panel{background:var(--card);border:1px solid var(--border);border-radius:var(--r);margin-bottom:16px;overflow:hidden;}';
    $out .= '.ph{padding:11px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--card2);}';
    $out .= '.pt{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--accent);}';
    $out .= '.pt svg{color:var(--teal);}';
    $out .= '.pb{padding:16px;}';
    $out .= '.pb0{padding:0;}';
    $out .= '.tbl{width:100%;border-collapse:collapse;}';
    $out .= '.tbl th{text-align:left;font-size:10.5px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.6px;padding:9px 14px;border-bottom:1px solid var(--border);}';
    $out .= '.tbl td{padding:9px 14px;border-bottom:1px solid rgba(26,46,90,.06);font-size:13px;vertical-align:middle;}';
    $out .= '.tbl tbody tr:last-child td{border-bottom:none;}';
    $out .= '.tbl tbody tr:hover td{background:rgba(26,46,90,.04);}';
    $out .= '.badge{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;white-space:nowrap;}';
    $out .= '.bb{background:rgba(26,46,90,.12);color:var(--accent)}';
    $out .= '.bg{background:rgba(30,126,68,.12);color:#1a6e3a}';
    $out .= '.br{background:rgba(192,57,43,.12);color:#a83228}';
    $out .= '.ba{background:rgba(201,134,10,.12);color:#8a5c08}';
    $out .= '.bm{background:rgba(122,143,168,.12);color:var(--text-3)}';
    $out .= '.bt{background:rgba(201,168,76,.14);color:#8a6a10}';
    $out .= 'input[type=text],input[type=password],select,textarea{background:#fff;border:1px solid var(--border);border-radius:6px;padding:8px 11px;color:var(--text);font-size:13px;outline:none;width:100%;transition:border-color .14s,box-shadow .14s;font-family:var(--font);}';
    $out .= 'input[type=text]:focus,input[type=password]:focus,select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(26,46,90,.1);}';
    $out .= '.frow{display:flex;gap:9px;align-items:flex-end;flex-wrap:wrap;}';
    $out .= '.ff{flex:1;min-width:130px;}';
    $out .= '.fl{font-size:11px;color:var(--text-3);margin-bottom:5px;display:block;font-weight:600;text-transform:uppercase;letter-spacing:.5px;}';
    $out .= '.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:6px;border:1px solid transparent;font-size:12.5px;font-weight:500;cursor:pointer;transition:all .13s;white-space:nowrap;line-height:1;font-family:var(--font);}';
    $out .= '.bp{background:var(--accent);color:#fff;border-color:var(--accent)}.bp:hover{background:var(--accent-h);border-color:var(--accent-h)}';
    $out .= '.bd{background:rgba(192,57,43,.1);color:#a83228;border-color:rgba(192,57,43,.28)}.bd:hover{background:rgba(192,57,43,.2);}';
    $out .= '.bw{background:rgba(201,168,76,.12);color:#8a6a10;border-color:rgba(201,168,76,.32)}.bw:hover{background:rgba(201,168,76,.22);}';
    $out .= '.bg2{background:var(--n850);color:var(--text-2);border-color:var(--border)}.bg2:hover{border-color:var(--accent);color:var(--text);}';
    $out .= '.bs{padding:5px 9px;font-size:11.5px;}';
    $out .= '.bi{padding:5px;border-radius:5px;}';
    $out .= '.alert{display:flex;align-items:center;gap:9px;padding:10px 14px;border-radius:var(--r);margin-bottom:16px;font-size:13px;}';
    $out .= '.as{background:rgba(30,126,68,.09);border:1px solid rgba(30,126,68,.22);color:#1a6e3a;}';
    $out .= '.aw{background:rgba(201,134,10,.09);border:1px solid rgba(201,134,10,.22);color:#8a5c08;}';
    $out .= '.url-tok{font-family:"Cascadia Code","Fira Mono","Consolas",monospace;font-size:11.5px;background:#fff;border:1px solid var(--border);border-radius:5px;padding:4px 9px;color:var(--teal);display:inline-block;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle;}';
    $out .= '.logbox{background:#fff;border:1px solid var(--border);border-radius:6px;padding:12px 14px;font-family:"Cascadia Code","Fira Mono","Consolas",monospace;font-size:11.5px;color:#3a4f70;max-height:370px;overflow-y:auto;white-space:pre-wrap;line-height:1.8;}';
    $out .= '.cpsec{display:none;}.cpsec.on{display:block;}';
    $out .= '.cfgr{display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);}'.'.cfgr:last-child{border-bottom:none;}';
    $out .= '.cfgk{font-size:12px;color:var(--text-2);}';
    $out .= '.cfgv{font-size:12px;color:var(--accent);font-weight:600;}';
    $out .= '.lw{min-height:100vh;display:flex;align-items:center;justify-content:center;}';
    $out .= '.lc{background:var(--n900);border:1px solid var(--border);border-radius:11px;padding:38px 34px;width:370px;box-shadow:0 8px 32px rgba(26,46,90,.12);}';
    $out .= '.ll{display:flex;align-items:center;gap:11px;margin-bottom:26px;}';
    $out .= '.li{width:40px;height:40px;border-radius:9px;background:linear-gradient(135deg,var(--accent),var(--accent-h));display:flex;align-items:center;justify-content:center;color:#fff;}';
    $out .= '.lt{font-size:17px;font-weight:700;color:var(--accent);}';
    $out .= '.ls{font-size:12px;color:var(--text-3);margin-top:2px;}';
    $out .= '.lerr{color:#a83228;font-size:12.5px;margin-bottom:12px;display:flex;align-items:center;gap:5px;}';
    $out .= '::-webkit-scrollbar{width:5px;height:5px;}::-webkit-scrollbar-track{background:transparent;}::-webkit-scrollbar-thumb{background:var(--border-h);border-radius:99px;}';
    $out .= '@media(max-width:660px){.sidebar{display:none;}.stat-grid{grid-template-columns:1fr 1fr;}}';
    $out .= '</style>';
    return $out;
}

function cp_js() {
    $check = svg('check', 14);
    $out  = '<script>';
    $out .= 'function showSec(n){';
    $out .= 'document.querySelectorAll(".cpsec").forEach(function(e){e.classList.remove("on");});';
    $out .= 'document.querySelectorAll(".nav-btn").forEach(function(e){e.classList.remove("active");});';
    $out .= 'var s=document.getElementById("sec-"+n);if(s)s.classList.add("on");';
    $out .= 'var b=document.querySelector(".nav-btn[data-s="+n+"]");if(b)b.classList.add("active");';
    $out .= 'var t={overview:"Overview",routes:"Routes",security:"Security",logs:"Logs",tools:"Tools"};';
    $out .= 'document.getElementById("tbtitle").textContent=t[n]||n;';
    $out .= 'return false;}';
    $out .= 'function cpCopy(txt,btn){';
    $out .= 'if(!navigator.clipboard){return;}';
    $out .= 'navigator.clipboard.writeText(txt).then(function(){';
    $out .= 'var orig=btn.innerHTML;btn.innerHTML=\'' . addslashes($check) . '\';';
    $out .= 'setTimeout(function(){btn.innerHTML=orig;},1400);';
    $out .= '});}';
    $out .= 'document.addEventListener("DOMContentLoaded",function(){';
    $out .= 'document.querySelectorAll(".logbox").forEach(function(b){b.scrollTop=b.scrollHeight;});';
    $out .= '});';
    $out .= '</script>';
    return $out;
}

function cp_login($err = '') {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Router Panel</title>';
    echo cp_css();
    echo '</head><body>';
    echo '<div class="lw"><div class="lc">';
    echo '<div class="ll"><div class="li">' . svg('zap', 20) . '</div>';
    echo '<div><div class="lt">Router Panel</div><div class="ls">Enterprise Router Control</div></div></div>';
    if ($err !== '') {
        echo '<div class="lerr">' . svg('warning', 14) . htmlspecialchars($err) . '</div>';
    }
    echo '<form method="post">';
    echo '<label class="fl" for="cpw">Password</label>';
    echo '<input id="cpw" type="password" name="cp_pass" placeholder="Enter password" autofocus style="margin-bottom:14px">';
    echo '<button type="submit" class="btn bp" style="width:100%;justify-content:center">' . svg('key', 14) . ' &nbsp;Authenticate</button>';
    echo '</form>';
    echo '</div></div>';
    echo cp_js();
    echo '</body></html>';
    exit;
}

function run_cp($mapFile, $accessLog, $securityLog, $runtimeDir, $ipFailFile, $botFile, $stealthFile, $tokenFile) {

    /* Auth */
    if (CP_PASSWORD !== '') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cp_pass'])) {
            if ($_POST['cp_pass'] === CP_PASSWORD) {
                $_SESSION['CP_AUTH'] = true;
            } else {
                $_SESSION['CP_AUTH'] = false;
                cp_login('Incorrect password.');
            }
        }
        if (empty($_SESSION['CP_AUTH'])) {
            cp_login();
        }
    }

    $map      = load_map($mapFile);
    $msg      = '';
    $msg_type = 'as';

    /* Process actions */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = isset($_POST['action']) ? $_POST['action'] : '';

        if ($act === 'add_route') {
            $rid = trim(isset($_POST['route_id'])  ? $_POST['route_id']  : '');
            $url = trim(isset($_POST['route_url']) ? $_POST['route_url'] : '');
            if ($rid === '' || $url === '') {
                $msg = 'Route ID and file path are required.'; $msg_type = 'aw';
            } elseif (isset($map[$rid])) {
                $msg = 'Route ID <strong>' . htmlspecialchars($rid) . '</strong> already exists.'; $msg_type = 'aw';
            } else {
                $map[$rid] = array('url'=>$url,'count'=>0,'last_used'=>0,'daily'=>array(),'unique_ips'=>array(),'access_types'=>array(),'bot_hits'=>0);
                save_map($mapFile, $map);
                $msg = 'Route <strong>' . htmlspecialchars($rid) . '</strong> created successfully.';
            }
        }

        if ($act === 'edit_route') {
            $rid = isset($_POST['route_id'])  ? $_POST['route_id']  : '';
            $url = trim(isset($_POST['route_url']) ? $_POST['route_url'] : '');
            if (isset($map[$rid]) && $url !== '') {
                $map[$rid]['url'] = $url;
                save_map($mapFile, $map);
                $msg = 'Route <strong>' . htmlspecialchars($rid) . '</strong> updated.';
            }
        }

        if ($act === 'delete_route') {
            $rid = isset($_POST['route_id']) ? $_POST['route_id'] : '';
            if (in_array($rid, array('0','999'))) {
                $msg = 'Cannot delete reserved routes (0 and 999).'; $msg_type = 'aw';
            } elseif (isset($map[$rid])) {
                unset($map[$rid]);
                save_map($mapFile, $map);
                $msg = 'Route <strong>' . htmlspecialchars($rid) . '</strong> deleted.';
            }
        }

        if ($act === 'reset_stats') {
            $rid = isset($_POST['route_id']) ? $_POST['route_id'] : '';
            if (isset($map[$rid])) {
                $map[$rid]['count']        = 0;
                $map[$rid]['daily']        = array();
                $map[$rid]['bot_hits']     = 0;
                $map[$rid]['access_types'] = array();
                save_map($mapFile, $map);
                $msg = 'Statistics for route <strong>' . htmlspecialchars($rid) . '</strong> reset.';
            }
        }

        if ($act === 'clear_access_log')   { file_put_contents($accessLog,   '', LOCK_EX); $msg = 'Access log cleared.'; }
        if ($act === 'clear_security_log') { file_put_contents($securityLog, '', LOCK_EX); $msg = 'Security log cleared.'; }

        if ($act === 'ban_ip') {
            $bip = trim(isset($_POST['ban_ip']) ? $_POST['ban_ip'] : '');
            if ($bip !== '') {
                $d       = json_clean_load($stealthFile);
                $d[$bip] = time() + 86400 * 30;
                file_put_contents($stealthFile, json_encode($d), LOCK_EX);
                $msg = 'IP <strong>' . htmlspecialchars($bip) . '</strong> banned for 30 days.';
            }
        }

        if ($act === 'unban_ip') {
            $bip = trim(isset($_POST['ban_ip']) ? $_POST['ban_ip'] : '');
            if ($bip !== '') {
                $d = json_clean_load($stealthFile); unset($d[$bip]);
                file_put_contents($stealthFile, json_encode($d), LOCK_EX);
                $bd = json_clean_load($botFile); unset($bd[$bip]);
                file_put_contents($botFile, json_encode($bd), LOCK_EX);
                $msg = 'IP <strong>' . htmlspecialchars($bip) . '</strong> unbanned.';
            }
        }

        if ($act === 'cp_logout') {
            unset($_SESSION['CP_AUTH']);
            header('Location: ?' . CP_PATH);
            exit;
        }
    }

    /* Compute stats */
    $today       = date('Y-m-d');
    $totalRoutes = count($map);
    $totalHits   = 0; foreach ($map as $r) $totalHits += $r['count'];
    $todayHits   = 0; foreach ($map as $r) $todayHits += (isset($r['daily'][$today]) ? $r['daily'][$today] : 0);
    $bannedIPs   = json_clean_load($stealthFile);
    $botData     = json_clean_load($botFile);
    $topBots     = $botData; arsort($topBots);
    $topBots     = array_slice($topBots, 0, 15, true);

    $recentAccess = '';
    if (file_exists($accessLog)) {
        $lines = file($accessLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $recentAccess = implode("\n", array_slice($lines, -150));
    }
    $recentSecurity = '';
    if (file_exists($securityLog)) {
        $lines = file($securityLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $recentSecurity = implode("\n", array_slice($lines, -150));
    }

    /* ============================================================
       HTML RENDER — pure echo, zero heredocs
       ============================================================ */
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Router Control Panel</title>';
    echo cp_css();
    echo '</head><body><div class="shell">';

    
    echo '<div class="sidebar">';
    echo '<div class="sb-brand"><div class="sb-brand-row">';
    echo '<div class="sb-icon">' . svg('zap', 17) . '</div>';
    echo '<div><div class="sb-name">v.php Router</div><div class="sb-sub">Control Panel</div></div>';
    echo '</div></div>';
    echo '<nav class="sb-nav">';
    echo '<div class="nav-lbl">Navigation</div>';
    echo '<button class="nav-btn active" data-s="overview" onclick="return showSec(\'overview\')">' . svg('dashboard', 14) . ' &nbsp;Overview</button>';
    echo '<button class="nav-btn" data-s="routes"   onclick="return showSec(\'routes\')">'   . svg('routemap', 14) . ' &nbsp;Routes</button>';
    echo '<button class="nav-btn" data-s="security" onclick="return showSec(\'security\')">' . svg('shield', 14)   . ' &nbsp;Security</button>';
    echo '<button class="nav-btn" data-s="logs"     onclick="return showSec(\'logs\')">'     . svg('log', 14)      . ' &nbsp;Logs</button>';
    echo '<button class="nav-btn" data-s="tools"    onclick="return showSec(\'tools\')">'    . svg('tools', 14)    . ' &nbsp;Tools</button>';
    echo '</nav>';
    echo '<div class="sb-footer"><form method="post">';
    echo '<button name="action" value="cp_logout" class="btn bg2" style="width:100%;justify-content:center">';
    echo svg('logout', 13) . ' &nbsp;Sign Out</button></form></div>';
    echo '</div>'; 

    
    echo '<div class="main">';
    echo '<div class="topbar"><div class="topbar-l">';
    echo svg('zap', 14) . '&nbsp;<span class="topbar-title" id="tbtitle">Overview</span>';
    echo '</div><div class="topbar-r">';
    echo '<div class="tb-meta"><div class="dot-green"></div>&nbsp;Router Online</div>';
    echo '<div class="tb-meta">' . svg('clock', 12) . '&nbsp;' . date('d M Y, H:i') . ' IST</div>';
    echo '<div class="tb-meta">' . svg('globe', 12) . '&nbsp;' . htmlspecialchars(client_ip()) . '</div>';
    echo '</div></div>'; 

    echo '<div class="content">';

    if ($msg !== '') {
        echo '<div class="alert ' . $msg_type . '">' . svg($msg_type === 'aw' ? 'warning' : 'check', 14) . '&nbsp;<span>' . $msg . '</span></div>';
    }

    
    echo '<div id="sec-overview" class="cpsec on">';
    echo '<div class="stat-grid">';
    echo '<div class="sc b"><div class="sc-ico">' . svg('routemap', 15) . '</div><div class="sc-val">' . $totalRoutes . '</div><div class="sc-lbl">Total Routes</div></div>';
    echo '<div class="sc g"><div class="sc-ico">' . svg('activity', 15) . '</div><div class="sc-val">' . $totalHits   . '</div><div class="sc-lbl">All-Time Hits</div></div>';
    echo '<div class="sc t"><div class="sc-ico">' . svg('zap', 15)      . '</div><div class="sc-val">' . $todayHits   . '</div><div class="sc-lbl">Today</div></div>';
    echo '<div class="sc r"><div class="sc-ico">' . svg('ban', 15)      . '</div><div class="sc-val">' . count($bannedIPs) . '</div><div class="sc-lbl">Banned IPs</div></div>';
    echo '<div class="sc a"><div class="sc-ico">' . svg('warning', 15)  . '</div><div class="sc-val">' . count($botData)   . '</div><div class="sc-lbl">Bot Entries</div></div>';
    echo '</div>'; 

    echo '<div class="panel"><div class="ph"><div class="pt">' . svg('activity', 14) . '&nbsp;Route Traffic Summary</div></div>';
    echo '<div class="pb0"><table class="tbl"><thead><tr>';
    echo '<th>Route ID</th><th>File Path</th><th>Hits</th><th>Today</th><th>Uniq IPs</th><th>Bots</th><th>Last Access</th><th>Short URL</th>';
    echo '</tr></thead><tbody>';
    foreach ($map as $rid => $r) {
        $lu  = $r['last_used'] ? date('d M, H:i', $r['last_used']) : '&mdash;';
        $td  = isset($r['daily'][$today]) ? $r['daily'][$today] : 0;
        $uip = count(isset($r['unique_ips']) ? $r['unique_ips'] : array());
        $bh  = isset($r['bot_hits']) ? $r['bot_hits'] : 0;
        $su  = sign_url($rid);
        $bCl = $bh > 0 ? 'br' : 'bm';
        $esc = htmlspecialchars($su, ENT_QUOTES);
        $esf = htmlspecialchars($r['url']);
        echo '<tr>';
        echo '<td><span class="badge bb">' . htmlspecialchars($rid) . '</span></td>';
        echo '<td><code>' . $esf . '</code></td>';
        echo '<td><strong>' . $r['count'] . '</strong></td>';
        echo '<td>' . $td . '</td>';
        echo '<td>' . $uip . '</td>';
        echo '<td><span class="badge ' . $bCl . '">' . $bh . '</span></td>';
        echo '<td style="color:var(--text-3);font-size:12px">' . $lu . '</td>';
        echo '<td style="white-space:nowrap"><span class="url-tok">' . $esc . '</span>';
        echo '<button class="btn bg2 bs bi" style="margin-left:5px" title="Copy" onclick="cpCopy(\'' . addslashes($su) . '\',this)">' . svg('copy', 12) . '</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></div>'; 

    if (!empty($topBots)) {
        echo '<div class="panel"><div class="ph"><div class="pt">' . svg('shield', 14) . '&nbsp;Top Bot Scores</div></div>';
        echo '<div class="pb0"><table class="tbl"><thead><tr>';
        echo '<th>IP Address</th><th>Score</th><th>Last Seen</th><th>Status</th><th>Action</th>';
        echo '</tr></thead><tbody>';
        foreach ($topBots as $bip => $bd) {
            $sc  = is_array($bd) ? (isset($bd['score']) ? $bd['score'] : 0) : (int)$bd;
            $ts  = (is_array($bd) && isset($bd['ts'])) ? date('d M, H:i', $bd['ts']) : '&mdash;';
            $cl  = $sc > 100 ? 'br' : ($sc > 60 ? 'ba' : 'bm');
            $ban = isset($bannedIPs[$bip]) ? '<span class="badge br">Banned</span>' : '<span class="badge bm">Active</span>';
            echo '<tr>';
            echo '<td><code>' . htmlspecialchars($bip) . '</code></td>';
            echo '<td><span class="badge ' . $cl . '">' . $sc . '</span></td>';
            echo '<td style="color:var(--text-3);font-size:12px">' . $ts . '</td>';
            echo '<td>' . $ban . '</td>';
            echo '<td><form method="post" style="display:flex;gap:5px">';
            echo '<input type="hidden" name="ban_ip" value="' . htmlspecialchars($bip, ENT_QUOTES) . '">';
            echo '<button name="action" value="ban_ip"   class="btn bs bd">' . svg('ban',   11) . '&nbsp;Ban</button>';
            echo '<button name="action" value="unban_ip" class="btn bs bg2">' . svg('check', 11) . '&nbsp;Unban</button>';
            echo '</form></td></tr>';
        }
        echo '</tbody></table></div></div>';
    }
    echo '</div>'; 

    
    echo '<div id="sec-routes" class="cpsec">';
    echo '<div class="panel"><div class="ph"><div class="pt">' . svg('plus', 14) . '&nbsp;Add New Route</div></div>';
    echo '<div class="pb"><form method="post"><div class="frow">';
    echo '<div class="ff"><label class="fl">Route ID</label><input type="text" name="route_id" placeholder="e.g. about1"></div>';
    echo '<div class="ff"><label class="fl">File Path</label><input type="text" name="route_url" placeholder="e.g. pages/about.php"></div>';
    echo '<button type="submit" name="action" value="add_route" class="btn bp">' . svg('plus', 13) . '&nbsp;Add Route</button>';
    echo '</div></form></div></div>';

    echo '<div class="panel"><div class="ph"><div class="pt">' . svg('routemap', 14) . '&nbsp;Manage Routes</div></div>';
    echo '<div class="pb0"><table class="tbl"><thead><tr>';
    echo '<th>ID</th><th>File Path</th><th>Hits</th><th>Edit</th><th>Reset</th><th>Delete</th>';
    echo '</tr></thead><tbody>';
    foreach ($map as $rid => $r) {
        $res = in_array($rid, array('0','999'));
        echo '<tr>';
        echo '<td><span class="badge bb">' . htmlspecialchars($rid) . '</span>';
        if ($res) echo '&nbsp;<span class="badge bm">reserved</span>';
        echo '</td>';
        echo '<td><form method="post" style="display:flex;gap:7px;align-items:center">';
        echo '<input type="hidden" name="route_id" value="' . htmlspecialchars($rid, ENT_QUOTES) . '">';
        echo '<input type="text" name="route_url" value="' . htmlspecialchars($r['url'], ENT_QUOTES) . '" style="min-width:200px">';
        echo '<button name="action" value="edit_route" class="btn bs bg2">' . svg('save', 12) . '&nbsp;Save</button>';
        echo '</form></td>';
        echo '<td>' . $r['count'] . '</td>';
        echo '<td><form method="post"><input type="hidden" name="route_id" value="' . htmlspecialchars($rid, ENT_QUOTES) . '">';
        echo '<button name="action" value="reset_stats" class="btn bs bw">' . svg('refresh', 12) . '&nbsp;Reset</button></form></td>';
        echo '<td><form method="post"><input type="hidden" name="route_id" value="' . htmlspecialchars($rid, ENT_QUOTES) . '">';
        echo '<button name="action" value="delete_route" class="btn bs bd"' . ($res ? ' disabled title="Reserved"' : '') . '>' . svg('trash', 12) . '&nbsp;Delete</button></form></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></div>';
    echo '</div>'; 

    
    echo '<div id="sec-security" class="cpsec">';
    echo '<div class="panel"><div class="ph"><div class="pt">' . svg('ban', 14) . '&nbsp;IP Ban Control</div></div>';
    echo '<div class="pb"><form method="post"><div class="frow">';
    echo '<div class="ff"><label class="fl">IP Address</label><input type="text" name="ban_ip" placeholder="1.2.3.4"></div>';
    echo '<button name="action" value="ban_ip"   class="btn bd">' . svg('ban',   13) . '&nbsp;Ban 30 Days</button>';
    echo '<button name="action" value="unban_ip" class="btn bg2">' . svg('check', 13) . '&nbsp;Unban</button>';
    echo '</div></form></div></div>';

    echo '<div class="panel"><div class="ph"><div class="pt">' . svg('shield', 14) . '&nbsp;Currently Banned IPs</div></div>';
    echo '<div class="pb0"><table class="tbl"><thead><tr><th>IP Address</th><th>Banned Until</th><th>Action</th></tr></thead><tbody>';
    if (empty($bannedIPs)) {
        echo '<tr><td colspan="3" style="text-align:center;color:var(--text-3);padding:22px">No banned IPs currently</td></tr>';
    }
    foreach ($bannedIPs as $bip => $exp) {
        echo '<tr><td><code>' . htmlspecialchars($bip) . '</code></td>';
        echo '<td style="font-size:12px;color:var(--text-2)">' . date('d M Y, H:i', $exp) . '</td>';
        echo '<td><form method="post" style="display:inline"><input type="hidden" name="ban_ip" value="' . htmlspecialchars($bip, ENT_QUOTES) . '">';
        echo '<button name="action" value="unban_ip" class="btn bs bg2">' . svg('check', 11) . '&nbsp;Unban</button></form></td></tr>';
    }
    echo '</tbody></table></div></div>';

    echo '<div class="panel"><div class="ph"><div class="pt">' . svg('activity', 14) . '&nbsp;Bot Score Table</div></div>';
    echo '<div class="pb0"><table class="tbl"><thead><tr><th>IP Address</th><th>Score</th><th>Last Seen</th></tr></thead><tbody>';
    if (empty($botData)) {
        echo '<tr><td colspan="3" style="text-align:center;color:var(--text-3);padding:22px">No bot data recorded</td></tr>';
    }
    foreach ($botData as $bip => $bd) {
        $sc = is_array($bd) ? (isset($bd['score']) ? $bd['score'] : 0) : (int)$bd;
        $ts = (is_array($bd) && isset($bd['ts'])) ? date('d M, H:i', $bd['ts']) : '&mdash;';
        $cl = $sc > 100 ? 'br' : ($sc > 60 ? 'ba' : 'bm');
        echo '<tr><td><code>' . htmlspecialchars($bip) . '</code></td>';
        echo '<td><span class="badge ' . $cl . '">' . $sc . '</span></td>';
        echo '<td style="color:var(--text-3);font-size:12px">' . $ts . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
    echo '</div>'; 

    
    echo '<div id="sec-logs" class="cpsec">';
    echo '<div class="panel"><div class="ph">';
    echo '<div class="pt">' . svg('log', 14) . '&nbsp;Access Log &nbsp;<span style="color:var(--text-3);font-size:11px;font-weight:400">last 150 lines</span></div>';
    echo '<form method="post"><button name="action" value="clear_access_log" class="btn bs bd">' . svg('trash', 12) . '&nbsp;Clear</button></form>';
    echo '</div><div class="pb"><div class="logbox">' . htmlspecialchars($recentAccess !== '' ? $recentAccess : '— log is empty —') . '</div></div></div>';

    echo '<div class="panel"><div class="ph">';
    echo '<div class="pt">' . svg('shield', 14) . '&nbsp;Security Log &nbsp;<span style="color:var(--text-3);font-size:11px;font-weight:400">last 150 lines</span></div>';
    echo '<form method="post"><button name="action" value="clear_security_log" class="btn bs bd">' . svg('trash', 12) . '&nbsp;Clear</button></form>';
    echo '</div><div class="pb"><div class="logbox">' . htmlspecialchars($recentSecurity !== '' ? $recentSecurity : '— log is empty —') . '</div></div></div>';
    echo '</div>'; 

    
    echo '<div id="sec-tools" class="cpsec">';

    /* URL generator */
    echo '<div class="panel"><div class="ph"><div class="pt">' . svg('link', 14) . '&nbsp;Generate Signed Short URL</div></div>';
    echo '<div class="pb">';
    echo '<div class="frow" style="margin-bottom:12px">';
    echo '<div class="ff"><label class="fl">Select Route</label><select id="gen_rid">';
    foreach ($map as $rid => $r) {
        echo '<option value="' . htmlspecialchars($rid, ENT_QUOTES) . '">' . htmlspecialchars($rid) . ' &rarr; ' . htmlspecialchars($r['url']) . '</option>';
    }
    echo '</select></div>';
    echo '<button class="btn bp" onclick="doGen();return false">' . svg('link', 13) . '&nbsp;Generate</button>';
    echo '</div>';
    echo '<div id="gen-out" style="display:none">';
    echo '<label class="fl">URL (valid ' . (SIGNED_TTL/3600) . 'h)</label>';
    echo '<div style="display:flex;align-items:center;gap:8px;margin-top:6px">';
    echo '<span class="url-tok" id="gen-url" style="max-width:480px;flex:1"></span>';
    echo '<button class="btn bg2 bs" id="gen-cp-btn" onclick="cpCopy(document.getElementById(\'gen-url\').textContent,this)">' . svg('copy', 12) . '&nbsp;Copy</button>';
    echo '</div></div>';

    $urlMap = array();
    foreach ($map as $rid => $r) $urlMap[$rid] = sign_url($rid);
    echo '<script>var cpUrlMap=' . json_encode($urlMap) . ';';
    echo 'function doGen(){var r=document.getElementById("gen_rid").value;';
    echo 'var u=(cpUrlMap[r]||"");';
    echo 'document.getElementById("gen-url").textContent=window.location.origin+u;';
    echo 'document.getElementById("gen-out").style.display="";}';
    echo '</script>';
    echo '</div></div>'; 

    /* Runtime files */
    echo '<div class="panel"><div class="ph"><div class="pt">' . svg('server', 14) . '&nbsp;Runtime Files</div></div>';
    echo '<div class="pb0"><table class="tbl"><thead><tr><th>File</th><th>Purpose</th><th>Size</th><th>Modified</th></tr></thead><tbody>';
    $rfiles = array('m.json'=>'Route Map','a.log'=>'Access Log','s.log'=>'Security Log',
                    'u.json'=>'Token Store','b.json'=>'Bot Scores','x.json'=>'Stealth Bans',
                    'r.json'=>'Rate Limits','k.json'=>'Token Leaks');
    foreach ($rfiles as $fn => $label) {
        $fp = $runtimeDir . '/' . $fn;
        $sz = file_exists($fp) ? number_format(filesize($fp) / 1024, 1) . ' KB' : '<span style="color:var(--text-3)">Not created</span>';
        $mt = file_exists($fp) ? date('d M Y, H:i', filemtime($fp)) : '&mdash;';
        echo '<tr><td><code>' . $fn . '</code></td><td style="color:var(--text-2)">' . $label . '</td><td>' . $sz . '</td><td style="color:var(--text-3);font-size:12px">' . $mt . '</td></tr>';
    }
    echo '</tbody></table></div></div>';

    /* Config overview */
    echo '<div class="panel"><div class="ph"><div class="pt">' . svg('cpu', 14) . '&nbsp;Active Configuration</div></div>';
    echo '<div class="pb">';
    $cfgs = array(
        'URL Mode'         => URL_MODE === 'short' ? '<span class="badge bt">Short &mdash; /?r=token</span>' : '<span class="badge bm">Classic</span>',
        'Token TTL'        => (SIGNED_TTL / 3600) . ' hours',
        'Rate Limit'       => RATE_LIMIT_MAX . ' req / ' . RATE_LIMIT_WINDOW . ' sec',
        'Stealth Ban'      => (STEALTH_BAN_TIME / 60) . ' minutes',
        'Bot Score Base'   => (string)BOT_SCORE_BASE,
        'Route Creation'   => ROUTE_CREATION_ENABLED ? '<span class="badge bg">Enabled</span>'  : '<span class="badge br">Disabled</span>',
        'Token Security'   => TOKEN_SECURITY_ENABLED  ? '<span class="badge bg">On</span>'       : '<span class="badge ba">Off</span>',
        'Bot Protection'   => BOT_PROTECTION_ENABLED  ? '<span class="badge bg">On</span>'       : '<span class="badge ba">Off</span>',
        'Access Logging'   => ACCESS_LOG_ENABLED       ? '<span class="badge bg">On</span>'       : '<span class="badge bm">Off</span>',
        'Security Logging' => SECURITY_LOG_ENABLED     ? '<span class="badge bg">On</span>'       : '<span class="badge bm">Off</span>',
    );
    foreach ($cfgs as $k => $v) {
        echo '<div class="cfgr"><span class="cfgk">' . $k . '</span><span class="cfgv">' . $v . '</span></div>';
    }
    echo '</div></div>';
    echo '</div>'; 

    echo '</div>'; 
    echo '</div>'; 
    echo '</div>'; 
    echo cp_js();
    echo '</body></html>';
    exit;
}

/* =============================================================
   CONTROL PANEL ENTRY POINT
   ============================================================= */
if (array_key_exists(CP_PATH, $_GET)) {
    run_cp($mapFile, $accessLog, $securityLog, $runtimeDir, $ipFailFile, $botFile, $stealthFile, $tokenFile);
}

/* =============================================================
   REQUEST BOOTSTRAP
   ============================================================= */
$IP       = client_ip();
$UA_FULL  = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
$UA_SHORT = ua_short();
$NET      = get_net_info($IP);
$ORIG_URI = $_SERVER['REQUEST_URI'];
$PATH     = ltrim(parse_url($ORIG_URI, PHP_URL_PATH), '/');

/* Init map */
$map = load_map($mapFile);
foreach (array('0' => 'index.html', '999' => 'error.html') as $rid => $url) {
    if (!isset($map[$rid])) {
        $map[$rid] = array('url'=>$url,'count'=>0,'last_used'=>0,'daily'=>array(),'unique_ips'=>array(),'access_types'=>array(),'bot_hits'=>0);
    }
}
save_map($mapFile, $map);

/* Forbidden path guard */
$forbidden_entries = array('.env', '.git', '.htaccess', 'config.php', 'database.php', '/.set/', '/.private');
foreach ($forbidden_entries as $f) {
    if (stripos($ORIG_URI, $f) !== false) {
        if (SECURITY_LOG_ENABLED) log_once($securityLog, array(
            'TIME'     => date('Y-m-d H:i:s'), 'SESSION'  => $VISITOR_ID,
            'IP'       => $IP, 'DEVICE'   => $UA_FULL,
            'LOCATION' => ($NET['country'] ?? 'Unknown') . ', ' . ($NET['city'] ?? 'Unknown'),
            'ISP'      => $NET['isp'] ?? 'Unknown ISP', 'BROWSER' => $UA_SHORT,
            'NETWORK'  => ($NET['proxy'] ? 'VPN/Proxy' : ($NET['hosting'] ? 'Hosting' : ($NET['mobile'] ? 'Mobile' : 'Normal'))),
            'EVENT'    => 'FORBIDDEN_ATTEMPT', 'TARGET' => $ORIG_URI,
        ));
        http_response_code(404);
        if (file_exists($errorPage)) readfile($errorPage); else echo 'Not Found';
        exit;
    }
}

/* Security pre-checks */
if (BOT_PROTECTION_ENABLED) stealth_check_ip($IP);
if (!ip_rate_ok($IP)) {
    bot_score_add($IP, 5);
    stealth_add_ip($IP);
    respond_error_and_log('RATE_LIMIT_EXCEEDED', array('URL' => $ORIG_URI));
}

if (URL_MODE === 'short' && isset($_GET['r'])) {
    $decoded = decode_short_token($_GET['r']);
    if ($decoded === null) {
        ip_rate_add($IP);
        bot_score_add($IP, 10);
        respond_error_and_log('INVALID_SHORT_TOKEN', array('URL' => $ORIG_URI));
    }
    $_GET = array_merge($_GET, $decoded);
}

if (isset($_GET['id']) && isset($_GET['sig'])) {

    $verify = verify_signature_details();
    if (!$verify['ok']) {
        ip_rate_add($IP);
        bot_score_add($IP, 10);
        respond_error_and_log('TAMPER_OR_INVALID_SIGNATURE', array(
            'URL'     => $ORIG_URI,
            'DETAILS' => implode('; ', isset($verify['reasons']) ? $verify['reasons'] : array()),
        ));
    }

    if (TOKEN_SECURITY_ENABLED && token_used_check($_GET['sig'])) {
        bot_score_add($IP, 15);
        respond_error_and_log('TOKEN_REPLAY', array('URL' => $ORIG_URI));
    }
    if (TOKEN_SECURITY_ENABLED) token_used_add($_GET['sig']);

    $leakCount = token_leak_track($_GET['sig'], $IP);
    if ($leakCount > 5) bot_score_add($IP, 20);

    $id = $_GET['id'];
    if (!isset($map[$id])) {
        respond_error_and_log('UNKNOWN_ROUTE_ID', array('URL' => $ORIG_URI, 'ROUTE' => $id));
    }

    $original_logged = isset($_SESSION['ORIGINAL_REQUEST_URI']) ? $_SESSION['ORIGINAL_REQUEST_URI'] : $ORIG_URI;
    unset($_SESSION['ORIGINAL_REQUEST_URI']);

    $signedData = $_GET;
    unset($signedData['sig']);
    $_GET = $signedData;
    $_SERVER['QUERY_STRING'] = http_build_query($signedData);

    $routeData = $map[$id];
    $mappedRel = $routeData['url'];
    $mappedAbs = safe_realpath_within(__DIR__, $mappedRel);
    if ($mappedAbs !== false) $mappedAbs = resolve_directory_index($mappedAbs);
    if ($mappedAbs === false || !file_exists($mappedAbs)) {
        respond_error_and_log('MAPPED_FILE_MISSING', array('ROUTE' => $id, 'FILE' => $mappedRel));
    }

    $pending       = get_pending_log();
    $eventCombined = ($pending && isset($pending['ROUTE ID']) && $pending['ROUTE ID'] === $id)
                   ? $pending['EVENT'] . ',SIGNED_ACCESS_ALLOWED'
                   : 'SIGNED_ACCESS_ALLOWED';
    clear_pending_log();

    $accessType = classify_access_type($eventCombined, $id);
    update_route_metrics($map, $mapFile, $id, $IP, $accessType);

    $logEntry = array(
        'TIME'         => date('Y-m-d H:i:s'),
        'SESSION'      => $VISITOR_ID,
        'IP'           => $IP,
        'DEVICE'       => $UA_FULL,
        'LOCATION'     => ($NET['country'] ?? 'Unknown') . ', ' . ($NET['city'] ?? 'Unknown'),
        'ISP'          => $NET['isp'] ?? 'Unknown ISP',
        'NETWORK'      => ($NET['proxy'] ? 'VPN/Proxy' : ($NET['hosting'] ? 'Hosting' : ($NET['mobile'] ? 'Mobile' : 'Normal'))),
        'EVENT'        => $eventCombined,
        'ACCESS_TYPE'  => $accessType,
        'ROUTE ID'     => $id,
        'FILE'         => $mappedRel,
        'ORIGINAL URL' => $original_logged,
        'FINAL URL'    => $ORIG_URI,
    );

    $dedupeKey = $VISITOR_ID . '|' . $id;
    $now       = time();
    $last      = isset($_SESSION['LAST_ROUTER_ACCESS']) ? $_SESSION['LAST_ROUTER_ACCESS'] : null;
    $shouldLog = !($last && isset($last['key'], $last['time']) && $last['key'] === $dedupeKey && ($now - $last['time']) <= 2);

    if ($shouldLog && ACCESS_LOG_ENABLED) {
        log_once($accessLog, $logEntry);
        $_SESSION['LAST_ROUTER_ACCESS'] = array('key' => $dedupeKey, 'time' => $now);
    }

    if (strtolower(pathinfo($mappedAbs, PATHINFO_EXTENSION)) === 'php') {
        include $mappedAbs;
    } else {
        $mime = @mime_content_type($mappedAbs) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        readfile($mappedAbs);
    }
    exit;
}

if (isset($_GET['id']) && !isset($_GET['sig'])) {
    $id = $_GET['id'];
    if (!isset($map[$id])) {
        respond_error_and_log('UNKNOWN_ROUTE_ID', array('URL' => $ORIG_URI, 'ROUTE' => $id));
    }
    $extra = $_GET;
    unset($extra['id'], $extra['sig'], $extra['r']);
    $_SESSION['ORIGINAL_REQUEST_URI'] = $ORIG_URI;
    set_pending_log(array('EVENT' => 'SIMPLE_ID_REDIRECT', 'ROUTE ID' => $id));
    header('Location: ' . sign_url($id, $extra, SIGNED_TTL));
    exit;
}

if ($PATH === '' || $PATH === 'v.php') {
    $_SESSION['ORIGINAL_REQUEST_URI'] = $ORIG_URI;
    set_pending_log(array('EVENT' => 'ROOT_REDIRECT', 'ROUTE ID' => '0'));
    header('Location: ' . sign_url('0', array(), SIGNED_TTL));
    exit;
}

$realCandidate = safe_realpath_within(__DIR__, $PATH);
if ($realCandidate !== false) $realCandidate = resolve_directory_index($realCandidate);

$canonicalRelPath = null;
if ($realCandidate !== false) {
    $baseReal = realpath(__DIR__);
    if ($baseReal !== false) {
        $canonicalRelPath = ltrim(str_replace('\\', '/', substr($realCandidate, strlen($baseReal))), '/');
    }
}

if ($realCandidate !== false && file_exists($realCandidate)) {
    $lookupKey = normalize_rel_path($canonicalRelPath ? $canonicalRelPath : $PATH);
    $found     = false;
    foreach ($map as $existingId => $routeData) {
        if (isset($routeData['url']) && normalize_rel_path($routeData['url']) === $lookupKey) {
            $found = $existingId;
            break;
        }
    }
    $created_new = false;
    if ($found === false) {
        if (!ROUTE_CREATION_ENABLED) {
            respond_error_and_log('ROUTE_CREATION_DISABLED', array('PATH' => $lookupKey));
        }
        $newId = substr(md5($PATH . microtime(true)), 0, 6);
        while (isset($map[$newId])) $newId = substr(md5($PATH . rand()), 0, 6);
        $map[$newId] = array('url'=>normalize_rel_path($lookupKey),'count'=>0,'last_used'=>0,
                             'daily'=>array(),'unique_ips'=>array(),'access_types'=>array(),'bot_hits'=>0);
        save_map($mapFile, $map);
        $found       = $newId;
        $created_new = true;
    }
    parse_str(isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '', $qs);
    unset($qs['sig'], $qs['exp'], $qs['r']);
    $_SESSION['ORIGINAL_REQUEST_URI'] = $ORIG_URI;

    if (AUTO_SIGN_REDIRECT) {
        set_pending_log(array('EVENT' => ($created_new ? 'MAPPING_CREATED_AND_REDIRECT' : 'REALPATH_REDIRECT'), 'ROUTE ID' => $found));
        header('Location: ' . sign_url($found, $qs, SIGNED_TTL));
        exit;
    } else {
        if (strtolower(pathinfo($realCandidate, PATHINFO_EXTENSION)) === 'php') {
            include $realCandidate;
        } else {
            $mime = @mime_content_type($realCandidate) ?: 'application/octet-stream';
            header('Content-Type: ' . $mime);
            readfile($realCandidate);
        }
        exit;
    }
}

respond_error_and_log('NOT_FOUND', array('URL' => $ORIG_URI));
