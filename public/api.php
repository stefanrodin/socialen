<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
ini_set('display_errors','0');
try {
    $app=app(); $app->session(); $method=$_SERVER['REQUEST_METHOD']; $action=$_GET['action']??'';
    if ($method==='GET' && $action==='bootstrap') {
        $user=$app->user();
        $revision=is_file($app->root.'/REVISION')?trim((string)file_get_contents($app->root.'/REVISION')):null;
        $data=['revision'=>$revision,'user'=>$user,'csrf'=>$_SESSION['csrf'],'demo'=>$app->demo,'name'=>$app->config['name'],'timezone'=>$app->config['timezone'],'upload_limit_mb'=>$app->config['upload_limit_mb']];
        if ($user) {
            $data['flash']=$_SESSION['flash']??null; unset($_SESSION['flash']);
            $last=$app->one("SELECT setting_value FROM settings WHERE setting_key='last_worker'");
            $data+=['posts'=>$app->posts(),'media'=>$app->mediaList(),'channels'=>$app->channels(),'last_worker'=>$last['setting_value']??null,'providers'=>[
                'google'=>!empty($app->config['google']['client_id'])&&!empty($app->config['google']['client_secret']),
                'meta'=>!empty($app->config['meta']['app_id'])&&!empty($app->config['meta']['app_secret']),
            ]];
        }
        jsonResponse($data);
    }
    if ($method!=='POST') jsonResponse(['error'=>'Metoden stöds inte.'],405);
    $app->csrf();
    if ($action==='upload') { $app->requireUser(); jsonResponse(['media'=>$app->upload($_FILES['file']??[])]); }
    if (!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json')) throw new UserError('Begäran behöver vara JSON.');
    $raw=file_get_contents('php://input',false,null,0,262145); if (strlen($raw)>262144) throw new UserError('Begäran är för stor.');
    $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR); if (!is_array($input)) throw new UserError('Ogiltig begäran.');
    if ($action==='login') { $app->login((string)($input['email']??''),(string)($input['password']??'')); jsonResponse(['ok'=>true]); }
    $app->requireUser();
    switch ($action) {
        case 'logout': $_SESSION=[]; session_destroy(); jsonResponse(['ok'=>true]);
        case 'save': jsonResponse(['id'=>$app->savePost($input)]);
        case 'cancel': $app->cancelPost((int)($input['id']??0)); jsonResponse(['ok'=>true]);
        case 'connect': jsonResponse(['url'=>(new OAuth($app))->start((string)($input['provider']??''))]);
        case 'disconnect':
            if ($app->demo) throw new UserError('Demokanalerna kan inte kopplas från.');
            $app->query('UPDATE channels SET credentials=?,active=0 WHERE id=?',[$app->seal([]),(int)($input['id']??0)]); jsonResponse(['ok'=>true]);
        case 'reconcile':
            $id=(int)($input['id']??0); $row=$app->one('SELECT * FROM publications WHERE id=?',[$id]);
            if (!$row || $row['status']!=='uncertain') throw new UserError('Publiceringen behöver ingen manuell avstämning.');
            if (($input['decision']??'')==='published') {
                $remote=trim((string)($input['remote_id']??''));
                if (!preg_match('/^[a-zA-Z0-9_-]{1,190}$/D',$remote)) throw new UserError('Ange det publicerade inläggets ID hos plattformen.');
                $app->query("UPDATE publications SET status='published',remote_id=?,last_error=?,updated_at=? WHERE id=? AND status='uncertain'",[$remote,'Manuellt bekräftat publicerat av administratören.',$app->now(),$id]);
            } elseif (($input['decision']??'')==='failed') $app->query("UPDATE publications SET status='failed',last_error=?,updated_at=? WHERE id=? AND status='uncertain'",['Manuellt kontrollerat: inget inlägg publicerades. Skapa en kopia om du vill försöka igen.',$app->now(),$id]);
            else throw new UserError('Välj hur publiceringen ska stämmas av.');
            jsonResponse(['ok'=>true]);
        default: jsonResponse(['error'=>'Åtgärden finns inte.'],404);
    }
} catch (JsonException) { jsonResponse(['error'=>'Begäran innehåller ogiltig JSON.'],400); }
catch (UserError|RemoteFailure $e) { jsonResponse(['error'=>$e->getMessage()],422); }
catch (Throwable $e) { error_log('Publicera: '.get_class($e).' '.$e->getMessage()); jsonResponse(['error'=>'Servern kunde inte slutföra åtgärden. Kontrollera installationen och PHP-loggen.'],500); }
