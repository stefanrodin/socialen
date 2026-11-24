#!/usr/bin/env python3
"""Isolated CLI/SSH protocol tests. No external service or production database."""
from datetime import datetime, timedelta
import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
from zoneinfo import ZoneInfo

source = Path(__file__).resolve().parents[1]
php = shutil.which("php")
with tempfile.TemporaryDirectory(prefix="socialen-admin-test-") as directory:
    root = Path(directory)
    for name in ("src", "bin"):
        shutil.copytree(source / name, root / name)
    (root / "storage").mkdir(mode=0o700)
    key = __import__("base64").b64encode(os.urandom(32)).decode()
    (root / "config.php").write_text("<?php return ['url'=>'https://socialen.example','timezone'=>'Europe/Stockholm','key'=>'%s','database'=>['dsn'=>'sqlite:%s/storage/test.sqlite','user'=>null,'password'=>null],'upload_limit_mb'=>1,'google'=>[],'meta'=>[]];" % (key, root))

    def php_code(code):
        return subprocess.run([php, "-r", "require 'src/bootstrap.php'; " + code], cwd=root, check=True, capture_output=True)

    php_code("$a=app();$a->migrate();$a->query('INSERT INTO users(email,name,password_hash,created_at) VALUES(?,?,?,?)',['test@example.se','Test',password_hash('test-only',PASSWORD_DEFAULT),$a->now()]);$a->query('INSERT INTO channels(network,external_id,name,credentials,connected_at) VALUES(?,?,?,?,?)',['facebook','test-page','Styverz',$a->seal(['access_token'=>'never-expose-token']),$a->now()]);$i=imagecreatetruecolor(32,32);imagejpeg($i,'storage/fixture.jpg');")
    db = sqlite3.connect(root / "storage/test.sqlite")

    def sql(query, args=()):
        result = db.execute(query, args).fetchall()
        db.commit()
        return result

    def count(table):
        return sql("SELECT COUNT(*) FROM " + table)[0][0]

    def call(req, data=b"", ok=True):
        proc = subprocess.run([php, str(root / "bin/admin.php")], input=json.dumps(req).encode()+b"\n"+data, capture_output=True)
        result = json.loads(proc.stdout)
        assert result["ok"] is ok, (req, result, proc.stderr.decode())
        assert (proc.returncode == 0) is ok
        assert key.encode() not in proc.stdout and b"never-expose-token" not in proc.stdout
        return result.get("result", result)

    # No CLI command can silently accept policies on behalf of an administrator.
    call({"action": "health"}, ok=False)
    php_code("$a=app();$a->query('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)',['policies_user_1',Policies::VERSION]);")
    assert call({"action": "health"})["posts"] == 0
    call({"action": "health", "actor_id": 99}, ok=False)
    assert call({"action": "channels"})["channels"][0]["name"] == "Styverz"

    future = (datetime.now(ZoneInfo("Europe/Stockholm"))+timedelta(days=2)).replace(second=0, microsecond=0)
    manifest = {"title":"Käft och rock", "caption":"Åäö 🍻 $(touch SHOULD_NOT_EXIST) `whoami`", "channel_ids":[1], "media_ids":[], "status":"scheduled", "scheduled_at":future.strftime("%Y-%m-%dT%H:%M"), "timezone":"Europe/Stockholm"}
    req = {"action":"save", "request_id":"test-create-0001", "post":manifest}
    assert call(req)["validated"] is True
    assert count("posts") == count("publications") == 0
    for bad in ({**manifest, "typo":1}, {**manifest, "scheduled_at":"2027-03-28T02:30"}, {**manifest, "scheduled_at":"2027-10-31T02:30"}, {**manifest, "channel_ids":[99]}):
        call({**req, "post":bad}, ok=False)
    saved = call({**req, "apply":True})["post"]
    pid = int(saved["id"])
    assert saved["caption"] == manifest["caption"] and int(saved["version"]) == 1
    assert call({**req, "apply":True})["replayed"] is True
    assert count("posts") == count("publications") == 1
    call({**req, "apply":True, "post":{**manifest,"caption":"different"}}, ok=False)
    assert len(call({"action":"posts","network":"facebook","limit":1})["posts"]) == 1
    assert call({"action":"posts","network":"youtube"})["posts"] == []
    assert call({"action":"show","id":pid})["post"]["scheduled_local"] == manifest["scheduled_at"]
    later = (future+timedelta(hours=1)).strftime("%Y-%m-%dT%H:%M")
    move = {"action":"reschedule","id":pid,"version":1,"scheduled_at":later,"request_id":"test-move-0001"}
    assert call(move)["applied"] is False
    assert call({"action":"show","id":pid})["post"]["scheduled_local"] == manifest["scheduled_at"]
    changed = call({**move,"apply":True})["post"]
    assert changed["scheduled_local"] == later and int(changed["version"]) == 2
    assert changed["caption"] == manifest["caption"]
    assert call({**move,"apply":True})["replayed"] is True
    call({**move,"apply":True,"request_id":"test-stale-0001"}, ok=False)
    draft = {"action":"draft","id":pid,"version":2,"request_id":"test-draft-0001"}
    assert call(draft)["applied"] is False
    assert call({**draft,"apply":True})["post"]["status"] == "draft"
    sql("UPDATE publications SET state_json=? WHERE post_id=?", ('{"started":true}',pid))
    call({"action":"save","request_id":"test-started-0001","post":{**manifest,"id":pid,"version":3}}, ok=False)
    sql("UPDATE publications SET state_json='{}' WHERE post_id=?", (pid,))

    # The receipt is committed atomically with its post, including callback failures.
    before = count("posts")
    php_code("$a=app();try{$a->savePost(['title'=>'Rollback','status'=>'draft'],false,function(){throw new UserError('test failure');});}catch(UserError $e){}")
    assert count("posts") == before

    body = (root / "storage/fixture.jpg").read_bytes()
    sha = hashlib.sha256(body).hexdigest()
    upload = {"action":"upload","name":"fixture.jpg","bytes":len(body),"sha256":sha}
    assert call(upload)["validation"] == "metadata_only" and count("media") == 0
    call({**upload,"bytes":1048577}, ok=False)
    call({**upload,"name":"../config.php"}, ok=False)
    call({**upload,"apply":True,"sha256":"0"*64}, body, ok=False)
    call({**upload,"apply":True}, body[:-1], ok=False)
    call({**upload,"apply":True}, body+b"extra", ok=False)
    assert count("media") == 0 and not list((root/"storage/cli-staging").iterdir())
    uploaded = call({**upload,"apply":True},body)
    mid = int(uploaded["media"]["id"])
    assert "path" not in uploaded["media"] and "public_key" not in uploaded["media"]
    assert call({**upload,"apply":True},body)["deduplicated"] is True and count("media") == 1
    stored = root / "storage/uploads" / sql("SELECT path FROM media WHERE id=?",(mid,))[0][0]
    assert stored.stat().st_mode & 0o777 == 0o600
    imported = php_code("$a=app();try{$a->importMedia('storage/fixture.jpg','outside.jpg');exit(1);}catch(UserError $e){}")
    assert imported.returncode == 0
    # An existing MP4 uploaded by the web interface can be reused by content hash.
    mp4 = b"\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom"
    (root/"storage/uploads/existing.mp4").write_bytes(mp4)
    sql("INSERT INTO media(name,path,mime,bytes,public_key,created_at) VALUES(?,?,?,?,?,?)",("existing.mp4","existing.mp4","video/mp4",len(mp4),"a"*64,"2026-01-01 00:00:00"))
    existing = call({"action":"upload","name":"same.mp4","bytes":len(mp4),"sha256":hashlib.sha256(mp4).hexdigest(),"apply":True},mp4)
    assert existing["deduplicated"] and count("media") == 2
    new_mp4 = mp4 + b"test-video-payload"
    new_upload = {"action":"upload","name":"new.mp4","bytes":len(new_mp4),"sha256":hashlib.sha256(new_mp4).hexdigest(),"apply":True}
    video_result = call(new_upload,new_mp4)
    assert not video_result["deduplicated"] and count("media") == 3
    video_path = sql("SELECT path FROM media WHERE id=?",(video_result["media"]["id"],))[0][0]
    assert (root/"storage/uploads"/video_path).read_bytes() == new_mp4
    assert call(new_upload,new_mp4)["deduplicated"] and count("media") == 3

    # Exercise the actual Python client with an SSH stand-in, including streaming.
    fake = root / "fake-bin"
    fake.mkdir()
    (fake/"ssh").write_text("#!/usr/bin/env python3\nimport json,os,sys\nfrom pathlib import Path\nPath(os.environ['FAKE_SSH_ARGS']).write_text(json.dumps(sys.argv[1:]))\nos.execv(os.environ['TEST_PHP'],[os.environ['TEST_PHP'],os.environ['TEST_ADMIN']])\n")
    (fake/"ssh").chmod(0o700)
    cfg = root / "ssh.json"
    cfg.write_text(json.dumps({"host":"test.example","user":"test","app_root":str(root),"php":php,"port":2020}))
    env = {**os.environ,"PATH":str(fake)+os.pathsep+os.environ["PATH"],"FAKE_SSH_ARGS":str(root/"ssh-args.json"),"TEST_PHP":php,"TEST_ADMIN":str(root/"bin/admin.php")}
    def client(*args):
        return subprocess.run([sys.executable,str(source/"bin/socialen.py"),"--config",str(cfg),*args],cwd=root,env=env,capture_output=True)
    import sys
    result = client("health")
    assert result.returncode == 0 and json.loads(result.stdout)["ok"]
    result = client("upload",str(root/"storage/fixture.jpg"),"--apply")
    assert result.returncode == 0 and json.loads(result.stdout)["result"]["deduplicated"]
    manifest_path = root/"post.json"
    manifest_path.write_text(json.dumps({**manifest,"title":"Client draft","status":"draft"}))
    before = count("posts")
    assert client("save",str(manifest_path)).returncode == 0 and count("posts") == before
    assert client("save",str(manifest_path),"--apply").returncode == 0 and count("posts") == before+1
    assert client("save",str(manifest_path),"--apply").returncode == 0 and count("posts") == before+1
    args = json.loads((root/"ssh-args.json").read_text())
    assert "StrictHostKeyChecking=yes" in args and "BatchMode=yes" in args
    assert not any("SHOULD_NOT_EXIST" in arg for arg in args) and not (root/"SHOULD_NOT_EXIST").exists()
    # Pagination has no duplicates and does not claim completeness when truncated.
    first = call({"action":"posts","limit":1})
    assert first["has_more"]
    second = call({"action":"posts","limit":1,"before_id":first["next_before_id"]})
    assert first["posts"][0]["id"] != second["posts"][0]["id"]
    print("PASS isolated admin protocol, previews, idempotency, conflicts, uploads, SSH client and pagination")
