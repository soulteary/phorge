"""Candidate policy controls; no runtime or deployment qualification."""
import copy, importlib.util, sys, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('candidate_recipe',ROOT/'scripts/runtime/php86-candidate-dockerfile.py')
recipe=importlib.util.module_from_spec(spec);spec.loader.exec_module(recipe)
sys.path.insert(0,str(ROOT/'tests/governance/native_rr_ci'))
import execution

class CandidateControls(unittest.TestCase):
    def test_native_receipt_cannot_mix_or_invent_runtime_profiles(self):
        sys.path.insert(0,str(ROOT/'scripts/governance'))
        import check_native_organization as native
        for profile,runtime in [('production','8.6.0'),('php86-isolated-v1','8.5.13'),('unknown','8.6.0')]:
            with self.assertRaisesRegex(ValueError,'unqualified native runtime profile'):
                native.validate({},runtime_profile=profile,expected_runtime=runtime)
        # Explicit selection is not permission to bypass the original body.
        with self.assertRaisesRegex(ValueError,'native receipt fields refused'):
            native.validate({},runtime_profile='php86-isolated-v1',expected_runtime='8.6.0')

    def test_recipe_changes_only_base_and_explicit_profile(self):
        original=(ROOT/'Dockerfile').read_text()
        candidate=recipe.render(original)
        restored=candidate.replace('ARG PHP_BASE_IMAGE=php:8.6.0-apache','ARG PHP_BASE_IMAGE=php:8.5.13-apache').replace(
            'ENV PHORGE_PHP_COMPATIBILITY_PROFILE=php86-isolated-v1\nENV PHORGE_GOVERNANCE_ISOLATED_TEST=1\n','')
        native_start=restored.index('    && mkdir /tmp/apcu-build')
        native_end=restored.index('    && docker-php-ext-enable apcu',native_start)
        restored=restored[:native_start]+'    && pecl install /tmp/apcu.tgz '+chr(92)+'\n'+restored[native_end:]
        restored=restored.replace(
            '33f9038e98d236d4833b3e311c21e1d969a96d06b0d1e4427c8a0db2dd7123e2','ca9c1820810a168786f8048a4c3f8c9e3fd941407ad1553259fb2e30b5f057bf').replace(
            'https://codeload.github.com/krakjoe/apcu/tar.gz/90899684be91c4f750512c2f840f487286ecb807','https://pecl.php.net/get/apcu-${APCU_VERSION}.tgz')
        self.assertEqual(original,restored)
        self.assertIn('sha256sum -c -',candidate)
        self.assertIn('phpize && ./configure --enable-apcu',candidate)
        self.assertNotIn('8.6',original)
    def test_apcu_build_drift_is_not_silently_accepted(self):
        source=(ROOT/'Dockerfile').read_text()
        for old,new in [('ca9c1820810a168786f8048a4c3f8c9e3fd941407ad1553259fb2e30b5f057bf','0'*64),
                        ('pecl install /tmp/apcu.tgz','pecl install apcu')]:
            with self.assertRaisesRegex(ValueError,'APCu recipe drift'):
                recipe.render(source.replace(old,new))

    def test_unknown_fixture_service_never_contacts_docker(self):
        path=ROOT/'tests/atomic/extract_native_renderer.py'
        spec=importlib.util.spec_from_file_location('candidate_extract',path)
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        with self.assertRaisesRegex(ValueError,'reviewed fixture service'):
            module.extract('/unused','/unused','a'*40,service='unreviewed',
                           runner=lambda *args,**kwargs:self.fail('Docker contacted'))
    def test_unknown_or_ambiguous_recipe_refuses(self):
        for raw in ('FROM php:latest\n',(ROOT/'Dockerfile').read_text()*2):
            with self.assertRaises(ValueError):recipe.render(raw)
    def test_candidate_receipt_requires_explicit_profile_and_exact_runtime(self):
        ids={'Synthetic::test'+str(i)for i in range(282)}
        value={'passed':True,'runtime':'8.6.0','expected_methods':282,
               'results':[{'id':i,'result':'pass'}for i in ids]}
        self.assertTrue(execution.validate_pure(value,ids,expected_runtime='8.6.0',profile='php86-isolated-v1')['passed'])
        with self.assertRaises(ValueError):execution.validate_pure(value,ids)
        for profile,runtime in [('production','8.6.0'),('php86-isolated-v1','8.5.13'),('unknown','8.6.0'),('php86-isolated-v1','8.6.0RC1')]:
            with self.assertRaises(ValueError):execution.validate_pure(value,ids,expected_runtime=runtime,profile=profile)
        for change in ('baseline','missing','duplicate','failed'):
            bad=copy.deepcopy(value)
            if change=='baseline':bad['runtime']='8.5.13'
            elif change=='missing':bad['results'].pop()
            elif change=='duplicate':bad['results'][0]=bad['results'][1]
            else:bad['results'][0]['result']='fail'
            with self.assertRaises(ValueError):execution.validate_pure(bad,ids,expected_runtime='8.6.0',profile='php86-isolated-v1')

if __name__=='__main__':unittest.main()
