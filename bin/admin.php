<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0');
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/Admin.php';
try {
    if (in_array('--help',$argv,true)) { echo "Socialen SSH admin: en JSON-rad på stdin, följd av filbyte endast för upload med apply=true. Se docs/terminal.md.\n"; exit; }
    $line=fgets(STDIN,262146);
    if ($line===false || !str_ends_with($line,"\n") || strlen($line)>262144) throw new UserError('Begäran saknas eller är för stor.');
    $request=json_decode($line,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($request) || array_is_list($request)) throw new UserError('Begäran behöver vara ett JSON-objekt.');
    $result=(new Admin(app()))->dispatch($request,STDIN);
    echo json_encode(['ok'=>true,'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
} catch (UserError|JsonException $e) {
    echo json_encode(['ok'=>false,'error'=>$e instanceof JsonException?'Ogiltig JSON.':$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL; exit(2);
} catch (Throwable $e) {
    error_log('Socialen admin: '.get_class($e));
    echo json_encode(['ok'=>false,'error'=>'Administrationskommandot misslyckades. Kontrollera serverns privata logg.']),PHP_EOL; exit(1);
}
