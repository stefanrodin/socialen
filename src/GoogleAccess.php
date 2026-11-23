<?php
declare(strict_types=1);

/** Owns Google authorization, revocation and the lifetime of locally cached API data. */
final class GoogleAccess
{
    public function __construct(private App $app, private Http $http = new Http()) {}
    private function setting(string $key): ?string { return $this->app->one('SELECT setting_value FROM settings WHERE setting_key=?',[$key])['setting_value']??null; }
    private function store(string $key,string $value): void {
        if ($this->setting($key)!==null) $this->app->query('UPDATE settings SET setting_value=? WHERE setting_key=?',[$value,$key]);
        else $this->app->query('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)',[$key,$value]);
    }
    public function credentials(array $channel): array {
        $creds=$this->app->unseal($channel['credentials']);
        if (empty($creds['refresh_token'])) throw new UserError('Anslut YouTube igen: förnyelsetoken saknas.');
        if (($creds['expires_at']??0)<time()+120) {
            try {
                $config=$this->app->config['google'];
                $token=$this->http->form('https://oauth2.googleapis.com/token',['client_id'=>$config['client_id'],'client_secret'=>$config['client_secret'],'refresh_token'=>$creds['refresh_token'],'grant_type'=>'refresh_token'])['json'];
            } catch (RemoteFailure $e) {
                if ($e->errorCode==='invalid_grant') { $this->purge((int)$channel['id']); throw new UserError('Google-åtkomsten har upphört eller återkallats. Kanalens lokala Google-data har raderats. Anslut kanalen igen.'); }
                throw $e;
            }
            if (empty($token['access_token'])) throw new UserError('Anslut YouTube igen: token kunde inte förnyas.');
            $creds=array_merge($creds,$token,['expires_at'=>time()+(int)($token['expires_in']??3600)]);
            $this->app->query('UPDATE channels SET credentials=? WHERE id=?',[$this->app->seal($creds),$channel['id']]);
        }
        return $creds;
    }
    /** Deletes Google-derived records, retaining user-authored posts and media files. */
    private function purge(int $id): void {
        $this->app->db->beginTransaction();
        try {
            $posts=$this->app->all('SELECT post_id FROM publications WHERE channel_id=?',[$id]);
            $this->app->query('DELETE FROM publications WHERE channel_id=?',[$id]);
            foreach($posts as $post)if(!$this->app->one('SELECT id FROM publications WHERE post_id=?',[$post['post_id']]))$this->app->query("UPDATE posts SET status='draft',version=version+1,updated_at=? WHERE id=? AND status='scheduled'",[$this->app->now(),$post['post_id']]);
            $this->app->query("DELETE FROM channels WHERE id=? AND network='youtube'",[$id]);
            $this->app->query('DELETE FROM settings WHERE setting_key=?',['google_delete_'.$id]);
            $this->app->db->commit();
        } catch (Throwable $e) { $this->app->db->rollBack(); throw $e; }
    }
    private function finishDeletion(array $channel): bool {
        $requested=(int)($this->setting('google_delete_'.$channel['id'])??0);
        $creds=$this->app->unseal($channel['credentials']);
        $token=$creds['refresh_token']??$creds['access_token']??null;
        try { if ($token) $this->http->form('https://oauth2.googleapis.com/revoke',['token'=>$token]); }
        catch (RemoteFailure $e) {
            if (!in_array($e->errorCode,['invalid_token','invalid_grant'],true)) {
                // Retry from cron, but never retain the requested Google data beyond seven days.
                if ($requested && $requested<=time()-7*86400) { $this->purge((int)$channel['id']); return true; }
                return false;
            }
        }
        $this->purge((int)$channel['id']); return true;
    }
    public function disconnect(int $id): bool {
        $lock=fopen($this->app->root.'/storage/worker.lock','c');
        if (!$lock||!flock($lock,LOCK_EX|LOCK_NB)) {if($lock)fclose($lock);throw new UserError('Publiceringsjobbet arbetar just nu. Försök koppla från igen om en minut.');}
        try {
            $channel=$this->app->one("SELECT * FROM channels WHERE id=? AND network='youtube'",[$id]);
            if (!$channel) throw new UserError('YouTube-kanalen finns inte.');
            if ($this->setting('google_delete_'.$id)===null) $this->store('google_delete_'.$id,(string)time());
            $this->app->query('UPDATE channels SET active=0 WHERE id=?',[$id]);
            return $this->finishDeletion($channel);
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    /** Called with the publisher lock held; at most one revoke and one connection per pass. */
    public function maintainOne(): void {
        if ($this->app->demo) return;
        $expired=$this->app->all("SELECT setting_key FROM settings WHERE setting_key LIKE 'google_delete_%' AND setting_value+0<=(?+0)",[time()-7*86400]);
        foreach($expired as $item){
            $id=(int)substr($item['setting_key'],strlen('google_delete_'));
            if($this->app->one("SELECT id FROM channels WHERE id=? AND network='youtube'",[$id]))$this->purge($id);
            else $this->app->query('DELETE FROM settings WHERE setting_key=?',[$item['setting_key']]);
        }
        $retryCursor=$this->setting('google_revoke_cursor')??'';
        $pending=$this->app->one("SELECT setting_key FROM settings WHERE setting_key LIKE 'google_delete_%' AND setting_key>? ORDER BY setting_key LIMIT 1",[$retryCursor])
            ??$this->app->one("SELECT setting_key FROM settings WHERE setting_key LIKE 'google_delete_%' ORDER BY setting_key LIMIT 1");
        if ($pending) {
            $this->store('google_revoke_cursor',$pending['setting_key']);
            $id=(int)substr($pending['setting_key'],strlen('google_delete_'));
            $channel=$this->app->one("SELECT * FROM channels WHERE id=? AND network='youtube'",[$id]);
            if($channel)$this->finishDeletion($channel);else $this->app->query('DELETE FROM settings WHERE setting_key=?',[$pending['setting_key']]);
            // A pending failed revocation must not prevent other connections being maintained.
        }
        $cursor=(int)($this->setting('google_maintenance_cursor')??0);
        $channel=$this->app->one("SELECT * FROM channels WHERE network='youtube' AND id>? ORDER BY id LIMIT 1",[$cursor])
            ?? $this->app->one("SELECT * FROM channels WHERE network='youtube' ORDER BY id LIMIT 1");
        if(!$channel)return;
        $this->store('google_maintenance_cursor',(string)$channel['id']);
        $creds=$this->app->unseal($channel['credentials']);
        if(!(int)$channel['active']&&empty($creds['refresh_token'])&&empty($creds['access_token'])){$this->purge((int)$channel['id']);return;}
        $this->app->query("UPDATE publications SET remote_id=NULL,remote_url=NULL,state_json=?,updated_at=? WHERE channel_id=? AND status NOT IN ('pending','processing') AND updated_at<?",[$this->app->seal(['completed'=>true]),$this->app->now(),$channel['id'],gmdate('Y-m-d H:i:s',time()-30*86400)]);
        $checked=(int)($creds['api_checked_at']??strtotime($channel['connected_at'].' UTC'));
        if(($creds['next_check_at']??0)>time())return;
        try {
            $credentialsReady=false;
            if ($checked<=time()-86400) {
                $creds=$this->credentials($channel);$credentialsReady=true;
                $response=$this->http->request('GET','https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true&fields=items(id,snippet(title))',['Authorization: Bearer '.$creds['access_token']])['json'];
                $identity=null;
                foreach($response['items']??[] as $item)if(($item['id']??null)===$channel['external_id']){$identity=$item;break;}
                if(!$identity){$this->purge((int)$channel['id']);return;}
                $creds['api_checked_at']=time();unset($creds['next_check_at']);
                $this->app->query('UPDATE channels SET name=?,credentials=? WHERE id=?',[mb_substr($identity['snippet']['title'],0,190),$this->app->seal($creds),$channel['id']]);
            }
            $old=$this->app->all("SELECT id,remote_id FROM publications WHERE channel_id=? AND status='published' AND remote_id IS NOT NULL AND updated_at<? ORDER BY updated_at,id LIMIT 50",[$channel['id'],gmdate('Y-m-d H:i:s',time()-86400)]);
            if(!$old)return;
            if(!$credentialsReady)$creds=$this->credentials($channel);
            $videos=$this->http->request('GET','https://www.googleapis.com/youtube/v3/videos?'.http_build_query(['part'=>'status','fields'=>'items(id)','id'=>implode(',',array_column($old,'remote_id'))]),['Authorization: Bearer '.$creds['access_token']])['json'];
            $present=array_column($videos['items']??[],'id');
            foreach($old as $publication){
                $exists=in_array($publication['remote_id'],$present,true);
                $this->app->query('UPDATE publications SET remote_id=?,remote_url=?,state_json=?,updated_at=? WHERE id=?',[$exists?$publication['remote_id']:null,$exists?'https://www.youtube.com/watch?v='.rawurlencode($publication['remote_id']):null,$this->app->seal(['completed'=>true]),$this->app->now(),$publication['id']]);
            }
        } catch (RemoteFailure|WorkerBudgetExpired $e) {
            if(!$this->app->one('SELECT id FROM channels WHERE id=?',[$channel['id']]))return;
            if($checked<=time()-30*86400){$this->purge((int)$channel['id']);return;}
            $creds['next_check_at']=time()+3600;
            $this->app->query('UPDATE channels SET credentials=? WHERE id=?',[$this->app->seal($creds),$channel['id']]);
        } catch (UserError) { if($checked<=time()-30*86400&&$this->app->one('SELECT id FROM channels WHERE id=?',[$channel['id']]))$this->purge((int)$channel['id']); }
    }
}
