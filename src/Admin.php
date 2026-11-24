<?php
declare(strict_types=1);

/** Private CLI interface. SSH account access is the authentication boundary. */
final class Admin
{
    public function __construct(private App $app) {}

    public function dispatch(array $request, $input): array
    {
        if (PHP_SAPI !== 'cli') throw new UserError('Administrationskommandot kräver SSH/terminal.');
        $actor = $this->positiveId($request['actor_id'] ?? 1);
        if (!$this->app->one('SELECT id FROM users WHERE id=?', [$actor])) throw new UserError('Administratören finns inte.');
        if (($this->app->one('SELECT setting_value FROM settings WHERE setting_key=?', ['policies_user_'.$actor])['setting_value'] ?? null) !== Policies::VERSION) throw new UserError('Administratören behöver först godkänna aktuell policy i Socialen.');
        $action = $request['action'] ?? '';
        switch ($action) {
            case 'health':
                return ['url'=>$this->app->config['url'], 'revision'=>is_file($this->app->root.'/REVISION') ? trim((string)file_get_contents($this->app->root.'/REVISION')) : null,
                    'schema'=>$this->app->one("SELECT setting_value FROM settings WHERE setting_key='schema_version'")['setting_value'] ?? null,
                    'last_worker'=>$this->app->one("SELECT setting_value FROM settings WHERE setting_key='last_worker'")['setting_value'] ?? null,
                    'posts'=>(int)$this->app->one('SELECT COUNT(*) AS n FROM posts')['n'], 'media'=>(int)$this->app->one('SELECT COUNT(*) AS n FROM media')['n']];
            case 'channels': return ['channels'=>$this->app->channels()];
            case 'media': return ['media'=>$this->app->mediaList(), 'limit'=>500];
            case 'show': return ['post'=>$this->post($this->positiveId($request['id'] ?? 0))];
            case 'posts':
                $limit = $this->positiveId($request['limit'] ?? 100);
                if ($limit > 500) throw new UserError('Välj högst 500 poster.');
                $where=[]; $args=[];
                if (isset($request['status'])) {
                    if (!in_array($request['status'], ['draft','scheduled','cancelled'], true)) throw new UserError('Ogiltig poststatus.');
                    $where[]='p.status=?'; $args[]=$request['status'];
                }
                if (isset($request['network'])) {
                    if (!in_array($request['network'], ['facebook','instagram','youtube'], true)) throw new UserError('Ogiltig kanal.');
                    $where[]='EXISTS (SELECT 1 FROM publications x JOIN channels c ON c.id=x.channel_id WHERE x.post_id=p.id AND c.network=?)'; $args[]=$request['network'];
                }
                if (isset($request['before_id'])) { $where[]='p.id<?'; $args[]=$this->positiveId($request['before_id']); }
                $rows=$this->app->all('SELECT p.id FROM posts p'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY p.id DESC LIMIT '.($limit+1),$args);
                $more=count($rows)>$limit; $rows=array_slice($rows,0,$limit);
                return ['posts'=>array_map(fn($r)=>$this->post((int)$r['id']),$rows), 'has_more'=>$more, 'next_before_id'=>$more?(int)end($rows)['id']:null];
        }
        if (!in_array($action,['save','reschedule','draft','upload'],true)) throw new UserError('Kommandot finns inte.');
        $lock=fopen($this->app->root.'/storage/admin.lock','c');
        if (!$lock) throw new RuntimeException('Cannot open admin lock');
        chmod($this->app->root.'/storage/admin.lock',0600);
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); throw new UserError('Ett annat administrationskommando körs. Försök senare.'); }
        try {
            if ($action==='upload') return $this->upload($request,$input);
            $apply=($request['apply'] ?? false)===true;
            $payload=$request['post'] ?? [];
            if (!is_array($payload) || array_is_list($payload) && $payload!==[]) throw new UserError('Posten behöver vara ett JSON-objekt.');
            if ($action==='save') {
                $allowed=['id','version','title','caption','media_ids','channel_ids','status','scheduled_at','timezone','visibility','made_for_kids'];
                foreach (array_keys($payload) as $key) if (!in_array($key,$allowed,true)) throw new UserError('Okänt postfält: '.$key);
                if (!in_array($payload['status']??'draft',['draft','scheduled'],true)) throw new UserError('Välj draft eller scheduled.');
            } else {
                $id=$this->positiveId($request['id']??0);
                $old=$this->post($id);
                $payload=$this->editable($old);
                $payload['version']=$this->positiveId($request['version']??0);
                if ($action==='draft') $payload['status']='draft';
                else {
                    if ($old['status']!=='scheduled') throw new UserError('Reschedule kräver en schemalagd post.');
                    $payload['scheduled_at']=$request['scheduled_at']??'';
                    $payload['timezone']=$request['timezone']??$old['timezone'];
                }
            }
            $fingerprint=hash('sha256',$this->canonical(['actor'=>$actor,'action'=>$action,'request'=>$request['post']??array_intersect_key($request,array_flip(['id','version','scheduled_at','timezone']))]));
            $requestId=$request['request_id']??'';
            if (!is_string($requestId) || !preg_match('/^[A-Za-z0-9_.:-]{8,160}$/D',$requestId)) throw new UserError('Ange ett stabilt request-id på 8–160 tecken.');
            $receiptKey='cli-save:'.hash('sha256',$requestId);
            $receipt=$this->receipt($receiptKey);
            if ($receipt) {
                if (($receipt['fingerprint']??null)!==$fingerprint) throw new UserError('Request-id används redan för en annan ändring.');
                return ['applied'=>$apply,'replayed'=>true,'post'=>$this->post((int)$receipt['post_id'])];
            }
            if (!$apply) {
                $this->app->savePost($payload,true);
                return ['applied'=>false,'validated'=>true,'request_id'=>$requestId,'post'=>$payload];
            }
            $id=$this->app->savePost($payload,false,function(int $id)use($receiptKey,$fingerprint):void {
                $this->putReceipt($receiptKey,['fingerprint'=>$fingerprint,'post_id'=>$id]);
            });
            return ['applied'=>true,'replayed'=>false,'post'=>$this->post($id)];
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    private function upload(array $request, $input): array
    {
        $name=$request['name']??''; $bytes=$request['bytes']??0; $sha=$request['sha256']??'';
        if (!is_string($name) || $name==='' || basename(str_replace('\\','/',$name))!==$name || str_contains($name,"\0")) throw new UserError('Ogiltigt filnamn.');
        if (!is_int($bytes) || $bytes<1 || $bytes>(int)$this->app->config['upload_limit_mb']*1048576) throw new UserError('Filen är tom eller över Socialens filgräns.');
        if (!is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D',$sha)) throw new UserError('Ogiltig SHA256.');
        if (($request['apply']??false)!==true) return ['applied'=>false,'validation'=>'metadata_only','name'=>$name,'bytes'=>$bytes,'sha256'=>$sha,'upload_limit_mb'=>$this->app->config['upload_limit_mb']];
        $folder=$this->app->root.'/storage/cli-staging';
        if (!is_dir($folder) && !mkdir($folder,0700,true) && !is_dir($folder)) throw new RuntimeException('Cannot create staging directory');
        chmod($folder,0700);
        $temp=tempnam($folder,'upload-');
        if ($temp===false) throw new RuntimeException('Cannot stage upload');
        chmod($temp,0600); $stream=null; $media=null;
        try {
            $stream=fopen($temp,'wb'); if (!$stream) throw new RuntimeException('Cannot write staging file');
            $remaining=$bytes; $hash=hash_init('sha256');
            while ($remaining>0) {
                $chunk=fread($input,min(1048576,$remaining));
                if ($chunk===false || $chunk==='') throw new UserError('Överföringen avbröts innan hela filen kom fram.');
                hash_update($hash,$chunk); $remaining-=strlen($chunk);
                $offset=0; while ($offset<strlen($chunk)) { $n=fwrite($stream,substr($chunk,$offset)); if (!$n) throw new RuntimeException('Staging write failed'); $offset+=$n; }
            }
            if (fread($input,1)!=='') throw new UserError('Överföringen innehåller fler byte än angivet.');
            fclose($stream); $stream=null;
            if (!hash_equals($sha,hash_final($hash))) throw new UserError('Filens SHA256 stämmer inte efter överföringen.');
            $key='cli-upload:'.$sha; $receipt=$this->receipt($key);
            if ($receipt) return ['applied'=>true,'deduplicated'=>true,'source_sha256'=>$sha,'media'=>$this->safeMedia($this->app->getMedia((int)$receipt['media_id']))];
            foreach ($this->app->all('SELECT * FROM media WHERE bytes=?',[$bytes]) as $candidate) {
                $existing=$this->app->root.'/storage/uploads/'.$candidate['path'];
                if (is_file($existing) && hash_equals($sha,hash_file('sha256',$existing))) {
                    $this->putReceipt($key,['media_id'=>(int)$candidate['id']]);
                    return ['applied'=>true,'deduplicated'=>true,'source_sha256'=>$sha,'media'=>$this->safeMedia($candidate)];
                }
            }
            $this->app->db->beginTransaction();
            try {
                $media=$this->app->importMedia($temp,$name);
                $this->putReceipt($key,['media_id'=>(int)$media['id']]);
                $this->app->db->commit();
            } catch (Throwable $e) {
                $this->app->db->rollBack();
                if ($media) @unlink($this->app->root.'/storage/uploads/'.$media['path']);
                throw $e;
            }
            return ['applied'=>true,'deduplicated'=>false,'source_sha256'=>$sha,'media'=>$this->safeMedia($media)];
        } finally { if (is_resource($stream)) fclose($stream); if (is_file($temp)) unlink($temp); }
    }
    private function safeMedia(array $m): array { return array_intersect_key($m,array_flip(['id','name','mime','bytes','width','height','created_at'])); }
    private function post(int $id): array
    {
        $p=$this->app->one('SELECT * FROM posts WHERE id=?',[$id]) ?? throw new UserError('Inlägget finns inte.');
        $p['media_ids']=json_decode($p['media_json'],true,flags:JSON_THROW_ON_ERROR); unset($p['media_json']);
        $p['publications']=$this->app->all('SELECT p.id,p.channel_id,p.status,p.remote_id,p.remote_url,p.last_error,p.next_at,p.updated_at,c.network,c.name AS channel_name FROM publications p JOIN channels c ON c.id=p.channel_id WHERE p.post_id=? ORDER BY p.id',[$id]);
        $p['scheduled_local']=$p['scheduled_at'] ? (new DateTimeImmutable($p['scheduled_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($p['timezone']))->format('Y-m-d\TH:i') : null;
        return $p;
    }
    private function editable(array $p): array
    {
        return ['id'=>(int)$p['id'],'version'=>(int)$p['version'],'title'=>$p['title'],'caption'=>$p['caption'],'media_ids'=>$p['media_ids'],
            'channel_ids'=>array_map(fn($x)=>(int)$x['channel_id'],$p['publications']),'status'=>$p['status'],'scheduled_at'=>$p['scheduled_local'],
            'timezone'=>$p['timezone'],'visibility'=>$p['visibility'],'made_for_kids'=>$p['made_for_kids']===null?'':((int)$p['made_for_kids']===1?'yes':'no')];
    }
    private function positiveId(mixed $v): int { if (filter_var($v,FILTER_VALIDATE_INT)===false || (int)$v<1) throw new UserError('Ange ett positivt heltals-ID/version.'); return (int)$v; }
    private function receipt(string $key): ?array { $r=$this->app->one('SELECT setting_value FROM settings WHERE setting_key=?',[$key]); return $r?json_decode($r['setting_value'],true,flags:JSON_THROW_ON_ERROR):null; }
    private function putReceipt(string $key,array $receipt): void { $this->app->query('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)',[$key,json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]); }
    private function canonical(array $value): string { $sort=function(&$v)use(&$sort):void { if (!is_array($v)) return; if (!array_is_list($v)) ksort($v); foreach ($v as &$x) $sort($x); }; $sort($value); return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
}
