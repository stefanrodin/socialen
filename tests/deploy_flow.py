#!/usr/bin/env python3
"""Exercise the Linux deployment receiver against a disposable app and HTTP server."""
import base64
import fcntl
import io
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tarfile
import tempfile
import time

if sys.platform != 'linux':
    print('Deployment integration requires Linux (Inleed/GitHub Actions); skipped locally.')
    raise SystemExit(0)

source = Path(__file__).resolve().parent.parent
php = shutil.which('php')
with tempfile.TemporaryDirectory(prefix='socialen-deploy-test-') as directory:
    root = Path(directory)
    for name in ['storage', 'releases/bootstrap', 'deploy']:
        (root / name).mkdir(parents=True)
    initial = root / 'releases/bootstrap'
    for name in ['src', 'bin', 'public']:
        shutil.copytree(source / name, initial / name)
    key = base64.b64encode(os.urandom(32)).decode()
    config = "<?php return ['name'=>'Test','url'=>'https://socialen.example','timezone'=>'Europe/Stockholm','key'=>'%s','database'=>['dsn'=>'sqlite:%s/storage/test.sqlite','user'=>null,'password'=>null],'upload_limit_mb'=>100,'google'=>[],'meta'=>[]];" % (key, root)
    (root / 'config.php').write_text(config)
    (initial / 'config.php').symlink_to(root / 'config.php')
    (initial / 'storage').symlink_to(root / 'storage')
    (root / 'current').symlink_to(initial)
    for name, destination in [('public_html', 'public'), ('src', 'src'), ('bin', 'bin')]:
        (root / name).symlink_to(root / 'current' / destination)
    (root / 'deploy/hosting.htaccess').write_text('\n# Hosting settings retained\n')
    (root / 'storage/preserved.txt').write_text('user upload must survive')
    subprocess.run([php, '-r', 'require $argv[1]."/src/bootstrap.php"; app()->migrate(); app()->savePost(["title"=>"Preserved draft","caption"=>"Text","status"=>"draft"]);', str(root)], check=True, capture_output=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    # Resolve the release for every request, as the hosting web server must do.
    # PHP's development server otherwise retains symlinked script paths across requests.
    router = root / 'router.php'
    router.write_text('<?php $root=' + json.dumps(str(root)) + '; $base=readlink($root."/current")."/public"; $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); if ($path==="/public_html/api.php") { require $base."/api.php"; } elseif ($path==="/public_html/deploy-revision.txt") { readfile($base."/deploy-revision.txt"); } else {http_response_code(404);}')
    server = subprocess.Popen([php, '-d', 'realpath_cache_size=0', '-d', 'opcache.enable_cli=0', '-S', f'127.0.0.1:{port}', str(router)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    url = f'http://127.0.0.1:{port}/public_html'
    env = dict(os.environ, SSH_ORIGINAL_COMMAND='deploy')
    env.pop('PUBLICERA_DEMO', None)
    command = ['bash', str(source / 'deploy/inleed-receive.sh'), str(root), php, url]
    def bundle(revision, broken=False, private=False):
        stream = io.BytesIO()
        with tarfile.open(fileobj=stream, mode='w:gz') as archive:
            # Reproduce a private mktemp staging directory in incoming archive metadata.
            member = tarfile.TarInfo('.'); member.type = tarfile.DIRTYPE; member.mode = 0o700
            archive.addfile(member)
            for name in ['src', 'bin', 'public']:
                archive.add(source / name, arcname=name)
            for name, content in [('REVISION', revision + '\n')]:
                data = content.encode()
                member = tarfile.TarInfo(name); member.size = len(data)
                archive.addfile(member, io.BytesIO(data))
            if broken:
                data = b'<?php http_response_code(503); echo "health failure";'
                member = tarfile.TarInfo('public/api.php'); member.size = len(data)
                archive.addfile(member, io.BytesIO(data))
            if private:
                data = b'<?php return [];'
                member = tarfile.TarInfo('config.php'); member.size = len(data)
                archive.addfile(member, io.BytesIO(data))
        return stream.getvalue()
    try:
        for attempt in range(50):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1):
                    break
            except OSError:
                time.sleep(.05)
        first = subprocess.run(command, input=bundle('a' * 40), env=env, capture_output=True, timeout=60)
        assert first.returncode == 0, (first.stdout, first.stderr)
        active = (root / 'current').resolve()
        assert active != initial
        assert active.stat().st_mode & 0o777 == 0o755, 'Web server cannot traverse release directory'
        assert (active / 'REVISION').read_text().strip() == 'a' * 40
        assert (active / 'config.php').resolve() == root / 'config.php'
        assert (active / 'storage').resolve() == root / 'storage'
        assert 'Hosting settings retained' in (active / 'public/.htaccess').read_text()
        assert (root / 'storage/preserved.txt').read_text() == 'user upload must survive'
        config_before = (root / 'config.php').read_bytes()
        check = subprocess.run([php, '-r', 'require $argv[1]."/src/bootstrap.php"; echo app()->one("SELECT title FROM posts LIMIT 1")["title"];', str(root)], capture_output=True, check=True)
        assert check.stdout == b'Preserved draft'
        print('PASS deployment serves the new release and retains config, storage and database')
        failed = subprocess.run(command, input=bundle('b' * 40, broken=True), env=env, capture_output=True, timeout=60)
        assert failed.returncode != 0
        assert (root / 'current').resolve() == active
        assert b'previous release restored' in failed.stderr
        assert (root / 'config.php').read_bytes() == config_before
        print('PASS HTTP failure restores the previous release')
        rejected = subprocess.run(command, input=bundle('c' * 40, private=True), env=env, capture_output=True, timeout=60)
        assert rejected.returncode != 0
        assert (root / 'current').resolve() == active
        assert (root / 'config.php').read_bytes() == config_before
        print('PASS private configuration in an archive is rejected before activation')
        with (root / 'storage/worker.lock').open('a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            archive = root / 'next.tar.gz'; archive.write_bytes(bundle('d' * 40))
            with archive.open('rb') as incoming:
                waiting = subprocess.Popen(command, stdin=incoming, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                try:
                    time.sleep(1)
                    assert waiting.poll() is None
                    assert (root / 'current').resolve() == active
                finally:
                    fcntl.flock(lock, fcntl.LOCK_UN)
                out, err = waiting.communicate(timeout=60)
                assert waiting.returncode == 0, (out, err)
        assert (root / 'current').resolve() != active
        print('PASS activation waits for an active publisher lock')
    finally:
        server.terminate()
        server.wait(timeout=5)
