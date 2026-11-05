"""Fixture parsing only. These host tests do not send mail or prove native auth."""
import json,os,secrets,shutil,subprocess,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
CONFIG=ROOT/'support/governance/notification/notice-wire-config.php'
class NoticeWireParsingTests(unittest.TestCase):
    def php(self,code,*args):
        result=subprocess.run(['php','-r','require $argv[1];'+code,str(CONFIG),*map(str,args)],capture_output=True,text=True)
        self.assertEqual(0,result.returncode,result.stderr);self.assertEqual('',result.stderr)
        return json.loads(result.stdout)
    def test_real_native_override_API_installs_each_fixed_key_without_SQL(self):
        code="""require $argv[1].'/support/runtime/src/init/init-library.php';phutil_load_library($argv[1].'/src/');
require $argv[1].'/support/governance/notification/notice-wire-config.php';
$stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDictionarySource(array()));
(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);
try{PhabricatorEnv::overrideConfig(array('governance.notifications.enabled'=>true));exit(9);}catch(ArgumentCountError$expected){}
$notice=array('queue_uri'=>'http://127.0.0.1:12345','queue_token'=>str_repeat('a',64));
governance_notice_wire_apply_overrides($notice);
$keys=array('governance.notifications.enabled','governance.organization.enabled','governance.account-recovery.enabled',
'governance.recovery-stop-ledger.enabled','gorge.taskqueue.owner','gorge.taskqueue.uri','gorge.taskqueue.token','gorge.service-policies');
$out=array();foreach($keys as$key){$out[$key]=PhabricatorEnv::getEnvConfig($key);}
echo json_encode($out,JSON_THROW_ON_ERROR);"""
        result=subprocess.run(['php','-r',code,str(ROOT)],capture_output=True,text=True,check=True)
        self.assertEqual('',result.stderr);value=json.loads(result.stdout)
        self.assertIs(value['governance.notifications.enabled'],True)
        self.assertIs(value['governance.organization.enabled'],True)
        self.assertIs(value['governance.account-recovery.enabled'],False)
        self.assertIs(value['governance.recovery-stop-ledger.enabled'],False)
        self.assertEqual('gorge',value['gorge.taskqueue.owner'])
        self.assertEqual('http://127.0.0.1:12345',value['gorge.taskqueue.uri'])
        self.assertEqual('a'*64,value['gorge.taskqueue.token'])
        self.assertEqual({k:'required'for k in ('file','mailer','conduit','taskqueue')},value['gorge.service-policies'])
    def test_case_selector_is_fixed_and_never_a_role_or_recipient_grant(self):
        result=self.php("""$out=[];foreach(array('allow','admin-revoked','between-attempts','config-notice-error',
          'config-private-mail-error',true,null,[],['allowed'=>true],'allow?recipient=other','unknown')as$v){
          try{governance_notice_wire_case($v);$out[]=true;}catch(RuntimeException$e){$out[]=false;}}
          echo json_encode($out);""")
        self.assertEqual([True]*5+[False]*6,result)
    def test_private_real_parser_refuses_foreign_origin_token_case_and_extra_authority(self):
        directory=Path(tempfile.gettempdir()).resolve()/('phorge-private-mail-wire-'+secrets.token_hex(12));directory.mkdir(mode=0o700)
        def write(name,value):
            p=directory/name;p.write_text(value if isinstance(value,str) else json.dumps(value));p.chmod(0o600)
        owner='a'*64
        base={'owner':owner,'fixture_nonce':'b'*64,'source_sha256':'c'*64,'writer':{'host':'127.0.0.1','port':3306,
          'namespace':'governance_native_'+'d'*16,'user':'gov_c_writer_'+'e'*12,'password':'f'*48},
          'callback_origin':'http://127.0.0.1:12345','service_token':'1'*64,'mailer_uri':'http://127.0.0.1:12346',
          'mailer_token':'2'*64,'file_uri':'http://127.0.0.1:12347','file_token':'3'*64}
        extra={'owner':owner,'case':'allow','queue_uri':'http://127.0.0.1:12348','queue_token':'4'*64,'stable_capture_version':'v0.14.0'}
        read="try{governance_notice_wire_config($argv[2]);echo 'true';}catch(RuntimeException$e){echo 'false';}"
        try:
            write('owner',owner);write('router.json',base);write('notice.json',extra)
            self.assertIs(self.php(read,directory),True)
            for delta in ({'owner':'5'*64},{'queue_uri':'http://localhost:12348'},
              {'queue_uri':'http://127.0.0.1:65536'},{'queue_uri':'https://public.example.invalid/'},
              {'queue_token':'A'*64},{'case':'unknown'},{'stable_capture_version':'v0.14.0-alpha.1'},
              {'allowed':True},{'recipient':'arbitrary'}):
                write('notice.json',{**extra,**delta});self.assertIs(self.php(read,directory),False)
            write('notice.json',extra);(directory/'notice.json').chmod(0o644)
            self.assertIs(self.php(read,directory),False)
            (directory/'notice.json').unlink();(directory/'notice.json').symlink_to(directory/'router.json')
            self.assertIs(self.php(read,directory),False)
        finally:shutil.rmtree(directory)
if __name__=='__main__':unittest.main()
