"""Validate the candidate adoption snapshot without interpreting disposition as pass."""
from collections import Counter
from pathlib import Path
import json,re
EXPECTED={'已完成':42,'保留原生':6,'未实现':4,'暂缓':82}
def validate(data,design):
 atoms=data['atoms'];ids=[a['id'] for a in atoms]
 if len(atoms)!=134 or len(set(ids))!=134:raise ValueError('134 unique atomic records required')
 if Counter(a['category'] for a in atoms)!=EXPECTED:raise ValueError('Adoption partition drift requires explicit review')
 if {a['id'] for a in atoms if a['category']=='未实现'}!={'M01','M02','M03','M04'}:raise ValueError('Native axes must not be relabelled as adopted')
 if {a['id'] for a in atoms if a['category']=='保留原生'}!={'B04','M07','M05','M06','M08','M09'}:raise ValueError('Native scope mismatch')
 deferred={'D'+str(n).zfill(2) for n in range(8,20)}
 if set(data['constraints']['deferredPackages'])!=deferred or any(a['category']!='暂缓' for a in atoms if a['package'] in deferred):raise ValueError('Deferred package adopted without decision')
 if data['constraints']!={'permissionAxes':'native','optionalFeaturesEnabled':False,'productionPermissionMigration':False,'deferredPackages':data['constraints']['deferredPackages']}:raise ValueError('Runtime scope changed')
 anchors=[c for a in atoms for c in a['caseIds']]
 if len(anchors)!=268 or len(set(anchors))!=268 or any(a['wholeDesignedCasesCertified'] is not False for a in atoms):raise ValueError('Design anchors must not become pass certificates')
 if {a['id'] for a in atoms if a['category']=='已完成'}!={c['atom'] for c in design['cases']}:raise ValueError('Delivered package evidence mismatch')
 expected={'atoms':134,'anchors':268,'packages':19,'delivered':42,'nativeToolingOrCompatibility':6,'unselectedModelChanges':4,'deferred':82,'candidateChangesUnintegrated':86}
 if data['counts']!=expected or len({a['package'] for a in atoms})!=19:raise ValueError('Count mismatch')
 if any(a['candidateChangeUnintegrated']!=(a['category'] in ('未实现','暂缓')) for a in atoms):raise ValueError('Per-atom implementation status contradicts disposition')
 if sum(a['candidateChangeUnintegrated'] for a in atoms)!=86:raise ValueError('Implementation and deferral overlap miscounted')
 source={p['number']:p['head'] for p in data['sourcePRs']}
 if set(source)!={47,48,49,50} or any(not re.fullmatch('[0-9a-f]{40}',v) for v in source.values()):raise ValueError('Fixed source revisions required')
 caps=data['capabilities']
 if len(caps)!=53 or len({c['id'] for c in caps})!=53:raise ValueError('53 original capability mappings required')
 for cap in caps:
  if not cap['atoms'] or not set(cap['atoms'])<=set(ids) or cap['sourceRevision']!=source[cap['sourcePR']]:raise ValueError('Dangling or stale capability mapping')
 covered={a for c in caps for a in c['atoms']};cross=set(data['crossCuttingAtomsWithoutDirectCapability'])
 if cross!={'B01','B03','B04','B07'} or covered & cross or covered|cross!=set(ids):raise ValueError('Capability and cross-cutting coverage mismatch')
 return {'atoms':134,'anchors':268,'capabilities':53,'candidateChangesUnintegrated':86}
if __name__=='__main__':
 root=Path(__file__).resolve().parents[2]
 print(json.dumps(validate(json.loads((root/'docs/upstream/candidate-adoption.json').read_text()),json.loads((root/'tests/atomic/design-coverage.json').read_text()))))
