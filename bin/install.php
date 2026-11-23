<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/src/bootstrap.php';
try {
    $app=app();
    foreach (['pdo','sodium','curl','mbstring','fileinfo','gd'] as $extension) if (!extension_loaded($extension)) throw new RuntimeException('Aktivera PHP-tillägget '.$extension.'.');
    $app->migrate(); echo "Databastabellerna är skapade. Befintliga data har behållits.\n";
    if ($app->one('SELECT id FROM users LIMIT 1')) { echo "Administratör finns redan. Inga användaruppgifter ändrades.\n"; exit(0); }
    if (in_array('--stdin', $argv, true)) {
        $name=trim((string)fgets(STDIN));
        $email=strtolower(trim((string)fgets(STDIN)));
        $password=rtrim((string)fgets(STDIN), "\r\n");
        $repeat=rtrim((string)fgets(STDIN), "\r\n");
    } else {
        if (!function_exists('shell_exec')) throw new RuntimeException('Webbhotellet blockerar PHP:s terminalfunktion. Kör i stället: bash bin/install-admin.sh');
        $hidden=trim((string)shell_exec('stty -g 2>/dev/null'));
        if ($hidden==='') throw new RuntimeException('Kör i en interaktiv SSH-terminal: bash bin/install-admin.sh');
        fwrite(STDOUT,'Ditt namn: '); $name=trim((string)fgets(STDIN));
        fwrite(STDOUT,'E-post: '); $email=strtolower(trim((string)fgets(STDIN)));
        shell_exec('stty -echo');
        try { fwrite(STDOUT,'Lösenord (minst 12 tecken, visas inte): '); $password=rtrim((string)fgets(STDIN),"\r\n"); fwrite(STDOUT,"\nUpprepa lösenordet: "); $repeat=rtrim((string)fgets(STDIN),"\r\n"); }
        finally { shell_exec('stty '.escapeshellarg($hidden)); fwrite(STDOUT,"\n"); }
    }
    if ($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ange namn och giltig e-postadress.');
    if (mb_strlen($password)<12||$password!==$repeat) throw new RuntimeException('Lösenorden behöver matcha och ha minst 12 tecken.');
    $app->query('INSERT INTO users(email,name,password_hash,created_at) VALUES(?,?,?,?)',[$email,$name,password_hash($password,PASSWORD_DEFAULT),$app->now()]);
    echo "Klart. Administratören har skapats. Öppna appens HTTPS-adress.\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
