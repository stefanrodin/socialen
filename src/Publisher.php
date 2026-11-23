<?php
declare(strict_types=1);

final class Publisher
{
    private float $deadline;
    public function __construct(private App $app, private Http $http = new Http()) {}
    private function checkpoint(int $id, array $state): void { $this->app->query('UPDATE publications SET state_json=?,updated_at=? WHERE id=?', [$this->app->seal($state),$this->app->now(),$id]); }
    private function mutate(array $job, array &$state, callable $call): array {
        $state['in_flight'] = true; $this->checkpoint((int)$job['id'],$state);
        try { $result = $call(); }
        catch (WorkerBudgetExpired $e) { $state['in_flight']=false; $this->checkpoint((int)$job['id'],$state); throw $e; }
        catch (RemoteFailure $e) { if (!$e->uncertain) { $state['in_flight']=false; $this->checkpoint((int)$job['id'],$state); } throw $e; }
        $state['in_flight'] = false;
        // The caller checkpoints the returned remote identity and this flag together.
        return $result;
    }
    public function run(): array {
        if ($this->app->demo) throw new UserError('Demoläget publicerar aldrig till externa plattformar.');
        $lock = fopen($this->app->root . '/storage/worker.lock','c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['busy'=>true,'handled'=>0];
        try {
            $this->deadline = microtime(true) + 45;
            $this->http->deadline($this->deadline);
            try { (new GoogleAccess($this->app,$this->http))->maintainOne(); } catch(WorkerBudgetExpired|RemoteFailure) {}
            foreach ($this->app->all("SELECT * FROM publications WHERE status='processing'") as $old) {
                $state = $old['state_json'] === '{}' ? [] : $this->app->unseal($old['state_json']);
                $uncertain = !empty($state['in_flight']);
                $this->app->query('UPDATE publications SET status=?,last_error=?,updated_at=? WHERE id=?', [$uncertain?'uncertain':'pending',$uncertain?'Ett tidigare jobb avbröts under ett API-anrop. Kontrollera plattformen innan något nytt publiceras.':null,$this->app->now(),$old['id']]);
            }
            $jobs = $this->app->all("SELECT p.id FROM publications p JOIN posts s ON s.id=p.post_id WHERE p.status='pending' AND s.status='scheduled' AND s.scheduled_at<=? AND p.next_at<=? ORDER BY s.scheduled_at,p.id LIMIT 10",[$this->app->now(),$this->app->now()]);
            $handled=0;
            foreach ($jobs as $selected) {
                if (microtime(true) > $this->deadline - 22) break;
                $this->app->db->beginTransaction();
                $job=$this->app->one('SELECT p.*,s.title,s.caption,s.media_json,s.visibility,s.made_for_kids,s.status AS post_status,c.network,c.external_id,c.credentials,c.active FROM publications p JOIN posts s ON s.id=p.post_id JOIN channels c ON c.id=p.channel_id WHERE p.id=?' . ($this->app->sqlite()?'':' FOR UPDATE'),[$selected['id']]);
                if (!$job || $job['status']!=='pending' || $job['post_status']!=='scheduled') { $this->app->db->commit(); continue; }
                $this->app->query("UPDATE publications SET status='processing',updated_at=? WHERE id=?",[$this->app->now(),$job['id']]); $this->app->db->commit();
                $state = $job['state_json']==='{}'?[]:$this->app->unseal($job['state_json']);
                try {
                    $assets=array_map(fn($id)=>$this->app->getMedia((int)$id),json_decode($job['media_json'],true,512,JSON_THROW_ON_ERROR));
                    $this->app->validatePublication($job['network'],$job['caption'],$assets);
                    do {
                        $outcome=$this->step($job,$state,$assets); $this->checkpoint((int)$job['id'],$state);
                        if ($outcome==='published') break;
                    } while ($outcome==='continue' && microtime(true) < $this->deadline - 22);
                    if ($outcome==='published') $this->app->query("UPDATE publications SET status='published',remote_id=?,remote_url=?,last_error=?,state_json=?,updated_at=? WHERE id=?",[$state['remote_id'],$state['remote_url']??null,$state['warning']??null,$this->app->seal(['completed'=>true]),$this->app->now(),$job['id']]);
                    else $this->app->query("UPDATE publications SET status='pending',last_error=NULL,next_at=?,updated_at=? WHERE id=?",[gmdate('Y-m-d H:i:s',time()+15),$this->app->now(),$job['id']]);
                } catch (Throwable $e) {
                    $uncertain = !empty($state['in_flight']) || ($e instanceof RemoteFailure && $e->uncertain);
                    // A resumable transfer has its own recovery query; repeating bytes is unnecessary.
                    $budgetExpired=$e instanceof WorkerBudgetExpired;
                    $transient = $e instanceof RemoteFailure && ($e->status===0 || in_array($e->status,[429,500,502,503,504],true));
                    $resume = $job['network']==='youtube' && !empty($state['upload_url']) && $transient;
                    $safePollRetry = !$uncertain && $transient && (!empty($state['container']) || !empty($state['video_id']));
                    if ($e instanceof RemoteFailure && !$resume && !$safePollRetry && (!empty($state['video_id']) || !empty($state['remote_id']))) $uncertain=true;
                    if ($resume) { unset($state['in_flight']); $state['query_upload']=true; $this->checkpoint((int)$job['id'],$state); }
                    $message = $e instanceof RemoteFailure || $e instanceof UserError || $budgetExpired ? $e->getMessage() : 'Ett internt fel avbröt jobbet. Kontrollera PHP-loggen och plattformen innan ett nytt försök.';
                    $this->app->query('UPDATE publications SET status=?,last_error=?,next_at=?,updated_at=? WHERE id=?',[$resume||$safePollRetry||$budgetExpired?'pending':($uncertain?'uncertain':'failed'),$message,gmdate('Y-m-d H:i:s',time()+120),$this->app->now(),$job['id']]);
                }
                ++$handled;
            }
            $now=$this->app->now(); if ($this->app->one("SELECT setting_key FROM settings WHERE setting_key='last_worker'")) $this->app->query("UPDATE settings SET setting_value=? WHERE setting_key='last_worker'",[$now]); else $this->app->query('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)',['last_worker',$now]);
            return ['busy'=>false,'handled'=>$handled,'last_worker'=>$now];
        } finally { $this->http->deadline(null); flock($lock,LOCK_UN); fclose($lock); }
    }
    private function step(array $job, array &$state, array $assets): string {
        $channel=$this->app->one('SELECT active,credentials FROM channels WHERE id=?',[$job['channel_id']]);
        if (!$channel || !(int)$channel['active']) throw new UserError('Kanalen är frånkopplad. Anslut den igen innan du skapar ett nytt publiceringsförsök.');
        $creds=$this->app->unseal($channel['credentials']);
        if (!empty($creds['demo'])) throw new UserError('En demokanal får inte publicera. Anslut en riktig kanal.');
        if (!empty($state['remote_id']) && empty($state['youtube_processing'])) return 'published';
        return match ($job['network']) { 'youtube'=>$this->youtube($job,$state,$assets[0],$creds), 'instagram'=>$this->instagram($job,$state,$assets,$creds), 'facebook'=>$this->facebook($job,$state,$assets,$creds), default=>throw new UserError('Kanaltypen stöds inte.') };
    }
    private function youtube(array $job, array &$state, array $media, array $creds): string {
        $channel=$this->app->one('SELECT * FROM channels WHERE id=?',[$job['channel_id']]);
        $creds=(new GoogleAccess($this->app,$this->http))->credentials($channel);
        $headers=['Authorization: Bearer '.$creds['access_token']]; $total=(int)$media['bytes'];
        if (!empty($state['remote_id'])) {
            $video=$this->http->request('GET','https://www.googleapis.com/youtube/v3/videos?'.http_build_query(['part'=>'status','fields'=>'items(id,status(uploadStatus))','id'=>$state['remote_id']]),$headers)['json']['items'][0]??null;
            if (!$video) throw new RemoteFailure('Den uppladdade YouTube-videon kunde inte hittas. Kontrollera kanalen.',true);
            $status=$video['status']['uploadStatus']??'';
            if (in_array($status,['failed','rejected','deleted'],true)) throw new UserError('YouTube stoppade den uppladdade videon: '.$status.'. Kontrollera den i YouTube Studio.');
            if ($status==='processed') { $state['youtube_processing']=false; return 'published'; }
            return 'wait';
        }
        if (empty($state['upload_url'])) {
            if ($job['made_for_kids']===null) throw new UserError('Ange om YouTube-videon är avsedd för barn.');
            $payload=['snippet'=>['title'=>$job['title'],'description'=>$job['caption'],'categoryId'=>'22'],'status'=>['privacyStatus'=>$job['visibility'],'selfDeclaredMadeForKids'=>(bool)$job['made_for_kids']]];
            $response=$this->mutate($job,$state,fn()=>$this->http->request('POST','https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status',array_merge($headers,['Content-Type: application/json','X-Upload-Content-Type: video/mp4','X-Upload-Content-Length: '.$total]),json_encode($payload,JSON_THROW_ON_ERROR)));
            $location=$response['headers']['location']??'';
            if (!str_starts_with($location,'https://www.googleapis.com/') && !str_starts_with($location,'https://upload.googleapis.com/')) throw new RemoteFailure('YouTube gav ingen giltig återupptagningsadress.',true);
            $state['upload_url']=$location; $state['offset']=0; $state['query_upload']=true; return 'continue';
        }
        // Always reconcile a persisted upload before sending the next chunk.
        if (!empty($state['query_upload'])) {
            $response=$this->http->request('PUT',$state['upload_url'],array_merge($headers,['Content-Length: 0','Content-Range: bytes */'.$total]),'');
            $state['query_upload']=false;
            return $this->uploadOutcome($response,$state,$total);
        }
        $offset=(int)($state['offset']??0);
        if ($offset >= $total) { $state['query_upload']=true; return 'wait'; }
        $file=fopen($this->app->root.'/storage/uploads/'.$media['path'],'rb'); if (!$file) throw new UserError('Videofilen saknas på servern.');
        try { fseek($file,$offset); $chunk=fread($file,min(4194304,$total-$offset)); } finally { fclose($file); }
        if ($chunk===false || $chunk==='') throw new UserError('Videofilen kunde inte läsas.');
        $state['query_upload']=true; $this->checkpoint((int)$job['id'],$state);
        $end=$offset+strlen($chunk)-1;
        $response=$this->http->request('PUT',$state['upload_url'],array_merge($headers,['Content-Type: video/mp4','Content-Length: '.strlen($chunk),'Content-Range: bytes '.$offset.'-'.$end.'/'.$total]),$chunk);
        $outcome=$this->uploadOutcome($response,$state,$total); return $outcome;
    }
    private function uploadOutcome(array $response,array &$state,int $total): string {
        if (in_array($response['status'],[200,201],true) && !empty($response['json']['id'])) { $state['remote_id']=$response['json']['id']; $state['remote_url']='https://www.youtube.com/watch?v='.rawurlencode($state['remote_id']); $state['in_flight']=false; $state['youtube_processing']=true; return 'continue'; }
        if ($response['status']!==308) throw new RemoteFailure('Onväntat svar från YouTube. Kontrollera den återupptagbara uppladdningen.',true);
        $range=$response['headers']['range']??'';
        $offset=0; if ($range!=='' && !preg_match('/^bytes=0-([0-9]+)$/D',$range,$matches)) throw new RemoteFailure('YouTubes uppladdningsposition kunde inte tolkas.',true);
        if ($range!=='') $offset=(int)$matches[1]+1;
        if ($offset>$total) throw new RemoteFailure('YouTube returnerade en ogiltig uppladdningsposition.',true);
        $state['offset']=$offset; $state['query_upload']=false; return 'continue';
    }
    private function graph(array $creds,string $path,array $params=[],bool $post=false): array {
        $version=(new OAuth($this->app,$this->http))->version();
        $url='https://graph.facebook.com/'.$version.'/'.$path;
        $headers=['Authorization: Bearer '.$creds['access_token']];
        return $post ? $this->http->form($url,$params,$headers)['json'] : $this->http->request('GET',$url.'?'.http_build_query($params),$headers)['json'];
    }
    private function instagram(array $job,array &$state,array $assets,array $creds): string {
        $ig=rawurlencode($job['external_id']);
        if (count($assets)>1 && count($state['children']??[])<count($assets)) {
            $media=$assets[count($state['children']??[])];
            $result=$this->mutate($job,$state,fn()=>$this->graph($creds,$ig.'/media',['image_url'=>$this->app->mediaUrl($media),'is_carousel_item'=>'true'],true));
            if (empty($result['id'])) throw new RemoteFailure('Instagram gav ingen medieidentitet.',true);
            $state['children'][]=$result['id']; return 'continue';
        }
        if (empty($state['container'])) {
            $params=['caption'=>$job['caption']];
            if (count($assets)>1) $params+=['media_type'=>'CAROUSEL','children'=>implode(',',$state['children'])];
            elseif ($assets[0]['mime']==='video/mp4') $params+=['media_type'=>'REELS','video_url'=>$this->app->mediaUrl($assets[0]),'share_to_feed'=>'true'];
            else $params['image_url']=$this->app->mediaUrl($assets[0]);
            $result=$this->mutate($job,$state,fn()=>$this->graph($creds,$ig.'/media',$params,true));
            if (empty($result['id'])) throw new RemoteFailure('Instagram gav ingen containeridentitet.',true);
            $state['container']=$result['id']; $state['created_at']=time(); return 'continue';
        }
        $result=$this->graph($creds,rawurlencode($state['container']),['fields'=>'status_code']);
        if (($result['status_code']??'')==='IN_PROGRESS') return 'wait';
        if (($result['status_code']??'')==='PUBLISHED') throw new RemoteFailure('Instagram anger att containern redan är publicerad. Kontrollera inlägget i Instagram.',true);
        if (($result['status_code']??'')!=='FINISHED') throw new UserError('Instagram kunde inte förbereda mediet: '.($result['status_code']??'okänd status').'.');
        $result=$this->mutate($job,$state,fn()=>$this->graph($creds,$ig.'/media_publish',['creation_id'=>$state['container']],true));
        if (empty($result['id'])) throw new RemoteFailure('Instagram gav ingen publiceringsidentitet.',true);
        $state['remote_id']=$result['id'];
        if (!empty($result['config_issue'])) $state['warning']='Publicerat med plattformsvarning: '.(is_string($result['config_issue'])?$result['config_issue']:'kontrollera text/taggar hos Instagram');
        try { $details=$this->graph($creds,rawurlencode($result['id']),['fields'=>'permalink']); $state['remote_url']=$details['permalink']??null; } catch (Throwable) { /* The post ID already proves API acceptance; never repeat publication. */ }
        return 'published';
    }
    private function facebook(array $job,array &$state,array $assets,array $creds): string {
        $page=rawurlencode($job['external_id']);
        if (!$assets) $result=$this->mutate($job,$state,fn()=>$this->graph($creds,$page.'/feed',['message'=>$job['caption'],'published'=>'true'],true));
        elseif ($assets[0]['mime']==='video/mp4') {
            if (empty($state['video_id'])) {
                $version=(new OAuth($this->app,$this->http))->version();
                $result=$this->mutate($job,$state,fn()=>$this->http->form('https://graph-video.facebook.com/'.$version.'/'.$page.'/videos',['file_url'=>$this->app->mediaUrl($assets[0]),'description'=>$job['caption'],'title'=>$job['title'],'published'=>'true'],['Authorization: Bearer '.$creds['access_token']])['json']);
                if (empty($result['id'])) throw new RemoteFailure('Facebook gav ingen videoidentitet.',true);
                $state['video_id']=$result['id']; return 'continue';
            }
            $video=$this->graph($creds,rawurlencode($state['video_id']),['fields'=>'status,permalink_url']);
            $status=$video['status']['video_status']??'';
            if ($status==='error') throw new UserError('Facebook kunde inte bearbeta videon. Kontrollera den i Meta Business Suite.');
            if ($status!=='ready') return 'wait';
            $state['remote_id']=$state['video_id']; $state['remote_url']=$video['permalink_url']??('https://www.facebook.com/'.$state['video_id']); return 'published';
        } else $result=$this->mutate($job,$state,fn()=>$this->graph($creds,$page.'/photos',['url'=>$this->app->mediaUrl($assets[0]),'caption'=>$job['caption'],'published'=>'true'],true));
        $id=$result['post_id']??$result['id']??null;
        if (!$id) throw new RemoteFailure('Facebook gav ingen publiceringsidentitet.',true);
        $state['remote_id']=$id; $state['remote_url']='https://www.facebook.com/'.rawurlencode($id); return 'published';
    }
}
