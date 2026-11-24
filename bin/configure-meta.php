<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
try {
    $values=[];
    for ($i=0;$i<3;$i++) {
        $line=fgets(STDIN,4096);
        if ($line===false || !str_ends_with($line,"\n")) throw new RuntimeException('Alla tre värden måste anges via skriptets inmatning.');
        $values[]=trim($line);
    }
    [$id,$secret,$login]=$values;
    if (!preg_match('/^[0-9]{5,40}$/D',$id) || !preg_match('/^[a-fA-F0-9]{32}$/D',$secret) || ($login!=='' && !preg_match('/^[0-9]{5,40}$/D',$login))) throw new RuntimeException('Ogiltigt app-ID, apphemlighet eller konfigurations-ID. Ingenting har ändrats.');
    $configPath=realpath(dirname(__DIR__).'/config.php');
    if ($configPath===false) throw new RuntimeException('Befintlig config.php saknas.');
    $original=file_get_contents($configPath);
    $config=require $configPath;
    if (!is_string($original) || !is_array($config)) throw new RuntimeException('Befintlig konfiguration kunde inte läsas.');
    $meta=array_merge($config['meta']??[],['app_id'=>$id,'app_secret'=>$secret,'login_config_id'=>$login]);
    $meta['version']??='v27.0';
    if (($config['meta']??[])===$meta) { echo "Meta-konfigurationen är redan sparad.\n"; exit; }
    $storage=dirname($configPath).'/storage';
    if (!is_dir($storage) || !is_writable($storage)) throw new RuntimeException('Privat storage-katalog saknas eller är inte skrivbar.');
    $backup=tempnam($storage,'meta-config-backup.');
    if ($backup===false || file_put_contents($backup,$original,LOCK_EX)!==strlen($original) || !chmod($backup,0600)) throw new RuntimeException('Säkerhetskopian kunde inte skapas. Ingenting har ändrats.');
    $config['meta']=$meta;
    $source="<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n";
    $temporary=tempnam(dirname($configPath),'.meta-config-');
    try {
        if ($temporary===false || file_put_contents($temporary,$source,LOCK_EX)!==strlen($source) || !chmod($temporary,0600) || !rename($temporary,$configPath)) throw new RuntimeException('Konfigurationen kunde inte sparas. Återställningskopian finns kvar.');
    } finally { if (is_string($temporary) && is_file($temporary)) unlink($temporary); }
    echo "Meta-konfigurationen är sparad. Google och databasen har behållits.\nSäkerhetskopia: ",$backup,"\n";
} catch (Throwable $error) {
    // Do not print configuration values or a stack trace containing input.
    fwrite(STDERR,$error instanceof RuntimeException?$error->getMessage()."\n":"Konfigurationen kunde inte uppdateras.\n");
    exit(1);
}
