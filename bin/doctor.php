<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/bootstrap.php';
$errors=[];
echo 'PHP ',PHP_VERSION,PHP_EOL;
if(version_compare(PHP_VERSION,'8.3.0','<'))$errors[]='PHP 8.3 eller senare behövs.';
foreach(['pdo_mysql','sodium','curl','mbstring','fileinfo','gd']as$ext){echo $ext,': ',extension_loaded($ext)?'OK':'SAKNAS',PHP_EOL;if(!extension_loaded($ext))$errors[]='Aktivera '.$ext.'.';}
try{$app=app();$app->query('SELECT 1');echo "Databas: OK\n";echo 'Appadress: ',$app->config['url'],PHP_EOL;echo 'Lagring: ',is_writable($app->root.'/storage')?'skrivbar':'INTE SKRIVBAR',PHP_EOL;if(!is_writable($app->root.'/storage'))$errors[]='Gör storage skrivbar för kontot.';echo 'Schema: ',$app->one("SELECT setting_value FROM settings WHERE setting_key='schema_version'")['setting_value']??'saknas',PHP_EOL;echo 'Senaste cron: ',$app->one("SELECT setting_value FROM settings WHERE setting_key='last_worker'")['setting_value']??'har inte körts',PHP_EOL;echo 'Google-app: ',!empty($app->config['google']['client_id'])?'angiven':'saknas',PHP_EOL;echo 'Meta-app: ',!empty($app->config['meta']['app_id'])?'angiven':'saknas',PHP_EOL;}catch(Throwable){$errors[]='Konfiguration/databas kunde inte öppnas. Kontrollera config.php och kör bin/install.php.';}
echo 'PHP upload_max_filesize: ',ini_get('upload_max_filesize'),PHP_EOL,'PHP post_max_size: ',ini_get('post_max_size'),PHP_EOL,'PHP memory_limit: ',ini_get('memory_limit'),PHP_EOL;
foreach($errors as$error)fwrite(STDERR,$error.PHP_EOL);exit($errors?1:0);
