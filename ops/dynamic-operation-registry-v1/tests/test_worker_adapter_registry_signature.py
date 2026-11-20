from pathlib import Path
import inspect,sys,unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import registry, worker_adapter
class RegistryProtectedFileInterfaceTest(unittest.TestCase):
    def test_checked_handler_uses_declared_registry_api(self):
        signature=inspect.signature(registry.protected_file)
        self.assertIn("maximum",signature.parameters)
        worker=inspect.getsource(worker_adapter.execute_claimed)
        self.assertIn("handler,index.root,maximum=2_000_000",worker)
        self.assertNotIn("max_bytes=",worker)
    def test_reservation_still_before_handler(self):
        worker=inspect.getsource(worker_adapter.execute_claimed)
        self.assertLess(worker.index("os.open(reservation"),worker.index("subprocess.run([sys.executable,str(handler)"))
if __name__=="__main__":unittest.main()
