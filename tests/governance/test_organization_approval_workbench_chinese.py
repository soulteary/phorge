"""Actual maintained dictionary and native zh_CN translator; no HTTP acceptance."""
from pathlib import Path
import json,re,subprocess,unittest
ROOT=Path(__file__).resolve().parents[2]
class Chinese(unittest.TestCase):
 def test_all_workbench_prompts_translate_through_real_zh_CN_environment(self):
  source=(ROOT/'src/applications/people/governance/controller/PhorgeOrganizationApprovalController.php').read_text()
  keys=list(dict.fromkeys(re.findall(r"pht\('((?:[^'\\]|\\.)*)'",source)))
  keys+=['Open Approval Request','Compound Approval Workbench','A different current system administrator must approve this request. The requester then executes it.']
  code=r"""require$argv[1].'/support/runtime/src/__phutil_library_init__.php';phutil_load_library($argv[1].'/src');$stack=new PhabricatorConfigStackSource();$stack->pushSource(new PhabricatorConfigDefaultSource());$stack->pushSource(new PhabricatorConfigDictionarySource(array('translation.override'=>array())));(new ReflectionProperty(PhabricatorEnv::class,'sourceStack'))->setValue(null,$stack);(new ReflectionProperty(PhabricatorEnv::class,'cache'))->setValue(null,array());PhabricatorEnv::setLocaleCode('zh_CN');$keys=json_decode($argv[2],true);$map=(new PhabricatorChineseTranslation())->getTranslationMap();$out=array();foreach($keys as$key){$args=array_merge(array($key),array_fill(0,substr_count($key,'%s'),'SYNTHETIC'));$out[]=array('source'=>$key,'dictionary'=>array_key_exists($key,$map)?$map[$key]:null,'translated'=>call_user_func_array('pht',$args));}echo json_encode(array('locale'=>PhabricatorEnv::getLocaleCode(),'translator_locale'=>(new ReflectionProperty(PhutilTranslator::class,'localeCode'))->getValue(PhutilTranslator::getInstance()),'values'=>$out));"""
  p=subprocess.run(['php','-r',code,str(ROOT),json.dumps(keys)],capture_output=True,text=True,timeout=15);self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');d=json.loads(p.stdout);self.assertEqual(d['locale'],'zh_CN');self.assertEqual(d['translator_locale'],'zh_CN')
  self.assertEqual(len(d['values']),len(keys))
  for item in d['values']:
   with self.subTest(source=item['source']):self.assertIsInstance(item['dictionary'],str);self.assertTrue(item['dictionary']);self.assertNotEqual(item['source'],item['translated']);self.assertRegex(item['translated'],r'[\u4e00-\u9fff]');self.assertEqual(item['source'].count('%s'),item['dictionary'].count('%s'))
  rendered={v['source']:v['translated']for v in d['values']};self.assertEqual(rendered['Approve Original Request'],'批准原始请求');self.assertEqual(rendered['Approved, Not Executed'],'已批准，尚未执行');self.assertIn('不代表审批结果或执行许可',rendered['Current eligibility is rechecked on every POST. Neither a displayed action nor this page is an approval or an execution permit.'])
if __name__=='__main__':unittest.main()
