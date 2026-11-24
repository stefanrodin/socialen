#!/usr/bin/env python3
"""Exercise configuration updates against a shared config symlink, never production."""
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile

source=Path(__file__).resolve().parent.parent
with tempfile.TemporaryDirectory(prefix='socialen-meta-config-') as directory:
    root=Path(directory)
    release=root/'release'
    (release/'bin').mkdir(parents=True)
    (root/'storage').mkdir(mode=0o700)
    shutil.copyfile(source/'bin/configure-meta.php',release/'bin/configure-meta.php')
    config=root/'config.php'
    original="<?php return ['google'=>['client_id'=>'keep-google','client_secret'=>'keep-secret'],'database'=>['dsn'=>'keep-database'],'key'=>'keep-key','meta'=>['version'=>'v27.0','custom'=>'keep-option']];"
    config.write_text(original)
    config.chmod(0o600)
    (release/'config.php').symlink_to(config)
    def run(secret='a'*32,login='1761148868517852'):
        return subprocess.run(['php',str(release/'bin/configure-meta.php')],input=f'4200581060239245\n{secret}\n{login}\n',text=True,capture_output=True)
    invalid=run(secret='invalid')
    assert invalid.returncode and config.read_text()==original
    assert not list((root/'storage').iterdir())
    result=run()
    assert result.returncode==0,result.stderr
    assert 'a'*32 not in result.stdout+result.stderr
    assert (release/'config.php').is_symlink()
    loaded=json.loads(subprocess.check_output(['php','-r','echo json_encode(require $argv[1]);',str(config)],text=True))
    assert loaded['google']=={'client_id':'keep-google','client_secret':'keep-secret'}
    assert loaded['database']['dsn']=='keep-database' and loaded['key']=='keep-key'
    assert loaded['meta']=={'version':'v27.0','custom':'keep-option','app_id':'4200581060239245','app_secret':'a'*32,'login_config_id':'1761148868517852'}
    backups=list((root/'storage').iterdir())
    assert len(backups)==1 and backups[0].read_text()==original
    assert stat.S_IMODE(config.stat().st_mode)==0o600
    assert stat.S_IMODE(backups[0].stat().st_mode)==0o600
    previous=config.read_bytes()
    assert run().returncode==0
    assert config.read_bytes()==previous and list((root/'storage').iterdir())==backups
    assert run(login='').returncode==0
    assert len(list((root/'storage').iterdir()))==2
print('PASS Meta setup: validates input, preserves shared config and unrelated settings, private backup, idempotent update, no secret output.')
