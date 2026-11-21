import socketserver,subprocess,selectors,os,threading,re
PHP_RELAY=r'''$s=stream_socket_client('tcp://127.0.0.1:33388',$n,$e,5);if(!$s){exit(1);}stream_set_blocking(STDIN,false);$input=true;while(true){$r=array($s);if($input){$r[]=STDIN;}$w=null;$x=null;if(stream_select($r,$w,$x,10)===false){exit(2);}foreach($r as$f){$d=fread($f,65536);if($d===''){if(feof($f)){if($f===$s){exit(0);}$input=false;stream_socket_shutdown($s,STREAM_SHUT_WR);}continue;}$target=$f===$s?STDOUT:$s;while($d!==''){$n=fwrite($target,$d);if($n===false||$n===0){exit(3);}$d=substr($d,$n);}}}'''
class Relay:
 def __init__(self,cid,port):
  if not isinstance(cid,str)or not re.fullmatch('[a-f0-9]{64}',cid)or type(port)is not int or not 1<=port<=65535:raise ValueError('exact fixture container and loopback port required')
  self.processes=set();self.lock=threading.Lock();owner=self
  class Handler(socketserver.BaseRequestHandler):
   def handle(self):
    p=subprocess.Popen(['docker','exec','-i',cid,'php','-r',PHP_RELAY],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL)
    with owner.lock:owner.processes.add(p)
    sel=selectors.DefaultSelector();sel.register(self.request,selectors.EVENT_READ,'client');sel.register(p.stdout,selectors.EVENT_READ,'server')
    try:
     while True:
      ready=sel.select(60)
      if not ready:break
      for key,_ in ready:
       if key.data=='client':
        data=self.request.recv(65536)
        if not data:
         sel.unregister(self.request);p.stdin.close();continue
        p.stdin.write(data);p.stdin.flush()
       else:
        data=os.read(p.stdout.fileno(),65536)
        if not data:return
        self.request.sendall(data)
    except (OSError,ValueError):pass
    finally:
     sel.close()
     if p.poll()is None:p.terminate()
     try:p.wait(timeout=3)
     except subprocess.TimeoutExpired:p.kill();p.wait(timeout=3)
     with owner.lock:owner.processes.discard(p)
  class Server(socketserver.ThreadingTCPServer):
   allow_reuse_address=False;daemon_threads=True
   def handle_error(self,*args):pass
  self.server=Server(('127.0.0.1',port),Handler);self.thread=threading.Thread(target=self.server.serve_forever,daemon=True)
 def start(self):self.thread.start()
 def close(self):
  self.server.shutdown();self.server.server_close();self.thread.join(timeout=3)
  with self.lock:rows=list(self.processes)
  for p in rows:
   if p.poll()is None:p.terminate()
  for p in rows:
   try:p.wait(timeout=3)
   except subprocess.TimeoutExpired:p.kill();p.wait(timeout=3)
