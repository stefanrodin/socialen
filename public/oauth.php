<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
try {
    $app=app(); $app->session(); $count=(new OAuth($app))->finish((string)($_GET['provider']??''),$_GET);
    $_SESSION['flash']=['type'=>'success','message'=>$count?$count.' kanal'.($count===1?'':'er').' anslöts.':'Välj vilka Facebook-sidor och Instagram-konton du vill ansluta.'];
} catch (UserError|RemoteFailure $e) { if (session_status()===PHP_SESSION_ACTIVE) $_SESSION['flash']=['type'=>'error','message'=>$e->getMessage()]; }
catch (Throwable $e) { error_log('Publicera OAuth: '.get_class($e)); if (session_status()===PHP_SESSION_ACTIVE) $_SESSION['flash']=['type'=>'error','message'=>'Anslutningen kunde inte slutföras. Kontrollera appuppgifterna och försök igen.']; }
header('Location: index.php#channels',true,303);
