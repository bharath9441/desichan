<?php
/**
 * DesiChan backend — mirrors futaba-ng's flat-file format.
 * Endpoints (all JSON):
 *   ?action=ping
 *   ?action=threads&board=desi
 *   ?action=thread&board=desi&id=123
 *   POST action=post     board,resto,name,email,subject,comment,pwd,upfile
 *   POST action=delete   board,no,pwd
 *   POST action=admin_list   pwd
 *   POST action=admin_delete board,no,pwd
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

define('DATA_DIR', __DIR__ . '/data/');
define('LOGFILE', 'img.log');
define('TREEFILE', 'tree.log');
define('MAX_KB', 500);
define('MAX_W', 250);
define('MAX_H', 250);
define('MAX_RES', 30);
define('RENZOKU', 5);       // seconds between posts
define('RENZOKU2', 10);     // seconds between image posts
define('MAX_COMMENT', 4000);
define('MAX_NAME', 100);
define('MAX_SUB', 100);
define('LOG_MAX', 500);
define('ADMIN_PASS', 'change_me_admin');
define('IDSEED', 'change_me_seed');

$BOARDS = ['desi','cricket','cinema','food','tech','memes','padhai','music',
           'charcha','safar','sports','kala','sahitya','fit','gaadi','random'];
$BAD_STRINGS = ['dummy_bad_word'];
$BAD_MD5 = [];
$BAD_HOSTS = [];

/* ---------- helpers ---------- */
function fail($m){ echo json_encode(['ok'=>false,'error'=>$m]); exit; }
function ok($d=[]){ echo json_encode(array_merge(['ok'=>true],$d)); exit; }
function validBoard($b){ global $BOARDS; return in_array($b,$BOARDS,true); }

function boardDir($b){
  if(!validBoard($b)) fail('invalid board');
  return DATA_DIR . $b . '/';
}

function ensureBoard($b){
  $dir = boardDir($b);
  foreach([$dir,$dir.'src/',$dir.'thumb/'] as $d) if(!is_dir($d)) @mkdir($d,0777,true);
  foreach([LOGFILE,TREEFILE] as $f){
    $p = $dir.$f;
    if(!file_exists($p)){ file_put_contents($p,''); @chmod($p,0666); }
  }
  return $dir;
}

function readCsvLines($f){
  if(!file_exists($f)) return [];
  $raw = file_get_contents($f);
  if($raw===false||$raw==='') return [];
  $out=[];
  foreach(preg_split('/\r?\n/',$raw) as $l){
    if($l!=='') $out[] = str_getcsv($l);
  }
  return $out;
}

function writeCsvLines($f,$rows){
  $buf='';
  foreach($rows as $r){
    $buf .= implode(',', array_map(function($v){
      $v = (string)$v;
      if(strpos($v,',')!==false || strpos($v,'"')!==false || strpos($v,"\n")!==false){
        return '"'.str_replace('"','""',$v).'"';
      }
      return $v;
    },$r))."\n";
  }
  $fp=fopen($f,'c+');
  if(!$fp) return false;
  flock($fp,LOCK_EX);
  ftruncate($fp,0); rewind($fp);
  fwrite($fp,$buf); fflush($fp);
  flock($fp,LOCK_UN); fclose($fp);
  return true;
}

function posterId(){
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  return strtoupper(substr(md5($ip.IDSEED.gmdate('Ymd')),0,8));
}

function nowStr(){
  $days=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  $t = time() + 9*3600;
  return gmdate('y/m/d',$t).'('.$days[(int)gmdate('w',$t)].')'.gmdate('H:i',$t);
}

function sanitizeText($s,$max){
  $s = str_replace("\0",'',(string)$s);
  $s = trim($s);
  $s = function_exists('mb_substr') ? mb_substr($s,0,$max,'UTF-8') : substr($s,0,$max);
  return $s;
}

function cleanForLog($s){ return str_replace(["\r","\n"],' ',(string)$s); }

/* ---------- routing ---------- */
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if($action==='ping') ok(['mode'=>'server','time'=>time()]);
if($action==='boards'){ global $BOARDS; ok(['boards'=>$BOARDS]); }
if($action==='threads'){ handleThreads(); }
if($action==='thread'){ handleThread(); }
if($action==='post'){ handlePost(); }
if($action==='delete'){ handleDelete(); }
if($action==='admin_list'){ handleAdminList(); }
if($action==='admin_delete'){ handleAdminDelete(); }
fail('unknown action');

/* ---------- handlers ---------- */
function handleThreads(){
  $b = $_GET['board'] ?? '';
  if(!validBoard($b)) fail('invalid board');
  $dir = ensureBoard($b);
  $tree = readCsvLines($dir.TREEFILE);
  $log = readCsvLines($dir.LOGFILE);
  $byNo=[]; foreach($log as $r) if(count($r)>=14) $byNo[$r[0]]=$r;
  $threads=[];
  foreach($tree as $row){
    if(empty($row[0])) continue;
    if(!isset($byNo[$row[0]])) continue;
    $replies=[];
    for($i=1;$i<count($row);$i++)
      if(isset($byNo[$row[$i]])) $replies[] = rowToReply($byNo[$row[$i]],$b);
    $threads[] = rowToThread($byNo[$row[0]],$b,$replies);
  }
  ok(['threads'=>$threads]);
}

function handleThread(){
  $b = $_GET['board'] ?? '';
  $id = (int)($_GET['id'] ?? 0);
  if(!validBoard($b)||!$id) fail('invalid');
  $dir = ensureBoard($b);
  $tree = readCsvLines($dir.TREEFILE);
  $log = readCsvLines($dir.LOGFILE);
  $byNo=[]; foreach($log as $r) if(count($r)>=14) $byNo[$r[0]]=$r;
  foreach($tree as $row){
    if((int)$row[0]===$id){
      if(!isset($byNo[$id])) fail('not found');
      $replies=[];
      for($i=1;$i<count($row);$i++)
        if(isset($byNo[$row[$i]])) $replies[] = rowToReply($byNo[$row[$i]],$b);
      ok(['thread'=>rowToThread($byNo[$id],$b,$replies)]);
    }
  }
  fail('not found');
}

function handlePost(){
  global $BAD_STRINGS;
  $b = $_POST['board'] ?? '';
  if(!validBoard($b)) fail('invalid board');
  $dir = ensureBoard($b);

  $name = sanitizeText($_POST['name'] ?? '',MAX_NAME) ?: 'Gupt';
  $email = sanitizeText($_POST['email'] ?? '',100);
  $sub = sanitizeText($_POST['subject'] ?? '',MAX_SUB);
  $comment = sanitizeText($_POST['comment'] ?? '',MAX_COMMENT);
  $pwd = sanitizeText($_POST['pwd'] ?? '',32);
  $resto = (int)($_POST['resto'] ?? 0);

  if($comment==='') fail('Comment cannot be empty');
  foreach($BAD_STRINGS as $bs){
    if($bs && (stripos($comment,$bs)!==false||stripos($name,$bs)!==false||stripos($sub,$bs)!==false))
      fail('Rejected');
  }

  // flood control
  $last = (int)($_COOKIE['lastpost'] ?? 0);
  if($last && time()-$last < RENZOKU) fail('Flood control — wait '.RENZOKU.'s');

  // file upload
  $fi = ['ext'=>'','w'=>'','h'=>'','tim'=>'','size'=>0,'orig'=>'','ow'=>0,'oh'=>0];
  if(!empty($_FILES['upfile']['tmp_name']) && is_uploaded_file($_FILES['upfile']['tmp_name'])){
    $size = (int)$_FILES['upfile']['size'];
    if($size > MAX_KB*1024) fail('File too large (max '.MAX_KB.' KB)');
    $info = @getimagesize($_FILES['upfile']['tmp_name']);
    if(!$info) fail('Not a valid image');
    switch($info[2]){
      case IMAGETYPE_GIF: $ext='.gif'; break;
      case IMAGETYPE_JPEG: $ext='.jpg'; break;
      case IMAGETYPE_PNG: $ext='.png'; break;
      default: fail('Unsupported image type');
    }
    $tim = time().substr(microtime(),2,3);
    $orig = $dir.'src/'.$tim.$ext;
    if(!move_uploaded_file($_FILES['upfile']['tmp_name'],$orig)) fail('Upload failed');
    @chmod($orig,0666);
    makeThumb($orig,$dir.'thumb/'.$tim.'s.jpg',MAX_W,MAX_H);
    $fi = [
      'ext'=>$ext,'w'=>min((int)$info[0],MAX_W),'h'=>min((int)$info[1],MAX_H),
      'tim'=>$tim,'size'=>$size,'ow'=>(int)$info[0],'oh'=>(int)$info[1]
    ];
    $fi['orig'] = $tim.$ext;
  }

  $passHash = $pwd!=='' ? substr(md5($pwd),2,8) : '*';
  $log = readCsvLines($dir.LOGFILE);
  $tree = readCsvLines($dir.TREEFILE);

  $maxNo=0; foreach($log as $r) $maxNo = max($maxNo,(int)$r[0]);
  $no = $maxNo + 1;
  $now = nowStr();
  $host = @gethostbyaddr($_SERVER['REMOTE_ADDR']??'') ?: ($_SERVER['REMOTE_ADDR']??'');
  $chk = $fi['tim'] ? @md5_file($dir.'src/'.$fi['orig']) : '';

  $row = [$no,$now,$name,$email,$sub,cleanForLog($comment),'',
          $host,$passHash,$fi['ext'],$fi['w'],$fi['h'],$fi['tim'],$chk];
  array_unshift($log,$row);
  if(count($log)>LOG_MAX) $log = array_slice($log,0,LOG_MAX);

  if($resto){
    $idx = null;
    foreach($tree as $i=>$t){ if((int)$t[0]===$resto){ $idx=$i; break; } }
    if($idx===null) fail('Thread not found');
    $tree[$idx][] = $no;
    if(strtolower($email)!=='sage'){
      $moved = $tree[$idx];
      array_splice($tree,$idx,1);
      array_unshift($tree,$moved);
    }
  } else {
    array_unshift($tree,[$no]);
  }

  writeCsvLines($dir.LOGFILE,$log);
  writeCsvLines($dir.TREEFILE,$tree);
  setcookie('lastpost',time(),time()+3600,'/');

  $newPost = rowToReply($row,$b);
  if($resto) ok(['no'=>$no,'reply'=>$newPost]);
  else {
    $newPost['subject'] = $sub ?: 'No subject';
    $newPost['replies'] = [];
    ok(['no'=>$no,'thread'=>$newPost]);
  }
}

function makeThumb($src,$dst,$maxW,$maxH){
  if(!function_exists('imagecreatefromjpeg')) return false;
  $info = @getimagesize($src);
  if(!$info) return false;
  $w=$info[0]; $h=$info[1];
  switch($info[2]){
    case IMAGETYPE_JPEG: $im=@imagecreatefromjpeg($src); break;
    case IMAGETYPE_PNG:  $im=@imagecreatefrompng($src);  break;
    case IMAGETYPE_GIF:  $im=@imagecreatefromgif($src);  break;
    default: return false;
  }
  if(!$im) return false;
  $ratio = min($maxW/$w,$maxH/$h,1);
  $nw = max(1,(int)round($w*$ratio));
  $nh = max(1,(int)round($h*$ratio));
  $out = function_exists('imagecreatetruecolor')
    ? imagecreatetruecolor($nw,$nh) : imagecreate($nw,$nh);
  $white = imagecolorallocate($out,255,255,255);
  imagefill($out,0,0,$white);
  imagecopyresampled($out,$im,0,0,0,0,$nw,$nh,$w,$h);
  imagejpeg($out,$dst,75);
  @chmod($dst,0666);
  imagedestroy($im); imagedestroy($out);
  return true;
}

function rowToReply($r,$board){
  // [no,now,name,email,sub,comment,url,host,pass,ext,W,H,tim,chk]
  $file = null;
  if(!empty($r[9]) && !empty($r[12])){
    $srcRel = 'data/'.$board.'/src/'.$r[12].$r[9];
    $thumbRel = 'data/'.$board.'/thumb/'.$r[12].'s.jpg';
    $srcAbs = __DIR__.'/'.$srcRel;
    if(file_exists($srcAbs)){
      $file = [
        'name' => $r[12].$r[9],
        'src' => $srcRel,
        'thumb' => file_exists(__DIR__.'/'.$thumbRel) ? $thumbRel : $srcRel,
        'w' => (int)$r[10], 'h' => (int)$r[11],
        'ow' => (int)$r[10], 'oh' => (int)$r[11],
        'size' => @filesize($srcAbs) ?: 0
      ];
    }
  }
  return [
    'id' => (int)$r[0],
    'name' => $r[2] ?: 'Gupt',
    'date' => (strtotime($r[1]) ?: time()/1) * 1000,
    'comment' => $r[5] ?? '',
    'posterId' => posterId(),
    'hasPassword' => ($r[8] ?? '*') !== '*',
    'file' => $file
  ];
}

function rowToThread($op,$board,$replies){
  $t = rowToReply($op,$board);
  $t['subject'] = $op[4] ?: 'No subject';
  $t['replies'] = $replies;
  return $t;
}

function handleDelete(){
  $b = $_POST['board'] ?? '';
  $no = (int)($_POST['no'] ?? 0);
  $pwd = sanitizeText($_POST['pwd'] ?? '',32);
  if(!validBoard($b)||!$no||!$pwd) fail('invalid');
  $dir = ensureBoard($b);
  $hash = substr(md5($pwd),2,8);
  $admin = ($pwd === ADMIN_PASS);

  $log = readCsvLines($dir.LOGFILE);
  $tree = readCsvLines($dir.TREEFILE);
  $newLog=[]; $found=false;
  foreach($log as $r){
    if((int)$r[0]===$no && (($r[8]??'')===$hash || $admin)){
      $found=true;
      if(!empty($r[9]) && !empty($r[12])){
        @unlink($dir.'src/'.$r[12].$r[9]);
        @unlink($dir.'thumb/'.$r[12].'s.jpg');
      }
      continue;
    }
    $newLog[] = $r;
  }
  if(!$found) fail('Not found or wrong password');
  $newTree=[];
  foreach($tree as $t){
    if((int)$t[0]===$no) continue;
    $f=[$t[0]];
    for($i=1;$i<count($t);$i++) if((int)$t[$i]!==$no) $f[]=$t[$i];
    $newTree[] = $f;
  }
  writeCsvLines($dir.LOGFILE,$newLog);
  writeCsvLines($dir.TREEFILE,$newTree);
  ok();
}

function handleAdminList(){
  global $BOARDS;
  $pwd = $_POST['pwd'] ?? $_GET['pwd'] ?? '';
  if($pwd !== ADMIN_PASS) fail('wrong password');
  $all=[];
  foreach($BOARDS as $b){
    $dir = ensureBoard($b);
    foreach(readCsvLines($dir.LOGFILE) as $r){
      if(count($r)<14) continue;
      $all[] = [
        'board'=>$b,'no'=>(int)$r[0],'time'=>$r[1],'name'=>$r[2],
        'sub'=>$r[4],'com'=>$r[5],'host'=>$r[7],
        'size'=> !empty($r[12]) ? (@filesize($dir.'src/'.$r[12].$r[9]) ?: 0) : 0
      ];
    }
  }
  ok(['posts'=>$all]);
}

function handleAdminDelete(){
  $pwd = $_POST['pwd'] ?? '';
  if($pwd !== ADMIN_PASS) fail('wrong password');
  $b = $_POST['board'] ?? '';
  $no = (int)($_POST['no'] ?? 0);
  if(!validBoard($b)||!$no) fail('invalid');
  $dir = ensureBoard($b);
  $log = readCsvLines($dir.LOGFILE);
  $tree = readCsvLines($dir.TREEFILE);
  $newLog=[]; $found=false;
  foreach($log as $r){
    if((int)$r[0]===$no){
      $found=true;
      if(!empty($r[9]) && !empty($r[12])){
        @unlink($dir.'src/'.$r[12].$r[9]);
        @unlink($dir.'thumb/'.$r[12].'s.jpg');
      }
      continue;
    }
    $newLog[] = $r;
  }
  if(!$found) fail('post not found');
  $newTree=[];
  foreach($tree as $t){
    if((int)$t[0]===$no) continue;
    $f=[$t[0]];
    for($i=1;$i<count($t);$i++) if((int)$t[$i]!==$no) $f[]=$t[$i];
    $newTree[] = $f;
  }
  writeCsvLines($dir.LOGFILE,$newLog);
  writeCsvLines($dir.TREEFILE,$newTree);
  ok();
}