<?php
declare(strict_types=1);

final class OAuth
{
    public function __construct(private App $app, private Http $http = new Http()) {}
    public function start(string $provider): string {
        $this->app->requireUser();
        if ($this->app->demo) throw new UserError('Kanalanslutningar är avstängda i den lokala demon.');
        if (!in_array($provider, ['google','meta'], true)) throw new UserError('Okänd kanaltyp.');
        $config = $this->app->config[$provider];
        if (empty($config[$provider === 'google' ? 'client_id' : 'app_id']) || empty($config[$provider === 'google' ? 'client_secret' : 'app_secret'])) throw new UserError('Fyll i ' . ($provider === 'google' ? 'Googles' : 'Metas') . ' appuppgifter i config.php först.');
        $state = bin2hex(random_bytes(32));
        $_SESSION['oauth'] = ['provider' => $provider, 'state' => $state, 'expires' => time() + 600];
        $params = ['client_id' => $config[$provider === 'google' ? 'client_id' : 'app_id'], 'redirect_uri' => $this->callback($provider), 'response_type' => 'code', 'state' => $state];
        if ($provider === 'google') {
            if($this->app->one("SELECT setting_key FROM settings WHERE setting_key LIKE 'google_delete_%' LIMIT 1"))throw new UserError('Radering av en Google-anslutning pågår. Slutför raderingen innan du ansluter Google igen.');
            $params += ['scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly', 'access_type' => 'offline', 'prompt' => 'consent'];
            return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
        }
        unset($_SESSION['meta_selection']);
        if (!empty($config['login_config_id'])) $params['config_id'] = $config['login_config_id'];
        else $params['scope'] = 'pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish,business_management';
        $params['auth_type']='rerequest';
        return 'https://www.facebook.com/' . $this->version() . '/dialog/oauth?' . http_build_query($params);
    }
    public function callback(string $provider): string { return $this->app->url('oauth.php?provider=' . $provider); }
    public function version(): string { $version = $this->app->config['meta']['version'] ?? 'v27.0'; if (!preg_match('/^v[0-9]+\.0$/D', $version)) throw new RuntimeException('Ogiltig Meta-version.'); return $version; }
    public function finish(string $provider, array $query): int {
        $this->app->requireUser(); $pending = $_SESSION['oauth'] ?? null; unset($_SESSION['oauth']);
        if (!$pending || $pending['provider'] !== $provider || $pending['expires'] < time() || !is_string($query['state'] ?? null) || !hash_equals($pending['state'], $query['state'])) throw new UserError('Kanalanslutningen har gått ut eller saknar giltig säkerhetskod. Börja om från Kanaler.');
        if (!empty($query['error'])) throw new UserError('Anslutningen avbröts hos plattformen. Ingen kanal lades till.');
        if (empty($query['code']) || !is_string($query['code'])) throw new UserError('Plattformens auktoriseringskod saknas.');
        return match ($provider) { 'google' => $this->google($query['code']), 'meta' => $this->meta($query['code']), default => throw new UserError('Okänd plattform.') };
    }
    private function save(string $network, string $id, string $name, array $credentials): void {
        if ($id === '' || mb_strlen($id) > 190) throw new UserError('Plattformen returnerade en ogiltig kanalidentitet.');
        $old = $this->app->one('SELECT * FROM channels WHERE network=? AND external_id=?', [$network,$id]);
        if ($old && $network === 'youtube' && empty($credentials['refresh_token'])) $credentials['refresh_token'] = $this->app->unseal($old['credentials'])['refresh_token'] ?? null;
        if ($network === 'youtube' && empty($credentials['refresh_token'])) throw new UserError('Google gav ingen förnyelsetoken. Anslut igen och godkänn offlineåtkomst.');
        if ($old) $this->app->query('UPDATE channels SET name=?,credentials=?,active=1,connected_at=? WHERE id=?', [mb_substr($name,0,190),$this->app->seal($credentials),$this->app->now(),$old['id']]);
        else $this->app->query('INSERT INTO channels(network,external_id,name,credentials,connected_at) VALUES(?,?,?,?,?)', [$network,$id,mb_substr($name,0,190),$this->app->seal($credentials),$this->app->now()]);
    }
    private function google(string $code): int {
        $config = $this->app->config['google'];
        $token = $this->http->form('https://oauth2.googleapis.com/token', ['client_id'=>$config['client_id'],'client_secret'=>$config['client_secret'],'code'=>$code,'grant_type'=>'authorization_code','redirect_uri'=>$this->callback('google')])['json'];
        if (empty($token['access_token'])) throw new UserError('Google returnerade ingen åtkomsttoken.');
        $identity = $this->http->request('GET','https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true&fields=items(id,snippet(title))',['Authorization: Bearer ' . $token['access_token']])['json'];
        $channel = $identity['items'][0] ?? null;
        if (!$channel) throw new UserError('Ingen YouTube-kanal hittades för kontot du valde.');
        $token['expires_at'] = time() + (int)($token['expires_in'] ?? 3600);
        $token['api_checked_at'] = time();
        $this->save('youtube',$channel['id'],$channel['snippet']['title'],$token); return 1;
    }
    private function pendingMeta(): ?array {
        $user=$this->app->requireUser(); $pending=$_SESSION['meta_selection']??null;
        if (!$pending || $pending['user_id']!==(int)$user['id'] || $pending['expires_at']<=time()) { unset($_SESSION['meta_selection']); return null; }
        return $pending;
    }
    public function metaChoices(): ?array {
        $pending=$this->pendingMeta(); if (!$pending) return null;
        $choices=$this->app->unseal($pending['choices']);
        return ['selection_id'=>$pending['selection_id'],'expires_at'=>$pending['expires_at'],'accounts'=>array_map(static fn($c)=>array_intersect_key($c,array_flip(['key','network','name','page_name','available','reason'])),array_values($choices))];
    }
    public function cancelMeta(): void { $this->app->requireUser(); unset($_SESSION['meta_selection']); }
    public function selectMeta(string $selectionId,array $keys): int {
        $this->app->requirePolicies();
        $pending=$this->pendingMeta();
        if (!$pending || !hash_equals($pending['selection_id'],$selectionId)) throw new UserError('Sidvalet har gått ut eller ändrats. Anslut Meta igen.');
        if (!$keys) throw new UserError('Välj minst en sida eller ett Instagram-konto.');
        $choices=$this->app->unseal($pending['choices']); $selected=[];
        foreach ($keys as $key) {
            if (!is_string($key) || !isset($choices[$key]) || !$choices[$key]['available']) throw new UserError('Ett valt konto saknas i Metas svar eller har inte publiceringsrätt. Hämta kontona igen.');
            $selected[$key]=$choices[$key];
        }
        $this->app->db->beginTransaction();
        try {
            foreach ($selected as $choice) $this->save($choice['network'],$choice['external_id'],$choice['name'],$choice['credentials']);
            $this->app->db->commit();
        } catch (Throwable $error) { $this->app->db->rollBack(); throw $error; }
        unset($_SESSION['meta_selection']);
        return count($selected);
    }
    private function meta(string $code): int {
        $config = $this->app->config['meta']; $base = 'https://graph.facebook.com/' . $this->version();
        $token = $this->http->request('GET',$base . '/oauth/access_token?' . http_build_query(['client_id'=>$config['app_id'],'client_secret'=>$config['app_secret'],'code'=>$code,'redirect_uri'=>$this->callback('meta')]))['json'];
        if (empty($token['access_token'])) throw new UserError('Meta returnerade ingen åtkomsttoken.');
        $token = $this->http->request('GET',$base . '/oauth/access_token?' . http_build_query(['grant_type'=>'fb_exchange_token','client_id'=>$config['app_id'],'client_secret'=>$config['app_secret'],'fb_exchange_token'=>$token['access_token']]))['json'];
        if (empty($token['access_token'])) throw new UserError('Metas token kunde inte förnyas.');
        $after = null; $choices=[];
        do {
            $params = ['fields'=>'id,name,access_token,tasks,instagram_business_account{id,username}','limit'=>100]; if ($after) $params['after']=$after;
            $response = $this->http->request('GET',$base . '/me/accounts?' . http_build_query($params),['Authorization: Bearer ' . $token['access_token']])['json'];
            foreach ($response['data'] ?? [] as $page) {
                $id=(string)($page['id']??'');
                if (!preg_match('/^[0-9]{1,190}$/D',$id)) throw new UserError('Meta returnerade en ogiltig sididentitet. Ingen kanal anslöts.');
                $available=!empty($page['access_token']) && (bool)array_intersect(['CREATE_CONTENT','MANAGE','PROFILE_PLUS_CREATE_CONTENT','PROFILE_PLUS_FULL_CONTROL'],$page['tasks']??[]);
                $reason=$available?null:(empty($page['access_token'])?'Meta lämnade inte ut publiceringsåtkomst till sidan.':'Din Facebook-profil saknar publiceringsrätt enligt Meta.');
                $name=(string)($page['name']??$id);
                $creds=['access_token'=>$page['access_token']??null,'page_id'=>$id];
                $key='facebook:'.$id;
                $choices[$key]=['key'=>$key,'network'=>'facebook','external_id'=>$id,'name'=>$name,'page_name'=>$name,'available'=>$available,'reason'=>$reason,'credentials'=>$creds];
                if (!empty($page['instagram_business_account']['id'])) {
                    $ig=$page['instagram_business_account']; $igId=(string)$ig['id'];
                    if (!preg_match('/^[0-9]{1,190}$/D',$igId)) throw new UserError('Meta returnerade en ogiltig Instagram-identitet. Ingen kanal anslöts.');
                    $key='instagram:'.$igId;
                    $choices[$key]=['key'=>$key,'network'=>'instagram','external_id'=>$igId,'name'=>(string)($ig['username']??$name),'page_name'=>$name,'available'=>$available,'reason'=>$reason,'credentials'=>$creds];
                }
            }
            if (count($choices)>1000) throw new UserError('Fler än 1 000 konton hittades. Begränsa sidåtkomsten hos Meta och anslut igen.');
            $next = isset($response['paging']['next']) ? ($response['paging']['cursors']['after'] ?? null) : null;
            if ($next === $after) $next = null; $after = $next;
        } while ($after);
        if (!$choices) throw new UserError('Meta lämnade inte ut några Facebook-sidor. Kontrollera att din Facebook-profil har åtkomst till rätt sida och uppdatera Socialens åtkomst hos Meta.');
        $_SESSION['meta_selection']=['selection_id'=>bin2hex(random_bytes(16)),'user_id'=>(int)$this->app->requireUser()['id'],'expires_at'=>time()+600,'choices'=>$this->app->seal($choices)];
        return 0; // Discovery never activates a channel. The user confirms an explicit selection.
    }
}
