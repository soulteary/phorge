#!/usr/bin/env python3
"""Exercise the actual functional PHP launcher without storage or sessions."""
import importlib.util
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('workspace_server', HERE/'serve.py')
DRIVER = importlib.util.module_from_spec(spec)
spec.loader.exec_module(DRIVER)


class FunctionalRuntimeTest(unittest.TestCase):
    def test_native_formatter_under_function_jit_host_configuration(self):
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 0)); port = probe.getsockname()[1]
        command = DRIVER.php_server_command(port, DRIVER.ROOT/'webroot',
                                            HERE/'runtime-probe.php')
        # Simulate a host configured to enable function JIT. The real launcher
        # must choose the deterministic functional-test mode after these flags.
        command[1:1] = ['-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1',
                        '-d', 'opcache.jit=function', '-d', 'opcache.jit_buffer_size=64M']
        env = dict(os.environ, ATOMIC_WORKSPACE_RUNTIME_PROBE='1')
        with tempfile.TemporaryFile() as log:
            server = subprocess.Popen(command, cwd=DRIVER.ROOT, env=env,
                                      stdout=log, stderr=subprocess.STDOUT)
            try:
                opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
                for _ in range(50):
                    try:
                        with opener.open(f'http://127.0.0.1:{port}/', timeout=10) as response:
                            result = json.load(response)
                        break
                    except urllib.error.HTTPError as error:
                        self.fail('The native formatter probe failed: '+error.read().decode())
                    except OSError:
                        if server.poll() is not None:
                            self.fail('The functional PHP server exited before startup.')
                        time.sleep(.1)
                else:
                    self.fail('The functional PHP server did not become ready.')
                self.assertTrue(result['passed'])
                self.assertEqual(4, result['checks'])
                self.assertEqual('cli-server', result['runtime']['sapi'])
                self.assertEqual('disable', result['runtime']['jit'])
                self.assertEqual('1', result['runtime']['opcacheEnabled'])
                self.assertEqual('1', result['runtime']['opcacheCLIEnabled'])
                self.assertEqual('64M', result['runtime']['jitBufferSize'])
            finally:
                server.terminate()
                try: server.wait(timeout=10)
                except subprocess.TimeoutExpired: server.kill(); server.wait()


if __name__ == '__main__':
    unittest.main()
