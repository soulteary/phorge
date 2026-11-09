"""Execute the exact fixed cleanup prefix with explicit inert collaborators."""
import json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[3]
class CleanupOrder(unittest.TestCase):
 def test_actual_prefix_closes_two_distinct_writer_handles_in_order(self):
  s=(ROOT/'support/governance/media-session-positive/run.php').read_text();a=s.index('unset($guard);LiskDAO::closeAllConnections();');b=s.index('$deadline=',a);body=s[a:b]
  setup="class LiskDAO{static function closeAllConnections(){$GLOBALS['events'][]='native-pool';}}class Fixture{function closeOwnedWriterConnectionForHTTP(){$GLOBALS['events'][]='fixture-writer';return array('closed_thread_private'=>'private-canary');}}$events=array();$guard=null;$fixture=new Fixture();"
  p=subprocess.run(['php','-r',setup+body+'echo json_encode($events);'],capture_output=True,text=True,timeout=5)
  self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stderr,'');self.assertEqual(json.loads(p.stdout),['native-pool','fixture-writer']);self.assertNotIn('private-canary',p.stdout)
 def test_all_writer_zero_and_MDL_barriers_remain_after_prefix(self):
  s=(ROOT/'support/governance/media-session-positive/run.php').read_text();self.assertLess(s.index('$fixture->closeOwnedWriterConnectionForHTTP();'),s.index('information_schema.PROCESSLIST'))
  self.assertLess(s.index('information_schema.PROCESSLIST'),s.index('performance_schema.metadata_locks'));self.assertLess(s.index('performance_schema.metadata_locks'),s.index('$fixture->cleanup()'))
if __name__=='__main__':unittest.main()
