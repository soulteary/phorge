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
