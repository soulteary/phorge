"""Owned, bounded two-process native transaction tests (no runtime hooks)."""
import json
import subprocess
import time


def transactions(here, root, env, private, fixture, check):
    fixture('transaction-seed')
    for key,value in fixture('transaction-stale').items():check(value is True,'preloaded object revocation '+key)
    workers=[]
    def spawn(mode):
        log=open(private/(mode+'.txt'),'w')
        process=subprocess.Popen(['php',str(here/'route_fixture.php'),mode],cwd=root,env=env,stdout=log,stderr=log)
        workers.append((process,log,mode))
        return process
    def wait_for(predicate, label):
        deadline=time.monotonic()+30
        while not predicate():
            if time.monotonic()>deadline:raise RuntimeError(label)
            if any(p.poll() not in (None,0) for p,_,_ in workers):raise RuntimeError('Native transaction worker failed.')
            time.sleep(.02)
    try:
        edit=spawn('transaction-hold')
        wait_for(lambda:(private/'transaction-ready').exists(),'Native edit barrier timed out.')
        revoke=spawn('transaction-revoke')
        wait_for(lambda:(private/'transaction-revoker').exists(),'Revoker connection timed out.')
        wait_for(lambda:fixture('transaction-lock-wait')['waiting'],'Revocation did not wait on native row lock.')
        check(edit.poll() is None and revoke.poll() is None,'concurrent revoke observed in database lock wait before edit release')
        (private/'transaction-release').write_text('release')
        for process,log,mode in workers:
            check(process.wait(timeout=40)==0,'native transaction worker completed '+mode)
            log.flush()
        for key,value in fixture('transaction-verify').items():check(value is True,'serialized revocation '+key)
        for key,value in fixture('transaction-rollback').items():check(value is True,'multi-transaction rollback '+key)
        for key,value in fixture('transaction-bulk').items():check(value is True,'bulk worker boundary '+key)
        for key,value in fixture('transaction-bulk-rollback').items():check(value is True,'bulk item rollback '+key)
        return {'independentProcesses':2,'databaseLockWaitObserved':True,'rollbackAfterObjectSave':True,'bulkRevocationAtExecution':True,'bulkItemRollback':True,'staleObjectReload':True}
    finally:
        (private/'transaction-release').touch()
        for process,log,_ in workers:
            if process.poll() is None:
                process.terminate()
                try:process.wait(timeout=5)
                except subprocess.TimeoutExpired:process.kill();process.wait()
            log.close()
