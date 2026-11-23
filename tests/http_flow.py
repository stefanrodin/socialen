#!/usr/bin/env python3
"""Isolated HTTP workflow checks; never calls Google or Meta."""
import base64
import http.client
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

source = Path(__file__).resolve().parent.parent
php = shutil.which('php')
with tempfile.TemporaryDirectory(prefix='publicera-http-test-') as directory:
    root = Path(directory)
    for name in ('src', 'public'):
        shutil.copytree(source / name, root / name)
    (root / 'storage').mkdir(mode=0o700)
    key = base64.b64encode(os.urandom(32)).decode()
    # All values here are controlled temporary-test paths, not user credentials.
    config = "<?php return ['url'=>'https://publicera.example','timezone'=>'Europe/Stockholm','key'=>'%s','database'=>['dsn'=>'sqlite:%s/storage/test.sqlite','user'=>null,'password'=>null],'upload_limit_mb'=>10,'google'=>[],'meta'=>[]];" % (key, root)
    (root / 'config.php').write_text(config)
    setup = "require 'src/bootstrap.php'; $a=app(); $a->migrate(); $a->query('INSERT INTO users(email,name,password_hash,created_at) VALUES(?,?,?,?)',['admin@example.se','Test',password_hash('test-password-123',PASSWORD_DEFAULT),$a->now()]); $i=imagecreatetruecolor(32,32); imagejpeg($i,'storage/test.jpg');"
    subprocess.run([php, '-r', setup], cwd=root, check=True, capture_output=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    env = os.environ.copy()
    env.pop('PUBLICERA_DEMO', None)
    server = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', 'public'], cwd=root, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    cookie = ''
    def request(method, path, value=None, csrf=None, raw=None, content_type=None, send_cookie=True, extra=None):
        headers = dict(extra or {})
        if send_cookie and cookie:
            headers['Cookie'] = cookie
        if csrf:
            headers['X-CSRF-Token'] = csrf
        body = raw
        if value is not None:
            body = json.dumps(value).encode()
            headers['Content-Type'] = 'application/json'
        if content_type:
            headers['Content-Type'] = content_type
        conn = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
        conn.request(method, path, body=body, headers=headers)
        response = conn.getresponse()
        result = (response.status, dict(response.getheaders()), response.read())
        conn.close()
        return result
    try:
        for _ in range(50):
            try:
                initial = request('GET', '/api.php?action=bootstrap')
                break
            except OSError:
                if server.poll() is not None:
                    raise RuntimeError('PHP test server failed to start')
                time.sleep(.05)
        else:
            raise RuntimeError('PHP test server did not become ready')
        for page, marker in [('about.php', b'YouTube API Services'), ('privacy.php', b'Limited Use'), ('terms.php', b'https://www.youtube.com/t/terms'), ('data-deletion.php', b'myaccount.google.com/permissions')]:
            page_status, _, page_body = request('GET', '/' + page, send_cookie=False)
            assert page_status == 200 and marker in page_body
            assert b'admin@example.se' not in page_body and key.encode() not in page_body
        status, headers, body = initial
        assert status == 200 and json.loads(body)['user'] is None
        flags = headers['Set-Cookie'].lower()
        assert 'secure' in flags and 'httponly' in flags and 'samesite=lax' in flags
        cookie = headers['Set-Cookie'].split(';')[0]
        csrf = json.loads(body)['csrf']
        assert request('POST', '/api.php?action=save', {'title': 'Blocked'})[0] == 422
        assert request('POST', '/api.php?action=save', {'title': 'Blocked'}, csrf)[0] == 422
        status, headers, body = request('POST', '/api.php?action=login', {'email': 'admin@example.se', 'password': 'test-password-123'}, csrf)
        assert status == 200
        cookie = headers['Set-Cookie'].split(';')[0]
        pending_policy = json.loads(request('GET', '/api.php?action=bootstrap')[2])
        csrf = pending_policy['csrf']
        assert pending_policy['policy_accepted'] is False and 'posts' not in pending_policy
        assert request('POST', '/api.php?action=save', {'title': 'No consent'}, csrf)[0] == 422
        assert request('POST', '/api.php?action=accept-policy', {'version': 'old'}, csrf)[0] == 422
        assert request('POST', '/api.php?action=accept-policy', {'version': pending_policy['policy_version']}, csrf)[0] == 200
        status, headers, body = request('POST', '/api.php?action=login', {'email': 'admin@example.se', 'password': 'test-password-123'}, csrf)
        assert status == 200
        new_cookie = headers['Set-Cookie'].split(';')[0]
        assert new_cookie != cookie
        cookie = new_cookie
        authenticated = json.loads(request('GET', '/api.php?action=bootstrap')[2])
        csrf = authenticated['csrf']
        assert authenticated['user']['email'] == 'admin@example.se'
        assert 'database' not in authenticated and 'key' not in authenticated
        status, _, body = request('POST', '/api.php?action=save', {'title': 'HTTP-test', 'caption': 'Svensk text 🧡 <script>inert</script>', 'status': 'draft'}, csrf)
        assert status == 200
        post_id = json.loads(body)['id']
        saved = json.loads(request('GET', '/api.php?action=bootstrap')[2])['posts'][0]
        assert saved['id'] == post_id and saved['caption'].endswith('<script>inert</script>')
        assert request('POST', '/api.php?action=save', {'id': post_id, 'version': 99, 'title': 'Conflict'}, csrf)[0] == 422
        boundary = 'publicera-test-boundary'
        jpeg = (root / 'storage/test.jpg').read_bytes()
        upload = (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="test.jpg"\r\nContent-Type: image/jpeg\r\n\r\n'.encode() + jpeg + f'\r\n--{boundary}--\r\n'.encode())
        status, _, body = request('POST', '/api.php?action=upload', csrf=csrf, raw=upload, content_type=f'multipart/form-data; boundary={boundary}')
        assert status == 200, body
        media = json.loads(body)['media']
        assert media['mime'] == 'image/jpeg' and media['width'] == 32
        assert request('GET', f'/media.php?id={media["id"]}', send_cookie=False)[0] == 404
        public_url = f'/media.php?id={media["id"]}&key={media["public_key"]}'
        status, headers, body = request('GET', public_url, send_cookie=False, extra={'Range': 'bytes=0-9'})
        assert status == 206 and len(body) == 10 and headers['Content-Type'] == 'image/jpeg'
        assert request('GET', public_url, send_cookie=False, extra={'Range': 'bytes=999999999-'})[0] == 416
        assert request('POST', '/api.php?action=upload', csrf=csrf, raw=upload.replace(b'image/jpeg', b'text/plain').replace(jpeg, b'<?php echo 1;'), content_type=f'multipart/form-data; boundary={boundary}')[0] == 422
        status, headers, _ = request('GET', '/')
        assert status == 200 and "frame-ancestors 'none'" in headers['Content-Security-Policy']
        assert request('GET', '/config.php')[0] == 404
        assert request('POST', '/api.php?action=logout', {}, csrf)[0] == 200
        assert json.loads(request('GET', '/api.php?action=bootstrap')[2])['user'] is None
        print('PASS HTTP: auth/session rotation, CSRF, draft persistence, conflict, upload validation, private media, public ranges, CSP, document root, logout.')
    finally:
        server.terminate()
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait()
