import unittest
from unittest.mock import patch
import resolver


class NativeRegistryNoWordPressUserTests(unittest.TestCase):
    def test_central_identity_registry_does_not_inject_wordpress_user(self):
        self.assertFalse(any(x=='--user' or x.startswith('--user=') for x in resolver.LOCAL))
        self.assertIn('--skip-themes',resolver.LOCAL)
    def test_central_registered_company_read_stays_readonly(self):
        output='[{"company_id":"egypt-tour-gates","site_id":"primary","environment":"staging","site_uuid":"d745d81f-6fc4-5c6a-99dd-d953c92137bf","canonical_origin":"https://staging.egypttourgates.com","identity_verified":true,"write_authorized":false}]'
        process=type('Process',(),{'returncode':0,'stdout':output})()
        with patch.object(resolver.subprocess,'run',return_value=process) as proc:
            rows=resolver.central_sites()
            args=proc.call_args.args[0]
        self.assertFalse(any(a=='--user' or a.startswith('--user=') for a in args))
        self.assertEqual(rows[('egypt-tour-gates','primary','staging')]['identity_verified'],True)
        self.assertFalse(rows[('egypt-tour-gates','primary','staging')]['write_authorized'])

if __name__=='__main__':
    unittest.main()
