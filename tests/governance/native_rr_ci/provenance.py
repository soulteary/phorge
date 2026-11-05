"""Data-only verification of an actual owner-helper build; not an execution permit."""
import hashlib,json,re
OWNER_FILES=tuple('support/governance/recovery/owned-process/'+name for name in ('go.mod','go.sum','main_linux.go','request.go','request_test.go'))
RECIPE_FILES=('tests/governance/native_rr_ci/build_owner.py','tests/governance/native_rr_ci/provenance.py','.github/workflows/native-rr-owner-build.yml')
MODULE='phorge-isolated-native-rr-owner'
DEP={'Path':'golang.org/x/sys','Version':'v0.49.0','Sum':'h1:XbzkgYJHdqh/8m2Uu0W/dQv8nktxx4BFHp1M0gROTXA='}
def digest(raw):return hashlib.sha256(raw).hexdigest()
def strict_object(pairs):
 result={}
 for key,value in pairs:
  if key in result:raise ValueError('duplicate field')
  result[key]=value
 return result
def decode(raw):return json.loads(raw,object_pairs_hook=strict_object,parse_constant=lambda _:(_ for _ in ()).throw(ValueError('nonfinite field')))
def verify_build_info(info,revision,arch):
 if type(revision)is not str or not re.fullmatch('[a-f0-9]{40}',revision)or arch not in ('amd64','arm64'):raise ValueError('build identity refused')
 if type(info)is not dict or info.get('GoVersion')!='go1.27.3' or info.get('Path')!=MODULE or info.get('Main')!={'Path':MODULE,'Version':'(devel)'}or info.get('Deps')!=[DEP]:raise ValueError('module provenance refused')
 settings=info.get('Settings')
 if type(settings)is not list or any(type(r)is not dict or set(r)!={'Key','Value'}or type(r['Key'])is not str or type(r['Value'])is not str for r in settings):raise ValueError('build settings refused')
 values={r['Key']:r['Value']for r in settings}
 if len(values)!=len(settings):raise ValueError('duplicate build setting')
 required={'-buildmode':'exe','-compiler':'gc','-trimpath':'true','CGO_ENABLED':'0','GOARCH':arch,'GOOS':'linux','vcs':'git','vcs.revision':revision,'vcs.modified':'false'}
 if any(values.get(k)!=v for k,v in required.items())or 'vcs.time'not in values:raise ValueError('actual build provenance refused')
 if set(values)-set(required)-{'vcs.time','GOAMD64','GOARM64'}:raise ValueError('unreviewed build setting')
 if values.get('GOAMD64','v1')!='v1' or values.get('GOARM64','v8.0')!='v8.0':raise ValueError('architecture profile refused')
 return {'revision':revision,'arch':arch,'go':'go1.27.3','CGO_ENABLED':0,'modified':False}
def source_bindings(root,read_committed):
 result={}
 for path in OWNER_FILES+RECIPE_FILES:
  p=root/path
  if p.is_symlink()or not p.is_file():raise ValueError('regular signed source required')
  raw=p.read_bytes()
  if raw!=read_committed(path):raise ValueError('source differs from current commit')
  result[path]=digest(raw)
 return result
