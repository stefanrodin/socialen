<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
ini_set('display_errors','0');
try {
    $app=app(); $media=$app->getMedia((int)($_GET['id']??0));
    $public=is_string($_GET['key']??null)&&hash_equals($media['public_key'],$_GET['key']);
    if (!$public) $app->requireUser();
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    $file=$app->root.'/storage/uploads/'.$media['path']; $size=is_file($file)?filesize($file):false;
    if ($size===false) throw new UserError('Filen saknas.');
    header('Content-Type: '.$media['mime']); header('X-Content-Type-Options: nosniff'); header('Content-Security-Policy: default-src \'none\'');
    header('Cache-Control: '.($public?'public, max-age=300':'private, max-age=300')); header('Referrer-Policy: no-referrer'); header('Accept-Ranges: bytes');
    $start=0; $end=$size-1;
    if (isset($_SERVER['HTTP_RANGE'])) {
        if (!preg_match('/^bytes=([0-9]*)-([0-9]*)$/D',$_SERVER['HTTP_RANGE'],$match)||($match[1]===''&&$match[2]==='')) { header('Content-Range: bytes */'.$size); http_response_code(416); exit; }
        if ($match[1]==='') $start=max(0,$size-(int)$match[2]); else { $start=(int)$match[1]; if ($match[2]!=='') $end=min($end,(int)$match[2]); }
        if ($start>$end || $start>=$size) { header('Content-Range: bytes */'.$size); http_response_code(416); exit; }
        http_response_code(206); header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    }
    header('Content-Length: '.($end-$start+1));
    if ($_SERVER['REQUEST_METHOD']==='HEAD') exit;
    $stream=fopen($file,'rb'); fseek($stream,$start); $remaining=$end-$start+1;
    while ($remaining>0&&!feof($stream)&&!connection_aborted()) { $chunk=fread($stream,min(65536,$remaining)); if ($chunk===false||$chunk==='') break; echo $chunk; $remaining-=strlen($chunk); }
    fclose($stream);
} catch (Throwable) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Filen är inte tillgänglig.'; }
