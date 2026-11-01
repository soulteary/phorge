"""Independent Go decoding of real isolated native and HTTP XLSX exports."""
import json,subprocess
from pathlib import Path


def execute(root,env,private,check):
    binary=Path(env['PERMISSION_XLSX_GO_BINARY']).resolve()
    if not binary.is_file() or root in binary.parents:raise ValueError('External isolated Go reader required.')
    state=json.loads((private/'export-state.json').read_text());loaded,unloaded=state['revoked']
    specs=[('owner.xlsx','owner','native',2),('outsider.xlsx','outsider','native',1),('owner-http.xlsx','owner','http',2),('outsider-http.xlsx','outsider','http',1),('mid-export.xlsx','outsider','mid',1001),('fresh-export.xlsx','outsider','fresh',1000)]
    for filename,role,kind,count in specs:
        r=subprocess.run([str(binary),str(private/filename)],env=env,capture_output=True,text=True,timeout=30)
        if r.returncode:raise RuntimeError('Owned Go XLSX decoder failed: '+kind+' '+role)
        parsed=json.loads(r.stdout);rows=parsed['rows']
        keys=json.loads((private/(role+'-columns.json')).read_text()) if kind=='native' else state['columns'][role]
        maps=[{c['column']:c for c in row['cells']} for row in rows];title_column=keys.index('title')+1;date_column=keys.index('dateCreated')+1;owner_column=keys.index('ownerPHID')+1;status_column=keys.index('status')+1
        check(parsed['goVersion']=='go1.27.3','Go XLSX exact reader runtime '+filename)
        check([row['index'] for row in rows]==list(range(1,count+2)),'Go XLSX exact row coordinates '+filename)
        check(sorted(maps[0])==list(range(1,len(keys)+1)),'Go XLSX header coordinates '+filename)
        check(parsed['formulaCells']==0 and all(not c['hasFormula'] for row in rows for c in row['cells']),'Go XLSX formula-free export '+filename)
        titles=[row[title_column]['value'] for row in maps[1:]]
        if kind=='native':
            prefixes=['=1+2 public canary']+(['Private XLSX canary'] if role=='owner' else [])
            wanted=len(titles)==len(prefixes) and all(sum(t.startswith(prefix) for t in titles)==1 for prefix in prefixes)
        elif kind=='http':wanted=set(titles)==({'HTTP XLSX visible canary','HTTP XLSX private canary'} if role=='owner' else {'HTTP XLSX visible canary'})
        else:
            omitted={unloaded} if kind=='mid' else {loaded,unloaded}
            expected={'Mid-export row '+str(i) for i,task_id in enumerate(state['rows']) if task_id not in omitted}
            wanted=len(titles)==len(expected) and set(titles)==expected
        check(wanted,'Go XLSX exact viewer/revocation title set '+filename)
        check(all(row[title_column]['type'] in ('s','str','inlineStr') and not row[title_column]['numeric'] for row in maps[1:]),'Go XLSX titles remain literal strings '+filename)
        check(all(row[date_column]['numeric'] and row[date_column]['dateFormatted'] for row in maps[1:]),'Go XLSX numeric date formatting '+filename)
        check(all(row.get(owner_column,{}).get('value','')=='' and row[status_column]['value']!='' for row in maps[1:]),'Go XLSX sparse columns preserve status '+filename)
    return {'engine':'go-stdlib-ooxml-v1','goVersion':'go1.27.3','workbooks':6,'assertions':48,'dataRows':[2,1,2,1,1001,1000],'thirdPartyModules':0,'softwareAcceptanceRequired':False,'productionDependenciesChanged':False}


def validate(report):
    expected={'engine':'go-stdlib-ooxml-v1','goVersion':'go1.27.3','workbooks':6,'assertions':48,'dataRows':[2,1,2,1,1001,1000],'thirdPartyModules':0,'softwareAcceptanceRequired':False,'productionDependenciesChanged':False}
    if report.get('boundarySchemaVersion')!=7 or report.get('goXLSXCoverage')!=expected:raise ValueError('Complete isolated Go XLSX evidence required.')
    return True
