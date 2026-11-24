<?php
declare(strict_types=1);

final class UserError extends RuntimeException {}

final class App
{
    public readonly PDO $db;
    public readonly string $key;
    public function __construct(public readonly string $root, public readonly array $config, public readonly bool $demo = false)
    {
        $key = base64_decode($config['key'] ?? '', true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('Appnyckeln måste vara 32 slumpbyte i base64. Kör php bin/key.php.');
        $this->key = $key;
        new DateTimeZone($config['timezone'] ?? 'Europe/Stockholm');
        $url = parse_url($config['url'] ?? '');
        if (!$url || empty($url['host']) || !in_array($url['scheme'] ?? '', ['https', 'http'], true) || isset($url['query']) || isset($url['fragment']) || isset($url['user']) || isset($url['pass'])) throw new RuntimeException('Appens URL är ogiltig.');
        if (!$demo && ($url['scheme'] !== 'https')) throw new RuntimeException('Produktionsadressen måste använda HTTPS.');
        $db = $config['database'];
        $this->db = new PDO($db['dsn'], $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        if ($this->sqlite()) $this->db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
        if ($demo) { $this->migrate(); $this->seed(); }
    }
    public function sqlite(): bool { return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'; }
    public function now(): string { return gmdate('Y-m-d H:i:s'); }
    public function url(string $path = ''): string { return rtrim($this->config['url'], '/') . '/' . ltrim($path, '/'); }
    public function query(string $sql, array $params = []): PDOStatement { $s = $this->db->prepare($sql); $s->execute($params); return $s; }
    public function one(string $sql, array $params = []): ?array { return $this->query($sql, $params)->fetch() ?: null; }
    public function all(string $sql, array $params = []): array { return $this->query($sql, $params)->fetchAll(); }
    public function seal(array $value): string { $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); return base64_encode($nonce . sodium_crypto_secretbox(json_encode($value, JSON_THROW_ON_ERROR), $nonce, $this->key)); }
    public function unseal(string $value): array {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 40) throw new RuntimeException('Ogiltig krypterad kanalanslutning.');
        $plain = sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), $this->key);
        if ($plain === false) throw new RuntimeException('Kanalens uppgifter kan inte dekrypteras. Kontrollera appnyckeln.');
        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }
    public function session(): void {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name('publicera_session');
        session_start(['use_strict_mode' => 1, 'cookie_httponly' => true, 'cookie_secure' => !$this->demo, 'cookie_samesite' => 'Lax', 'cookie_path' => parse_url($this->url(), PHP_URL_PATH) ?: '/']);
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
    public function user(): ?array {
        $this->session();
        if ($this->demo) return ['id' => 1, 'email' => 'demo@publicera.local', 'name' => 'Stefan'];
        if (empty($_SESSION['user_id']) || (int)($_SESSION['last_active'] ?? 0) < time() - 43200) return null;
        $_SESSION['last_active'] = time();
        return $this->one('SELECT id,email,name FROM users WHERE id=?', [$_SESSION['user_id']]);
    }
    public function requireUser(): array { return $this->user() ?? throw new UserError('Logga in för att fortsätta.'); }
    public function csrf(): void { $this->session(); if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) throw new UserError('Sessionen har ändrats. Ladda om sidan och försök igen.'); }
    public function login(string $email, string $password): void {
        $this->session();
        $key = hash_hmac('sha256', strtolower(trim($email)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''), $this->key);
        $row = $this->one('SELECT * FROM login_attempts WHERE attempt_key=?', [$key]);
        if ($row && (int)$row['attempts'] >= 8 && $row['last_at'] > gmdate('Y-m-d H:i:s', time() - 900)) throw new UserError('För många inloggningsförsök. Försök igen om 15 minuter.');
        $user = $this->one('SELECT * FROM users WHERE email=?', [strtolower(trim($email))]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $count = $row && $row['last_at'] > gmdate('Y-m-d H:i:s', time() - 900) ? (int)$row['attempts'] + 1 : 1;
            if ($row) $this->query('UPDATE login_attempts SET attempts=?,last_at=? WHERE attempt_key=?', [$count, $this->now(), $key]);
            else $this->query('INSERT INTO login_attempts(attempt_key,attempts,last_at) VALUES(?,?,?)', [$key, $count, $this->now()]);
            throw new UserError('E-postadressen eller lösenordet stämmer inte.');
        }
        $this->query('DELETE FROM login_attempts WHERE attempt_key=? OR last_at<?', [$key, gmdate('Y-m-d H:i:s', time() - 86400)]);
        session_regenerate_id(true); $_SESSION['user_id'] = (int)$user['id']; $_SESSION['last_active'] = time(); $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    public function hasAcceptedPolicies(): bool {
        if ($this->demo) return true;
        $user=$this->user();
        return $user && ($this->one('SELECT setting_value FROM settings WHERE setting_key=?',['policies_user_'.$user['id']])['setting_value']??null)===Policies::VERSION;
    }
    public function acceptPolicies(string $version): void {
        $user=$this->requireUser();
        if ($version!==Policies::VERSION) throw new UserError('Policyn har uppdaterats. Ladda om sidan och läs den aktuella versionen.');
        $key='policies_user_'.$user['id'];
        if($this->one('SELECT setting_key FROM settings WHERE setting_key=?',[$key]))$this->query('UPDATE settings SET setting_value=? WHERE setting_key=?',[$version,$key]);
        else $this->query('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)',[$key,$version]);
    }
    public function requirePolicies(): void {
        if(!$this->hasAcceptedPolicies())throw new UserError('Läs och godkänn integritetspolicyn och användarvillkoren innan du fortsätter.');
    }
    public function migrate(): void {
        $id = $this->sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT';
        $text = $this->sqlite() ? 'TEXT' : 'MEDIUMTEXT';
        $suffix = $this->sqlite() ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $schemas = [
            "users (id $id,email VARCHAR(190) NOT NULL UNIQUE,name VARCHAR(190) NOT NULL,password_hash VARCHAR(255) NOT NULL,created_at VARCHAR(19) NOT NULL)",
            "channels (id $id,network VARCHAR(20) NOT NULL,external_id VARCHAR(190) NOT NULL,name VARCHAR(190) NOT NULL,credentials TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,connected_at VARCHAR(19) NOT NULL,UNIQUE(network,external_id))",
            "media (id $id,name VARCHAR(255) NOT NULL,path VARCHAR(255) NOT NULL,mime VARCHAR(100) NOT NULL,bytes BIGINT NOT NULL,width INTEGER NULL,height INTEGER NULL,public_key VARCHAR(64) NOT NULL UNIQUE,created_at VARCHAR(19) NOT NULL)",
            "posts (id $id,title VARCHAR(190) NOT NULL,caption $text NOT NULL,media_json TEXT NOT NULL,scheduled_at VARCHAR(19) NULL,timezone VARCHAR(80) NOT NULL,status VARCHAR(20) NOT NULL,visibility VARCHAR(20) NOT NULL,made_for_kids INTEGER NULL,version INTEGER NOT NULL DEFAULT 1,created_at VARCHAR(19) NOT NULL,updated_at VARCHAR(19) NOT NULL)",
            "publications (id $id,post_id BIGINT NOT NULL,channel_id BIGINT NOT NULL,status VARCHAR(20) NOT NULL,state_json TEXT NOT NULL,remote_id VARCHAR(190) NULL,remote_url TEXT NULL,last_error TEXT NULL,next_at VARCHAR(19) NOT NULL,updated_at VARCHAR(19) NOT NULL,UNIQUE(post_id,channel_id))",
            'login_attempts (attempt_key VARCHAR(64) PRIMARY KEY,attempts INTEGER NOT NULL,last_at VARCHAR(19) NOT NULL)',
            'settings (setting_key VARCHAR(80) PRIMARY KEY,setting_value TEXT NOT NULL)',
        ];
        foreach ($schemas as $schema) $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $schema . $suffix);
        if (!$this->one("SELECT setting_key FROM settings WHERE setting_key='schema_version'")) {
            $this->db->exec('CREATE INDEX publications_due ON publications(status,next_at)');
            $this->db->exec('CREATE INDEX posts_scheduled ON posts(status,scheduled_at)');
            $this->query('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)', ['schema_version', '1']);
        }
    }
    public function channels(): array { return $this->all('SELECT id,network,external_id,name,active,connected_at FROM channels ORDER BY network,name'); }
    public function posts(): array {
        $posts = $this->all('SELECT * FROM posts ORDER BY COALESCE(scheduled_at,created_at) DESC LIMIT 500');
        $ids=array_column($posts,'id');
        $pubs = $ids ? $this->all('SELECT p.*,c.network,c.name AS channel_name FROM publications p JOIN channels c ON c.id=p.channel_id WHERE p.post_id IN (' . implode(',',array_fill(0,count($ids),'?')) . ') ORDER BY p.id',$ids) : [];
        $byPost = [];
        foreach ($pubs as $pub) { $pub['started']=$pub['state_json']!=='{}'; unset($pub['state_json']); $byPost[$pub['post_id']][] = $pub; }
        foreach ($posts as &$post) { $post['media_ids'] = json_decode($post['media_json'], true, 512, JSON_THROW_ON_ERROR); unset($post['media_json']); $post['publications'] = $byPost[$post['id']] ?? []; }
        return $posts;
    }
    public function getMedia(int $id): array { return $this->one('SELECT * FROM media WHERE id=?', [$id]) ?? throw new UserError('Mediefilen finns inte.'); }
    public function mediaUrl(array $media): string { return $this->url('media.php?id=' . $media['id'] . '&key=' . $media['public_key']); }
    public function mediaList(): array { $rows = $this->all('SELECT id,name,mime,bytes,width,height,created_at FROM media ORDER BY id DESC LIMIT 500'); foreach ($rows as &$row) $row['url'] = 'media.php?id=' . $row['id']; return $rows; }
    public function upload(array $file): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new UserError('Uppladdningen misslyckades. Kontrollera filstorleken och serverns upload_max_filesize/post_max_size.');
        return $this->storeMedia((string)$file['tmp_name'], (string)$file['name'], false);
    }
    public function importMedia(string $source, string $name): array {
        $stage = realpath($this->root . '/storage/cli-staging');
        $real = realpath($source);
        if (PHP_SAPI !== 'cli' || !$stage || !$real || !str_starts_with($real, $stage . DIRECTORY_SEPARATOR) || is_link($source)) throw new UserError('Terminalimport kräver en privat mellanlagrad fil.');
        return $this->storeMedia($real, $name, true);
    }
    private function storeMedia(string $sourceFile, string $originalName, bool $fromCli): array {
        $size = filesize($sourceFile);
        if (!$size || $size > (int)$this->config['upload_limit_mb'] * 1048576) throw new UserError('Filen är för stor. Appens gräns är ' . $this->config['upload_limit_mb'] . ' MB.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($sourceFile);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'video/mp4'], true)) throw new UserError('Välj en JPEG-, PNG-, WebP-bild eller en MP4-video.');
        $folder = $this->root . '/storage/uploads';
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) throw new RuntimeException('Uppladdningsmappen kunde inte skapas.');
        $name = basename(str_replace('\\', '/', $originalName));
        if (mb_strlen($name) > 240) $name = mb_substr($name, 0, 230);
        $path = bin2hex(random_bytes(20)) . ($mime === 'video/mp4' ? '.mp4' : '.jpg');
        $width = $height = null;
        if (str_starts_with($mime, 'image/')) {
            $dimensions = getimagesize($sourceFile);
            if (!$dimensions || $dimensions[0] * $dimensions[1] > 24000000) throw new UserError('Bilden får innehålla högst 24 megapixlar.');
            $limit=ini_get('memory_limit'); $unit=strtolower(substr($limit,-1));
            $memoryLimit=(int)$limit*match($unit){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};
            $estimated=$dimensions[0]*$dimensions[1]*12 + min($dimensions[0],2048)*min($dimensions[1],2048)*5 + $size*2 + 8388608;
            if ($memoryLimit>0 && memory_get_usage(true)+$estimated>$memoryLimit) throw new UserError('Bilden kräver mer arbetsminne än webbhotellet tillåter. Minska bildens pixelmått och ladda upp igen.');
            if (!extension_loaded('gd')) throw new UserError('PHP-tillägget GD behöver aktiveras för bilder.');
            $source = imagecreatefromstring(file_get_contents($sourceFile));
            if (!$source) throw new UserError('Bilden kunde inte läsas.');
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data($sourceFile); $orientation = (int)($exif['Orientation'] ?? 1);
                if (in_array($orientation, [2,4,5,7], true)) imageflip($source, in_array($orientation, [2,5,7], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
                $angle = match ($orientation) { 3 => 180, 5,6 => -90, 7,8 => 90, default => 0 };
                if ($angle) { $rotated = imagerotate($source, $angle, 0); imagedestroy($source); $source = $rotated; }
            }
            $scale = min(1, 2048 / max(imagesx($source), imagesy($source)));
            $width = max(1, (int)round(imagesx($source) * $scale)); $height = max(1, (int)round(imagesy($source) * $scale));
            $output = imagecreatetruecolor($width, $height); imagefill($output, 0, 0, imagecolorallocate($output, 255,255,255)); imagecopyresampled($output, $source, 0,0,0,0,$width,$height,imagesx($source),imagesy($source));
            $saved = imagejpeg($output, $folder . '/' . $path, 90); imagedestroy($source); imagedestroy($output);
            if (!$saved) throw new RuntimeException('Bilden kunde inte sparas.'); $mime = 'image/jpeg';
        } elseif (!($fromCli ? rename($sourceFile, $folder . '/' . $path) : move_uploaded_file($sourceFile, $folder . '/' . $path))) throw new RuntimeException('Videon kunde inte sparas.');
        chmod($folder . '/' . $path, 0600);
        try { $this->query('INSERT INTO media(name,path,mime,bytes,width,height,public_key,created_at) VALUES(?,?,?,?,?,?,?,?)', [$name, $path, $mime, filesize($folder . '/' . $path), $width, $height, bin2hex(random_bytes(32)), $this->now()]); }
        catch (Throwable $e) { unlink($folder . '/' . $path); throw $e; }
        return $this->getMedia((int)$this->db->lastInsertId());
    }
    public function savePost(array $input, bool $preview = false, ?callable $onSaved = null): int {
        $title = trim((string)($input['title'] ?? '')); $caption = trim((string)($input['caption'] ?? ''));
        if ($title === '' || mb_strlen($title) > 100) throw new UserError('Ge inlägget en rubrik på högst 100 tecken.');
        if (mb_strlen($caption) > 60000) throw new UserError('Texten är för lång.');
        $mediaIds = $this->ids($input['media_ids'] ?? []); if (count($mediaIds) > 10) throw new UserError('Välj högst 10 mediefiler.');
        $assets = array_map(fn($id) => $this->getMedia($id), $mediaIds);
        $channelIds = $this->ids($input['channel_ids'] ?? []);
        $channels = []; foreach ($channelIds as $channelId) $channels[] = $this->one('SELECT * FROM channels WHERE id=? AND active=1', [$channelId]) ?? throw new UserError('En vald kanal är inte ansluten längre.');
        $status = ($input['status'] ?? '') === 'scheduled' ? 'scheduled' : 'draft';
        $visibility = in_array($input['visibility'] ?? '', ['public', 'unlisted', 'private'], true) ? $input['visibility'] : 'private';
        $madeForKids = match ($input['made_for_kids'] ?? '') { 'yes' => 1, 'no' => 0, default => null };
        $timezone = $input['timezone'] ?? $this->config['timezone'];
        try { $tz = new DateTimeZone($timezone); } catch (Throwable) { throw new UserError('Välj en giltig tidszon.'); }
        $scheduled = null;
        if (!empty($input['scheduled_at'])) {
            $source = (string)$input['scheduled_at'];
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $source, $tz);
            if (!$date || $date->format('Y-m-d\TH:i') !== $source) throw new UserError('Datumet eller tiden är ogiltig. Välj en tid som finns i din tidszon.');
            $naive=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$source,new DateTimeZone('UTC'))->getTimestamp();
            $offsets=array_unique(array_column($tz->getTransitions($naive-86400,$naive+86400)?:[],'offset'));
            $matches=0; foreach ($offsets as $offset) if ((new DateTimeImmutable('@'.($naive-$offset)))->setTimezone($tz)->format('Y-m-d\TH:i')===$source) ++$matches;
            if ($matches>1) throw new UserError('Tiden förekommer två gånger vid övergången till vintertid. Välj en tid utanför den upprepade timmen.');
            $scheduled = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if ($status === 'scheduled') {
            if (!$channels) throw new UserError('Välj minst en ansluten kanal.');
            if (!$scheduled || $scheduled < gmdate('Y-m-d H:i:s', time() - 60)) throw new UserError('Välj en publiceringstid från och med nu.');
            foreach ($channels as $channel) $this->validatePublication($channel['network'], $caption, $assets);
            if ($madeForKids===null && array_filter($channels,fn($c)=>$c['network']==='youtube')) throw new UserError('Ange om YouTube-videon är avsedd för barn.');
        }
        $id = (int)($input['id'] ?? 0);
        $this->db->beginTransaction();
        try {
            if ($id) {
                $old = $this->one('SELECT * FROM posts WHERE id=?' . ($this->sqlite() ? '' : ' FOR UPDATE'), [$id]);
                if (!$old) throw new UserError('Inlägget finns inte.');
                if ((int)($input['version'] ?? 0) !== (int)$old['version']) throw new UserError('Inlägget har ändrats i en annan vy. Ladda om och försök igen.');
                if ($this->one("SELECT id FROM publications WHERE post_id=? AND (status NOT IN ('pending','cancelled') OR state_json<>'{}') LIMIT 1", [$id])) throw new UserError('Publiceringen har påbörjats. Skapa en kopia för att göra ett nytt inlägg.');
                if ($preview) { $this->db->rollBack(); return $id; }
                $this->query('UPDATE posts SET title=?,caption=?,media_json=?,scheduled_at=?,timezone=?,status=?,visibility=?,made_for_kids=?,version=version+1,updated_at=? WHERE id=?', [$title,$caption,json_encode($mediaIds),$scheduled,$timezone,$status,$visibility,$madeForKids,$this->now(),$id]);
                $this->query('DELETE FROM publications WHERE post_id=?', [$id]);
            } else {
                if ($preview) { $this->db->rollBack(); return 0; }
                $this->query('INSERT INTO posts(title,caption,media_json,scheduled_at,timezone,status,visibility,made_for_kids,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)', [$title,$caption,json_encode($mediaIds),$scheduled,$timezone,$status,$visibility,$madeForKids,$this->now(),$this->now()]); $id = (int)$this->db->lastInsertId();
            }
            foreach ($channels as $channel) $this->query('INSERT INTO publications(post_id,channel_id,status,state_json,next_at,updated_at) VALUES(?,?,?,?,?,?)', [$id,$channel['id'],'pending','{}',$scheduled ?? $this->now(),$this->now()]);
            if ($onSaved) $onSaved($id);
            $this->db->commit(); return $id;
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    private function ids(mixed $value): array {
        if (!is_array($value) || count($value) > 50) throw new UserError('Ogiltigt val.');
        $ids = []; foreach ($value as $id) { if (filter_var($id, FILTER_VALIDATE_INT) === false || (int)$id < 1) throw new UserError('Ogiltigt val.'); $ids[] = (int)$id; } return array_values(array_unique($ids));
    }
    public function validatePublication(string $network, string $caption, array $assets): void {
        $videos = array_values(array_filter($assets, fn($m) => $m['mime'] === 'video/mp4'));
        if ($network === 'youtube' && (count($assets) !== 1 || count($videos) !== 1 || mb_strlen($caption) > 5000)) throw new UserError('YouTube behöver en MP4-video och en beskrivning på högst 5 000 tecken.');
        if ($network === 'instagram') {
            if (!$assets || mb_strlen($caption) > 2200 || (count($assets) > 1 && $videos)) throw new UserError('Instagram behöver en bild, en MP4-video eller upp till 10 bilder. Texten får vara högst 2 200 tecken.');
            foreach ($assets as $media) if ($media['mime'] !== 'video/mp4' && ($media['width'] / $media['height'] < 0.8 || $media['width'] / $media['height'] > 1.91)) throw new UserError('Instagram-bilder behöver proportioner mellan 4:5 och 1,91:1. Beskär bilden före uppladdning.');
        }
        if ($network === 'facebook' && (count($assets) > 1 || (!$assets && $caption === ''))) throw new UserError('Facebook stöder text, en bild eller en MP4-video i den här versionen.');
    }
    public function cancelPost(int $id): void {
        $this->db->beginTransaction();
        try {
            $post = $this->one('SELECT * FROM posts WHERE id=?' . ($this->sqlite() ? '' : ' FOR UPDATE'), [$id]);
            if (!$post) throw new UserError('Inlägget finns inte.');
            if ($this->one("SELECT id FROM publications WHERE post_id=? AND status='processing'", [$id])) throw new UserError('Ett publiceringsjobb körs. Vänta och försök igen.');
            $this->query("UPDATE publications SET status='cancelled',updated_at=? WHERE post_id=? AND status IN ('pending','failed')", [$this->now(),$id]);
            $this->query("UPDATE posts SET status='cancelled',version=version+1,updated_at=? WHERE id=?", [$this->now(),$id]); $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    public function seed(): void {
        if ($this->one('SELECT id FROM posts LIMIT 1')) return;
        foreach (['instagram' => 'Styverz', 'facebook' => 'Styverz · Facebook', 'youtube' => 'Styverz · YouTube'] as $network => $name) $this->query('INSERT INTO channels(network,external_id,name,credentials,connected_at) VALUES(?,?,?,?,?)', [$network, 'demo-' . $network, $name, $this->seal(['demo' => true]), $this->now()]);
        $titles = ['En ny vecka, nya idéer', 'Bakom kulisserna', 'Det här vill vi dela med er', 'Veckans höjdpunkt', 'En idé till nästa vecka'];
        $captions = ['Små ögonblick som förtjänar lite mer plats. Vad ser du fram emot den här veckan?', 'Det börjar med en idé. Här är en liten glimt av det vi arbetar med just nu.', 'Ibland är det de enkla sakerna som gör störst skillnad. ✨', 'Tack till alla er som följer med. Här kommer ett ögonblick från veckan.', 'Samla bilder, skriv ner tankarna och hitta den rätta stunden.'];
        $monday = (new DateTimeImmutable('monday this week', new DateTimeZone($this->config['timezone'])))->setTime(10,0);
        foreach ($titles as $i => $title) {
            $date = $monday->modify('+' . ($i === 4 ? 8 : $i) . ' days')->setTime($i === 1 ? 16 : 10, $i === 2 ? 30 : 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $this->query('INSERT INTO posts(title,caption,media_json,scheduled_at,timezone,status,visibility,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)', [$title,$captions[$i],'[]',$date,$this->config['timezone'],$i === 4 ? 'draft' : 'scheduled','private',$this->now(),$this->now()]); $id = (int)$this->db->lastInsertId();
            foreach ($i === 1 ? [1,3] : [1,2] as $channel) $this->query('INSERT INTO publications(post_id,channel_id,status,state_json,next_at,updated_at) VALUES(?,?,?,?,?,?)', [$id,$channel,'pending','{}',$date,$this->now()]);
        }
    }
}
