"""Additional native boundaries; never select an offline candidate model."""
import json
import urllib.parse
from route_spec import PROJECTS, SUBJECTS, expected
ACTIVE=set(SUBJECTS)-{'anonymous','disabled','unapproved'}


def members(alias, phase):
    if alias in ('grandparent','hiddenParent','openChild','hiddenMilestone'):
        return {'owner','member'} if phase=='restored' else {'owner'}
    if alias in ('legacyParent','legacyLeaf'):return {'owner'}
    if alias in ('memberParent','memberLeaf'):return {'owner','member'}
    return set()


def project_reads(data, phase, sessions, request, client, check):
    counts={'canonicalMemberSlugCells':0,'attachmentSubjects':0}
    projects=data['projects']
    for role in SUBJECTS:
        allowed=expected(role,phase)['view'];c=sessions[role]
        for alias,p in projects.items():
            uris=[f"/project/view/{p['id']}/",f"/project/members/{p['id']}/"]
            if p.get('slug'):uris.append('/tag/'+urllib.parse.quote(p['slug'],safe='')+'/')
            for n,uri in enumerate(uris):
                status,body=request(c,uri);counts['canonicalMemberSlugCells']+=1
                present=p['name'] in body or p['phid'] in body
                check(status<500 and present==(alias in allowed) and (alias not in allowed or status==200),
                      f'canonical-member-slug {phase} {role} {alias} {n}')
        after=None;seen={};pages=0
        while True:
            params={'constraints':{'phids':[p['phid'] for p in projects.values()]},'attachments':{'members':True,'ancestors':True},'limit':2}
            if after:params['after']=after
            if role!='anonymous':params['__conduit__']={'token':data['users'][role]['api']}
            status,body=request(client(),'/api/project.search',{'params':json.dumps(params)})
            response=json.loads(body);pages+=1
            if role not in ACTIVE:
                check(status==200 and response.get('error_code') is not None and response.get('result') is None,
                      f'API inactive admission {phase} {role}');break
            check(status==200 and response.get('error_code') is None,f'API attachment success {phase} {role} {pages}')
            result=response['result']
            for alias,p in projects.items():
                if alias not in allowed:check(p['name'] not in body and p['phid'] not in body,f'API hidden metadata {phase} {role} {alias} {pages}')
            for row in result['data']:
                check(row['phid'] not in seen,f'API pagination no duplicate {phase} {role} {pages}')
                seen[row['phid']]=row
                alias=next(a for a,p in projects.items() if p['phid']==row['phid'])
                actual={m['phid'] for m in row['attachments']['members']['members']}
                want={data['users'][r]['phid'] for r in members(alias,phase)}
                check(actual==want,f'API materialized member meaning {phase} {role} {alias}')
                for ancestor in row['attachments']['ancestors']['ancestors']:
                    check(ancestor['phid'] in {projects[a]['phid'] for a in allowed},f'API visible ancestors {phase} {role} {alias}')
            after=result['cursor']['after']
            if not after:break
            if pages>=20:raise RuntimeError('Bounded attachment pagination failed.')
        counts['attachmentSubjects']+=1
        check(set(seen)=={projects[a]['phid'] for a in allowed},f'API paginated visible inventory {phase} {role}')

    return counts

def effective_writes(data,phase,request,client,fixture,check):
    counts={'deniedWrites':0,'authorizedWrites':0}
    def edit(role,alias,value):
        params={'objectIdentifier':data['projects'][alias]['phid'],'transactions':[{'type':'name','value':value}],
                '__conduit__':{'token':data['users'][role]['api']}}
        status,body=request(client(),'/api/project.edit',{'params':json.dumps(params)})
        return status,json.loads(body)
    for role,alias in [('outsider','ordinary'),('parentEditor','privateSibling')]+([('parentEditor','ordinary')] if phase=='revoked' else []):
        before=fixture('write-state');status,response=edit(role,alias,'Forbidden boundary mutation')
        check(status==200 and response.get('error_code') is not None and 'permission' in response.get('error_info','').lower(),f'boundary denied edit {phase} {role} {alias}')
        counts['deniedWrites']+=1
        check(fixture('write-state')==before,f'denied edit zero business transaction {phase} {role} {alias}')
    alias='memberLeaf';old=data['projects'][alias]['name'];before=fixture('write-state')
    status,response=edit('owner',alias,old+' updated')
    check(status==200 and response.get('error_code') is None,f'authorized edit commits {phase}')
    counts['authorizedWrites']+=1
    changed=fixture('write-state')
    check(changed['fingerprint']!=before['fingerprint'] and changed['transactions']==before['transactions']+1,f'authorized edit persisted one transaction {phase}')
    status,response=edit('owner',alias,old)
    check(status==200 and response.get('error_code') is None and fixture('write-state')['transactions']==before['transactions']+2,f'authorized fixture name restored {phase}')

    counts['authorizedWrites']+=1
    return counts

def cross_object(data,extra,sessions,request,client,check):
    counts={'taskCells':0,'projectCells':0,'feedListCells':0}
    for role in SUBJECTS:
        active=role in ACTIVE;c=sessions[role]
        status,board=request(c,f"/project/board/{data['projects']['ordinary']['id']}/")
        check(status<500,'cross-object board response '+role)
        feed_status,feed_body=request(c,'/feed/')
        for kind,t in extra['tasks'].items():
            want=active and (kind=='public' or role=='owner')
            counts['taskCells']+=1
            counts['feedListCells']+=1
            check(feed_status<500 and (t['title'] in feed_body)==want,'task feed list '+role+' '+kind)
            check((t['title'] in board)==want,'board task permission '+role+' '+kind)
            for route,uri in [('task',f"/T{t['id']}"),('feed',f"/feed/{t['storyKey']}/?text=1")]:
                status,body=request(c,uri);counts['taskCells']+=1
                check(status<500 and (t['title'] in body)==want and (not want or status==200),'task story object '+role+' '+kind+' '+route)
        for kind,p in extra['projects'].items():
            want=active and (role=='owner' or (kind=='custom' and role=='childEditor'))
            counts['feedListCells']+=1
            check(feed_status<500 and (p['name'] in feed_body)==want,'project feed list '+role+' '+kind)
            for route in ('profile','view','board'):
                status,body=request(c,f"/project/{route}/{p['id']}/");counts['projectCells']+=1
                check(status<500 and (p['name'] in body or p['phid'] in body)==want and (not want or status==200),f'{kind} native {route} {role}')
            for route,uri in [('feed',f"/feed/{p['storyKey']}/?text=1"),
                              ('query','/project/?'+urllib.parse.urlencode({'name':'BoundaryProject'+data['token'],'isMilestone':''})),
                              ('search','/search/?'+urllib.parse.urlencode({'query':'BoundaryProject'+data['token'],'types':'PROJ'}))]:
                status,body=request(c,uri);counts['projectCells']+=1
                check(status<500 and (p['name'] in body or p['phid'] in body)==want and (not want or status==200),f'{kind} native {route} {role}')
            params={'constraints':{'phids':[p['phid']]},'attachments':{'members':True,'ancestors':True}}
            if role!='anonymous':params['__conduit__']={'token':data['users'][role]['api']}
            status,body=request(client(),'/api/project.search',{'params':json.dumps(params)})
            response=json.loads(body)
            counts['projectCells']+=1
            check(status==200 and (p['phid'] in body)==want,f'{kind} native API {role}')

    return counts

def validate_coverage(report):
    if report.get("boundarySchemaVersion")!=1:raise ValueError("Boundary evidence version required.")
    coverage=report.get("boundaryCoverage",{})
    if set(coverage)!={"restored","revoked","cross-object"}:raise ValueError("Missing boundary phase.")
    for phase in ("restored","revoked"):
        want={"canonicalMemberSlugCells":340,"attachmentSubjects":10,"deniedWrites":2 if phase=="restored" else 3,"authorizedWrites":2}
        if coverage[phase]!=want:raise ValueError("Incomplete native boundary coverage.")
    if coverage["cross-object"]!={"taskCells":60,"projectCells":140,"feedListCells":40}:raise ValueError("Incomplete cross-object boundaries.")
    if not report.get("boundaryChecks") or not all(c.get("passed") is True for c in report["boundaryChecks"]):raise ValueError("Boundary check failed.")
    return True
