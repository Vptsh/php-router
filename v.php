<?php
session_start();

date_default_timezone_set("Asia/Kolkata");

$config = __DIR__ . '/.set/router_config.php';
$errorPage = __DIR__ . '/error.html';

if (!file_exists($config)) {
    http_response_code(500);
    exit("Config missing");
}
require_once $config;

/* ================= MASTER CONTROL PANEL ================= */

define('ROUTER_ENABLED', true);
define('ROUTE_CREATION_ENABLED', false);
define('ACCESS_LOG_ENABLED', true);
define('SECURITY_LOG_ENABLED', true);
define('TOKEN_SECURITY_ENABLED', true);
define('BOT_PROTECTION_ENABLED', true);
define('AUTO_SIGN_REDIRECT', true);

if (!ROUTER_ENABLED) {
    http_response_code(503);
    exit("Router disabled");
}

/* ================= SECURITY CONFIG ================= */
define('RATE_LIMIT_MAX', 40);
define('RATE_LIMIT_WINDOW', 60);
define('TOKEN_REPLAY_WINDOW', 7200);
define('MAX_UNIQUE_IP_TRACK', 100);
define('STEALTH_BAN_TIME', 900);
define('BOT_SCORE_BASE', 60);
define('JSON_MAX_AGE', 604800);

/* ================= SIGNED EXTRA PARAM WHITELIST ================= */

define('SIGNED_EXTRA_PARAMS', [
    'file',
    'page',
    'lang',
    'mode'
]);


/* ================= JSON CLEAN TIMES ================= */

define('CLEAN_U', 86400);        // u.json → 24h
define('CLEAN_K', 604800);       // k.json → 7 days
define('CLEAN_B', 1209600);      // b.json → 14 days
define('CLEAN_R', 3600);         // r.json → 1 hour


/* ================= PATHS ================= */

$runtimeDir = __DIR__ . '/.runtime';
/* ================= AUTO JSON CLEAN ================= */

json_clean_per_file($runtimeDir.'/u.json', CLEAN_U);
json_clean_per_file($runtimeDir.'/k.json', CLEAN_K);
json_clean_per_file($runtimeDir.'/b.json', CLEAN_B);
json_clean_per_file($runtimeDir.'/r.json', CLEAN_R);

if (!is_dir($runtimeDir)) mkdir($runtimeDir, 0777, true);

$mapFile     = $runtimeDir . '/m.json';
$accessLog   = $runtimeDir . '/a.log';
$securityLog = $runtimeDir . '/s.log';

$ipFailFile  = $runtimeDir . '/r.json';
$tokenFile   = $runtimeDir . '/u.json';
$botFile     = $runtimeDir . '/b.json';
$stealthFile = $runtimeDir . '/x.json';
$leakFile    = $runtimeDir . '/k.json';

/* ================= VISITOR ID ================= */
if (!isset($_SESSION['VISITOR_ID'])) {
    $_SESSION['VISITOR_ID'] = 'VIS-' . substr(md5(uniqid('', true)), 0, 6);
}
$VISITOR_ID = $_SESSION['VISITOR_ID'];

/* ================= JSON CLEAN ================= */
function json_clean_load($file){
 if(!file_exists($file)) return [];
 $d=json_decode(file_get_contents($file),true)?:[];
 $now=time();
 foreach($d as $k=>$v){
  if(is_array($v)&&isset($v['ts'])&&$now-$v['ts']>JSON_MAX_AGE) unset($d[$k]);
  if(is_numeric($v)&&$now-$v>JSON_MAX_AGE) unset($d[$k]);
 }
 file_put_contents($file,json_encode($d),LOCK_EX);
 return $d;
}

/* ================= ORIGINAL HELPERS ================= */
function client_ip(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
}

function ua_short(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    if (stripos($ua, 'Chrome') !== false) return 'Chrome';
    if (stripos($ua, 'Firefox') !== false) return 'Firefox';
    if (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false) return 'Safari';
    if (stripos($ua, 'Edg') !== false) return 'Edge';
    return 'Other';
}
function json_clean_per_file($file, $maxAge){

 if(!file_exists($file)) return [];

 $data = json_decode(file_get_contents($file), true) ?: [];
 $now = time();
 $changed = false;

 foreach($data as $k => $v){

  if(is_array($v) && isset($v['ts'])){
   if(($now - $v['ts']) > $maxAge){
    unset($data[$k]);
    $changed = true;
   }
  }

  elseif(is_numeric($v)){
   if(($now - $v) > $maxAge){
    unset($data[$k]);
    $changed = true;
   }
  }
 }

 if($changed){
  file_put_contents($file, json_encode($data), LOCK_EX);
 }

 return $data;
}

function write_block_raw(string $file, string $txt): void {
    file_put_contents($file, $txt, FILE_APPEND | LOCK_EX);
}

function write_block(string $file, array $kv): void {
    $txt = "\n==================================================\n";
    foreach ($kv as $k => $v) {
        $txt .= str_pad($k, 14) . " : " . $v . "\n";
    }
    $txt .= "==================================================\n";
    write_block_raw($file, $txt);
}

function log_once(string $file, array $kv): void {
    $hashable = json_encode($kv);
    $h = hash('sha256', $hashable);
    $now = time();
    $last = $_SESSION['LAST_LOG'] ?? null;
    if ($last && ($last['h'] ?? '') === $h && ($now - ($last['t'] ?? 0)) <= LOG_DEDUPE_WINDOW) return;
    $_SESSION['LAST_LOG'] = ['h' => $h, 't' => $now];
    write_block($file, $kv);
}

/* ================= SECURITY FUNCTIONS ================= */
function bot_score_add($ip,$p){
 global $botFile;
 $d=json_clean_load($botFile);
 if(!isset($d[$ip])) $d[$ip]=['score'=>0,'ts'=>time()];
 $d[$ip]['score']+=$p;
 $d[$ip]['ts']=time();
 file_put_contents($botFile,json_encode($d),LOCK_EX);
 return $d[$ip]['score'];
}

function bot_score_get($ip){
 global $botFile;
 $d=json_clean_load($botFile);
 return $d[$ip]['score']??0;
}

function adaptive_bot_threshold(){
 global $botFile;
 $d=json_clean_load($botFile);
 if(empty($d)) return BOT_SCORE_BASE;
 $scores=array_column($d,'score');
 sort($scores);
 $mid=$scores[floor(count($scores)/2)]??BOT_SCORE_BASE;
 return max(BOT_SCORE_BASE,$mid*1.5);
}

function stealth_check_ip($ip){
 global $stealthFile,$errorPage;

 $d=json_clean_load($stealthFile);

 if(isset($d[$ip]) && $d[$ip]>time()){

  http_response_code(404);

  if(file_exists($errorPage)){
   readfile($errorPage);
  } else {
   echo "Not Found";
  }

  exit;
 }
}


function stealth_add_ip($ip){
 global $stealthFile;
 $d=json_clean_load($stealthFile);
 $d[$ip]=time()+STEALTH_BAN_TIME;
 file_put_contents($stealthFile,json_encode($d),LOCK_EX);
}

function ip_rate_ok($ip){
 global $ipFailFile;
 $d=json_clean_load($ipFailFile);
 $now=time();
 if(!isset($d[$ip])) $d[$ip]=[];
 $d[$ip]=array_filter($d[$ip],fn($t)=>($now-$t)<RATE_LIMIT_WINDOW);
 file_put_contents($ipFailFile,json_encode($d),LOCK_EX);
 return count($d[$ip])<RATE_LIMIT_MAX;
}

function ip_rate_add($ip){
 global $ipFailFile;
 $d=json_clean_load($ipFailFile);
 $d[$ip][] = time();
 file_put_contents($ipFailFile,json_encode($d),LOCK_EX);
}
function token_used_check($sig){
 global $tokenFile, $securityLog, $VISITOR_ID;

 $d = json_clean_load($tokenFile);

 if(!isset($d[$sig])) return false;

 $rec = $d[$sig];

 if(!is_array($rec)) return false;

 /* Expired */
 if(time() > ($rec['exp'] ?? 0)){
  return false;
 }

 /* Same session + same IP */
 if(($rec['sid'] ?? '') === session_id()){

    if(($rec['ip'] ?? '') !== client_ip()){

    /* Score original IP slightly */
    if(!empty($rec['ip'])){
        bot_score_add($rec['ip'], 1);
    }

    /* Score new IP slightly higher */
    bot_score_add(client_ip(), 2);
}
  /* ================= DEVICE CHANGE DETECTION (LOG ONLY) ================= */

   $currentUA = $_SERVER['HTTP_USER_AGENT'] ?? '';
$storedUA  = $rec['ua'] ?? null;

if($storedUA !== null && $storedUA !== $currentUA){

    bot_score_add(client_ip(), 3);

    token_leak_track($sig, client_ip());

    log_once($securityLog,[
        "TIME"=>date("Y-m-d H:i:s"),
        "SESSION"=>$VISITOR_ID,
        "IP"=>client_ip(),
        "EVENT"=>"DEVICE_CHANGE_DETECTED",
        "OLD_DEVICE"=>$storedUA,
        "NEW_DEVICE"=>$currentUA
    ]);
}

   /* Increase refresh counter */
   $d[$sig]['rc'] = ($rec['rc'] ?? 0) + 1;
   $d[$sig]['ts'] = time();

   file_put_contents($tokenFile,json_encode($d),LOCK_EX);

   /* Allow max 20 refresh */
   if($d[$sig]['rc'] <= 20){
     return false;
   }

   return true; // block after 20
 }

 return true;
}
 

function token_used_add($sig){
 global $tokenFile;

 $d = json_clean_load($tokenFile);

 // ONLY create token if it does not exist
 if(!isset($d[$sig])){
   $d[$sig] = [
     'exp' => time() + TOKEN_REPLAY_WINDOW,
     'ip'  => client_ip(),
     'sid' => session_id(),
     'ua'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
     'ts'  => time(),
     'rc'  => 0
   ];

   file_put_contents($tokenFile,json_encode($d),LOCK_EX);
 }
} 

function token_leak_track($sig,$ip){
 global $leakFile;
 $d=json_clean_load($leakFile);
 if(!isset($d[$sig])) $d[$sig]=['ips'=>[],'ts'=>time()];
 if(!in_array($ip,$d[$sig]['ips'])) $d[$sig]['ips'][]=$ip;
 file_put_contents($leakFile,json_encode($d),LOCK_EX);
 return count($d[$sig]['ips']);
}
/* ================= SECURITY PRE CHECK ================= */
$IP = client_ip();

/* ================= ACCESS TYPE CLASSIFIER ================= */
function classify_access_type($eventCombined, $routeId){

    $now = time();
    $last = $_SESSION['LAST_CLASSIFY'] ?? null;

$type = "USER_ACTION";


/* Redirect detection */
if (strpos($eventCombined,'REDIRECT') !== false) {
    $type = "REDIRECT_CHAIN";
} 

// Session expire = redirect to home from another route
if (
    $routeId === '0' &&
    strpos($eventCombined,'SIMPLE_ID_REDIRECT') !== false &&
    isset($last['route']) &&
    $last['route'] !== '0'
){
    $type = "SESSION_EXPIRE";
}

if ($last) {
    $diff = $now - ($last['time'] ?? 0);

    // If previous was SESSION EXPIRE redirect → next same route = background fetch
    if (
        ($last['route'] ?? '') === '0' &&
        $routeId !== '0' &&
        $diff <= 3
    ){
        $type = "BACKGROUND_FETCH";
    }

    // Fast repeat = reload / resource
    if (
        ($last['route'] ?? '') === $routeId &&
        $diff <= 2
    ){
        $type = "AUTO_BROWSER";
    }

    // Slight delay repeat = background fetch
    if (
        ($last['route'] ?? '') === $routeId &&
        $diff > 2 && $diff <= 20
    ){
        $type = "BACKGROUND_FETCH";
    }
} 

$_SESSION['LAST_CLASSIFY'] = [
    'route'=>$routeId,
    'time'=>$now,
    'event'=>$eventCombined
];

    return $type;
}

/* ================= DIRECTORY INDEX RESOLVER ================= */
function resolve_directory_index(string $absPath) {

    if (is_dir($absPath)) {

        $indexes = ['index.php', 'index.html', 'index.htm'];

        foreach ($indexes as $i) {

            $try = $absPath . DIRECTORY_SEPARATOR . $i;

            if (file_exists($try) && !is_dir($try)) {
                return $try;
            }
        }

        // Directory exists but NO index → return FALSE
        return false;
    }

    return $absPath;
} 
/* ================= NET INFO ================= */
function get_net_info(string $ip): array {
    if (!empty($_SESSION['NET_INFO']) && $_SESSION['NET_INFO']['ip'] === $ip && (time() - ($_SESSION['NET_INFO']['ts'] ?? 0)) < 86400) {
        return $_SESSION['NET_INFO'];
    }

    $res = ['ip'=>$ip,'country'=>'Unknown','city'=>'Unknown','isp'=>'Unknown ISP','proxy'=>false,'hosting'=>false,'mobile'=>false,'ts'=>time()];
    $url = "http://ip-api.com/json/{$ip}?fields=status,country,city,isp,proxy,hosting,mobile";
    $r = @file_get_contents($url);

    if ($r) {
        $d = @json_decode($r,true);
        if (!empty($d) && ($d['status'] ?? '') === 'success') {
            $res['country'] = $d['country'] ?? $res['country'];
            $res['city']    = $d['city'] ?? $res['city'];
            $res['isp']     = $d['isp'] ?? $res['isp'];
            $res['proxy']   = !empty($d['proxy']);
            $res['hosting'] = !empty($d['hosting']);
            $res['mobile']  = !empty($d['mobile']);
        }
    }

    $_SESSION['NET_INFO'] = $res;
    return $res;
}

/* ================= SIGNING ================= */

function build_sig_base(array $params): string {
    ksort($params);
    return http_build_query($params);
}

function sign_url(string $id, array $extra = [], int $ttl = SIGNED_TTL): string {

 $cleanExtra = [];

foreach (SIGNED_EXTRA_PARAMS as $k) {
    if (isset($extra[$k])) {
        $cleanExtra[$k] = $extra[$k];
    }
}


    $params = array_merge([
        'id' => $id,
        'exp' => time() + $ttl,
        'router' => ROUTER_NAME
    ], $cleanExtra);

    $base = build_sig_base($params);
    $sig  = hash_hmac('sha256', $base, SIGN_SECRET);

    return '/v.php?' . $base . '&sig=' . $sig;
}


function verify_signature_details(): array {

    $reasons = [];
    $now = time();

    /* ================= BASIC FIELD CHECK ================= */

    if (!isset($_GET['sig']) || !isset($_GET['exp'])) {
        $reasons[] = 'missing_sig_or_exp';
        return ['ok'=>false,'reasons'=>$reasons];
    }

    if ((int)$_GET['exp'] < $now) {
        $reasons[] = 'expired';
    }

    if (!isset($_GET['router'])) {
        $reasons[] = 'missing_router_field';
    } else {
        if ($_GET['router'] !== ROUTER_NAME) {
            $reasons[] = 'signed_router_mismatch(' . ($_GET['router'] ?? '') . ')';
        }
    }

   /* ================= WHITELIST SIGNATURE DATASET ================= */

$allowed = array_merge(
    ['id','exp','router'],
    SIGNED_EXTRA_PARAMS
);

$data = [];

foreach($allowed as $k){
    if(isset($_GET[$k])){
        $data[$k] = $_GET[$k];
    }
}

if(!isset($_GET['sig'])){
    $reasons[] = 'missing_signature';
    return ['ok'=>false,'reasons'=>$reasons];
}

$sig = $_GET['sig'];

/* ================= REBUILD SIGNATURE ================= */

$base = build_sig_base($data);
$calc = hash_hmac('sha256', $base, SIGN_SECRET);

if (!hash_equals($calc, $sig)) {
    $reasons[] = 'signature_mismatch';
}

    /* ================= ROUTER PATH CHECK ================= */

    $reqPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $reqBase = trim(basename($reqPath));

    if ($reqBase !== ROUTER_NAME) {
        $reasons[] = 'requested_path_mismatch(' . ($reqBase === '' ? '/' : $reqBase) . ')';
    }

    /* ================= RESULT ================= */

    return [
        'ok' => empty($reasons),
        'reasons' => $reasons
    ];
}


/* ================= PATH HELPERS ================= */
function normalize_rel_path(string $path): string {

    $path = str_replace('\\','/',$path);

    $path = preg_replace('#^\./#','',$path);

    $path = preg_replace('#/+#','/',$path);

    return trim($path,'/');
}

function safe_realpath_within(string $baseDir, string $relPath) {
    $candidate = realpath($baseDir.'/'.ltrim($relPath,'/'));
    if ($candidate === false) return false;

    $baseReal = realpath($baseDir);
    if ($baseReal === false) return false;

    if (strpos($candidate,$baseReal) !== 0) return false;
    return $candidate;
}

function load_map(string $file): array {
    if (!file_exists($file)) return [];

    $raw = @file_get_contents($file);
    $d = @json_decode($raw, true);

    if (!is_array($d)) return [];

    foreach ($d as $k => $v) {

        if (is_string($v)) {
            $d[$k] = [
                'url' => $v,
                'count' => 0,
                'last_used' => 0,
                'daily' => [],
                'unique_ips' => [],
                'access_types' => [],
                'bot_hits' => 0
            ];
        }

        $d[$k]['daily'] = $d[$k]['daily'] ?? [];
        $d[$k]['unique_ips'] = $d[$k]['unique_ips'] ?? [];
        $d[$k]['access_types'] = $d[$k]['access_types'] ?? [];
        $d[$k]['bot_hits'] = $d[$k]['bot_hits'] ?? 0;
    }

    return $d;
}

function save_map(string $file, array $map): void {
    file_put_contents($file,json_encode($map,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
}
function update_route_metrics(&$map, $mapFile, $id, $ip, $accessType){

    if (!isset($map[$id])) return;

    $today = date('Y-m-d');

    $map[$id]['count']++;
    $map[$id]['last_used'] = time();

    if (!isset($map[$id]['daily'][$today])) {
        $map[$id]['daily'][$today] = 0;
    }
    $map[$id]['daily'][$today]++;

    if (!in_array($ip, $map[$id]['unique_ips'])) {
        $map[$id]['unique_ips'][] = $ip;

        if (count($map[$id]['unique_ips']) > MAX_UNIQUE_IP_TRACK) {
            array_shift($map[$id]['unique_ips']);
        }
    }

    if (!isset($map[$id]['access_types'][$accessType])) {
        $map[$id]['access_types'][$accessType] = 0;
    }
    $map[$id]['access_types'][$accessType]++;

    if (bot_score_get($ip) > adaptive_bot_threshold()) {
        $map[$id]['bot_hits']++;
    }

    save_map($mapFile, $map);
}

/* ================= INIT ================= */
$map = load_map($mapFile);
if (!isset($map['0'])) {
 $map['0'] = [
  'url' => 'index.html',
  'count' => 0,
  'last_used' => 0,
  'daily' => [],
  'unique_ips' => [],
  'access_types' => [],
  'bot_hits' => 0
 ];
}

if (!isset($map['999'])) {
 $map['999'] = [
  'url' => 'error.html',
  'count' => 0,
  'last_used' => 0,
  'daily' => [],
  'unique_ips' => [],
  'access_types' => [],
  'bot_hits' => 0
 ];
}

save_map($mapFile,$map);

/* ================= REQUEST INFO ================= */
$IP       = client_ip();
$UA_FULL  = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
$UA_SHORT = ua_short();
$NET      = get_net_info($IP);
$ORIG_URI = $_SERVER['REQUEST_URI'];
$PATH     = ltrim(parse_url($ORIG_URI, PHP_URL_PATH), '/');

/* ================= PENDING LOG MERGE ================= */
function set_pending_log(array $data){
    $_SESSION['PENDING_LOG'] = $data;
}

function get_pending_log(){
    return $_SESSION['PENDING_LOG'] ?? null;
}

function clear_pending_log(){
    unset($_SESSION['PENDING_LOG']);
}

/* ================= FORBIDDEN ================= */
$forbidden_entries = ['.env','.git','.htaccess','config.php','database.php','/.set/','/.set','/.private','/.private/'];

foreach ($forbidden_entries as $f) {
 if (stripos($ORIG_URI,$f) !== false) {

  log_once($securityLog,[
   "TIME"=>date("Y-m-d H:i:s"),
   "SESSION"=>$VISITOR_ID,
   "IP"=>$IP,
   "DEVICE"=>$UA_FULL,
   "LOCATION"=>($NET['country'] ?? "Unknown").", ".($NET['city'] ?? "Unknown"),
   "ISP"=>$NET['isp'] ?? "Unknown ISP",
   "BROWSER"=>$UA_SHORT,
   "NETWORK"=>($NET['proxy']?"VPN/Proxy":($NET['hosting']?"Hosting":($NET['mobile']?"Mobile":"Normal"))),
   "EVENT"=>"FORBIDDEN_ATTEMPT",
   "TARGET"=>$ORIG_URI
  ]);

  http_response_code(404);
  if (file_exists($errorPage)) readfile($errorPage);
  else echo "Not Found";
  exit;
 }
}

/* ================= ERROR RESPONDER ================= */
function respond_error_and_log(string $label, array $info): void {
 global $securityLog,$errorPage,$VISITOR_ID,$IP,$NET,$UA_SHORT,$UA_FULL;

 $full = [
  "TIME"=>date("Y-m-d H:i:s"),
  "SESSION"=>$VISITOR_ID,
  "IP"=>$IP,
  "DEVICE"=>$UA_FULL,
  "LOCATION"=>($NET['country'] ?? "Unknown").", ".($NET['city'] ?? "Unknown"),
  "ISP"=>$NET['isp'] ?? "Unknown ISP",
  "BROWSER"=>$UA_SHORT,
  "NETWORK"=>($NET['proxy']?"VPN/Proxy":($NET['hosting']?"Hosting":($NET['mobile']?"Mobile":"Normal"))),
  "EVENT"=>$label
 ];

 $full = array_merge($full,$info);
if (SECURITY_LOG_ENABLED) {
    log_once($securityLog,$full);
} 

 http_response_code(404);
 if(file_exists($errorPage)) readfile($errorPage);
 else echo "Not Found";
 exit;
}

/* ================= SIGNED ACCESS ================= */
if (isset($_GET['id']) && isset($_GET['sig'])) {

    $verify = verify_signature_details();
    if (!$verify['ok']) {

    ip_rate_add($IP);
    bot_score_add($IP,10);

    respond_error_and_log('TAMPER_OR_INVALID_SIGNATURE',[
        'URL'=>$ORIG_URI,
        'DETAILS'=>implode('; ',$verify['reasons'] ?? [])
    ]);
}
/* SECURITY POST VERIFY */

if(TOKEN_SECURITY_ENABLED && token_used_check($_GET['sig'])){
 bot_score_add($IP,15);
 respond_error_and_log('TOKEN_REPLAY',[
   'URL'=>$ORIG_URI
 ]);
}

if (TOKEN_SECURITY_ENABLED) {
    token_used_add($_GET['sig']);
}

$leakCount = token_leak_track($_GET['sig'],$IP);
if($leakCount > 5){
 bot_score_add($IP,20);
}

    $id = $_GET['id'];

    if (!isset($map[$id])) {
        respond_error_and_log('UNKNOWN_ROUTE_ID',[
            'URL'=>$ORIG_URI,
            'ROUTE'=>$id
        ]);
    }

    $original_logged = $_SESSION['ORIGINAL_REQUEST_URI'] ?? $ORIG_URI;
    unset($_SESSION['ORIGINAL_REQUEST_URI']);

    $signedData = $_GET;
    unset($signedData['sig']);
    $_GET = $signedData;
    $_SERVER['QUERY_STRING'] = http_build_query($signedData);

    $routeData = $map[$id];
    $mappedRel = $routeData['url'];
    $mappedAbs = safe_realpath_within(__DIR__, $mappedRel);

if ($mappedAbs !== false) {
    $mappedAbs = resolve_directory_index($mappedAbs);
}

/* FILE MISSING → NORMAL ERROR */
if (!file_exists($mappedAbs)) {
    respond_error_and_log('MAPPED_FILE_MISSING',[
        'ROUTE'=>$id,
        'FILE'=>$mappedRel
    ]);
} 

    /* ===== MERGE EVENT LOGIC ===== */
    $pending = get_pending_log();

    if($pending && ($pending['ROUTE ID'] ?? '') === $id){
        $eventCombined = $pending['EVENT'] . ',SIGNED_ACCESS_ALLOWED';
        clear_pending_log();
    } else {
        $eventCombined = 'SIGNED_ACCESS_ALLOWED';
    }
$accessType = classify_access_type($eventCombined, $id);
update_route_metrics($map, $mapFile, $id, $IP, $accessType);
    $logEntry = [
        'TIME'=>date('Y-m-d H:i:s'),
        'SESSION'=>$VISITOR_ID,
        'IP'=>$IP,
        'DEVICE'=>$UA_FULL,
        'LOCATION'=>($NET['country'] ?? 'Unknown').', '.($NET['city'] ?? 'Unknown'),
        'ISP'=>$NET['isp'] ?? 'Unknown ISP',
        'NETWORK'=>($NET['proxy']?'VPN/Proxy':($NET['hosting']?'Hosting':($NET['mobile']?'Mobile':'Normal'))),
        'EVENT'=>$eventCombined,
        'ACCESS_TYPE'=>$accessType,
        'ROUTE ID'=>$id,
        'FILE'=>$mappedRel,
        'ORIGINAL URL'=>$original_logged,
        'FINAL URL'=>$ORIG_URI
    ];

/* ===== SMART ROUTER ACCESS DEDUPE (TIME WINDOW BASED) ===== */

$dedupeKey = $VISITOR_ID . "|" . $id;
$now = time();

$last = $_SESSION['LAST_ROUTER_ACCESS'] ?? null;

$shouldLog = true;

if ($last) {
    if (
        ($last['key'] ?? '') === $dedupeKey &&
        ($now - ($last['time'] ?? 0)) <= 2
    ) {
        // Same route accessed within 2 seconds → likely reload/resource → skip
        $shouldLog = false;
    }
}

if ($shouldLog && ACCESS_LOG_ENABLED) {
    log_once($accessLog,$logEntry);
    $_SESSION['LAST_ROUTER_ACCESS'] = [
        'key' => $dedupeKey,
        'time' => $now
    ];
}


    if (strtolower(pathinfo($mappedAbs, PATHINFO_EXTENSION)) === 'php') include $mappedAbs;
    else {
        $mime = @mime_content_type($mappedAbs) ?: 'application/octet-stream';
        header('Content-Type: '.$mime);
        readfile($mappedAbs);
    }
    exit;
}

/* ================= SIMPLE ID ================= */
if (isset($_GET['id']) && !isset($_GET['sig'])) {

    $id = $_GET['id'];

    if (!isset($map[$id])) {
        respond_error_and_log('UNKNOWN_ROUTE_ID',[
            'URL'=>$ORIG_URI,
            'ROUTE'=>$id
        ]);
    }

    $qs = $_GET;
    unset($qs['sig']);

    $extra = $qs;
    unset($extra['id']);

    $_SESSION['ORIGINAL_REQUEST_URI'] = $ORIG_URI;

    $signed = sign_url($id,$extra,SIGNED_TTL);

    /* STORE PENDING LOG */
    set_pending_log([
        'EVENT'=>'SIMPLE_ID_REDIRECT',
        'ROUTE ID'=>$id
    ]);

    header('Location: '.$signed);
    exit;
}

/* ================= ROOT ================= */
if ($PATH === '' || $PATH === 'v.php') {
    $_SESSION['ORIGINAL_REQUEST_URI'] = $ORIG_URI;
    set_pending_log([
        'EVENT'=>'ROOT_REDIRECT',
        'ROUTE ID'=>'0'
    ]);
    $signed = sign_url('0',[],SIGNED_TTL);
    header('Location: '.$signed);
    exit;
}

/* ================= REAL PATH ================= */
$realCandidate = safe_realpath_within(__DIR__, $PATH);

if ($realCandidate !== false) {
    $realCandidate = resolve_directory_index($realCandidate);
}
/* ===== CANONICAL RELATIVE PATH NORMALIZER ===== */

$canonicalRelPath = null;

if ($realCandidate !== false) {

    $baseReal = realpath(__DIR__);

    if ($baseReal !== false) {
        $canonicalRelPath = ltrim(str_replace('\\','/',
            substr($realCandidate, strlen($baseReal))
        ), '/');
    }
}


if ($realCandidate !== false && file_exists($realCandidate)) {

$lookupKey = normalize_rel_path(
    $canonicalRelPath ?: $PATH
);

$found = false;

foreach ($map as $existingId => $routeData) {

    if (
        isset($routeData['url']) &&
        normalize_rel_path($routeData['url']) === $lookupKey
    ) {
        $found = $existingId;
        break;
    }
}


$created_new = false;

if ($found === false) {

    if (!ROUTE_CREATION_ENABLED) {
        respond_error_and_log('ROUTE_CREATION_DISABLED',[
            'PATH'=>$lookupKey
        ]);
    }

    $newId = substr(md5($PATH.microtime(true)),0,6);

    while (isset($map[$newId])) {
        $newId = substr(md5($PATH.rand()),0,6);
    }

    $map[$newId] = [
        'url' => normalize_rel_path($lookupKey),
        'count' => 0,
        'last_used' => 0,
        'daily' => [],
        'unique_ips' => [],
        'access_types' => [],
        'bot_hits' => 0
    ];

    save_map($mapFile,$map);

    $found = $newId;
    $created_new = true;
}

    parse_str($_SERVER['QUERY_STRING'] ?? '',$qs);
    unset($qs['sig'],$qs['exp']);

    $_SESSION['ORIGINAL_REQUEST_URI'] = $ORIG_URI;

    if (AUTO_SIGN_REDIRECT) {

    $signed = sign_url($found,$qs,SIGNED_TTL);

    set_pending_log([
        'EVENT'=>($created_new?'MAPPING_CREATED_AND_REDIRECT':'REALPATH_REDIRECT'),
        'ROUTE ID'=>$found
    ]);

    header('Location: '.$signed);
    exit;

} else {

    // direct include if redirect disabled
    if (strtolower(pathinfo($realCandidate, PATHINFO_EXTENSION)) === 'php') {
        include $realCandidate;
    } else {
        $mime = @mime_content_type($realCandidate) ?: 'application/octet-stream';
        header('Content-Type: '.$mime);
        readfile($realCandidate);
    }

    exit;
}
} // closes: if ($realCandidate !== false && file_exists($realCandidate))
/* ================= NOT FOUND ================= */
respond_error_and_log('NOT_FOUND',[
    'URL'=>$ORIG_URI
]);
