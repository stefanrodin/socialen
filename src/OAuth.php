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
            $params += ['scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly', 'access_type' => 'offline', 'prompt' => 'consent'];
            return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
        }
        if (!empty($config['login_config_id'])) $params['config_id'] = $config['login_config_id'];
        else $params['scope'] = 'pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish';
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
        $identity = $this->http->request('GET','https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true',['Authorization: Bearer ' . $token['access_token']])['json'];
        $channel = $identity['items'][0] ?? null;
        if (!$channel) throw new UserError('Ingen YouTube-kanal hittades för kontot du valde.');
        $token['expires_at'] = time() + (int)($token['expires_in'] ?? 3600);
        $this->save('youtube',$channel['id'],$channel['snippet']['title'],$token); return 1;
    }
    private function meta(string $code): int {
        $config = $this->app->config['meta']; $base = 'https://graph.facebook.com/' . $this->version();
        $token = $this->http->request('GET',$base . '/oauth/access_token?' . http_build_query(['client_id'=>$config['app_id'],'client_secret'=>$config['app_secret'],'code'=>$code,'redirect_uri'=>$this->callback('meta')]))['json'];
        if (empty($token['access_token'])) throw new UserError('Meta returnerade ingen åtkomsttoken.');
        $token = $this->http->request('GET',$base . '/oauth/access_token?' . http_build_query(['grant_type'=>'fb_exchange_token','client_id'=>$config['app_id'],'client_secret'=>$config['app_secret'],'fb_exchange_token'=>$token['access_token']]))['json'];
        if (empty($token['access_token'])) throw new UserError('Metas token kunde inte förnyas.');
        $after = null; $count = 0;
        do {
            $params = ['fields'=>'id,name,access_token,tasks,instagram_business_account{id,username}','limit'=>100]; if ($after) $params['after']=$after;
            $response = $this->http->request('GET',$base . '/me/accounts?' . http_build_query($params),['Authorization: Bearer ' . $token['access_token']])['json'];
            foreach ($response['data'] ?? [] as $page) {
                if (empty($page['access_token']) || !array_intersect(['CREATE_CONTENT','MANAGE','PROFILE_PLUS_CREATE_CONTENT','PROFILE_PLUS_FULL_CONTROL'], $page['tasks'] ?? [])) continue;
                $creds = ['access_token'=>$page['access_token'],'page_id'=>$page['id']];
                $this->save('facebook',$page['id'],$page['name'],$creds); ++$count;
                if (!empty($page['instagram_business_account']['id'])) { $ig=$page['instagram_business_account']; $this->save('instagram',$ig['id'],$ig['username'] ?? $page['name'],$creds); ++$count; }
            }
            $next = isset($response['paging']['next']) ? ($response['paging']['cursors']['after'] ?? null) : null;
            if ($next === $after) $next = null; $after = $next;
            if ($count > 1000 && $after) throw new UserError('Fler än 1 000 kanaler hittades. Begränsa sidåtkomsten hos Meta och anslut igen.');
        } while ($after);
        if (!$count) throw new UserError('Ingen Facebook-sida med publiceringsrätt hittades. Instagram behöver vara ett professionellt konto kopplat till en vald sida.');
        return $count;
    }
}
