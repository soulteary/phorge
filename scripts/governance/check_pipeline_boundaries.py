#!/usr/bin/env python3
"""Review bookkeeping only: no PHP bootstrap, SQL connection or authorization."""
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = 'src/applications/transactions/editor/PhabricatorApplicationTransactionEditor.php'
SOURCE_SHA256 = '5a9e1511d75dad3ae201af97ad86fec845afbde989dd6c382c5d0c4f9088519d'
STAGES = [('old-recipients',
  '$this->buildOldRecipientLists($object, $xactions);',
  'native-queries-unverified'),
 ('lock-reload', '$object->beginReadLocking();', 'database-lock-reload'),
 ('edit-parameters',
  '$this->validateEditParameters($object, $xactions);',
  'dynamic-parameters-and-mfa'),
 ('local-expansion',
  '$xactions = $this->expandTransactions($object, $xactions);',
  'selected-local-observers-only'),
 ('support-expansion',
  '$xactions = $this->expandSupportTransactions($object, $xactions);',
  'subscriptions-remarkup-files-unverified'),
 ('combine-populate',
  '$xactions = $this->combineTransactions($xactions);',
  'combination-and-population-unverified'),
 ('modular-validation',
  '$errors[] = $this->validateTransaction(',
  'modular-validation-unverified'),
 ('cross-validation',
  '$errors[] = $this->validateAllTransactions($object, $xactions);',
  'selected-project-local-check-only'),
 ('extension-validation',
  '$errors[] = $this->validateTransactionsWithExtensions(',
  'dynamic-extension-dispatch'),
 ('capabilities',
  '$this->applyCapabilityChecks($object, $xactions);',
  'native-capabilities-and-mfa-unverified'),
 ('required-mfa', '$this->requireMFA($object, $xactions);', 'mfa-unverified'),
 ('initial-effects',
  '$this->applyInitialEffects($object, $xactions);',
  'conditional-effects-before-preview-return'),
 ('preview-return', '$this->loadHandles($xactions);', 'preview-does-not-prove-read-only'),
 ('internal-effects',
  '$this->applyInternalEffects($object, $xaction);',
  'internal-mutation'),
 ('internal-hooks',
  '$xactions = $this->didApplyInternalEffects($object, $xactions);',
  'dynamic-internal-hooks'),
 ('object-save', '$object->save();', 'database-persistence'),
 ('comment-records',
  '$comment_editor->applyEdit($xaction, $xaction->getComment());',
  'comment-persistence'),
 ('transaction-records', '$xaction->save();', 'transaction-and-edge-record-persistence'),
 ('external-effects',
  '$this->applyExternalEffects($object, $xaction);',
  'external-effects'),
 ('final-effects',
  '$xactions = $this->applyFinalEffects($object, $xactions);',
  'final-effects-and-subeditors-unverified'),
 ('commit', '$object->saveTransaction();', 'commit-boundary'),
 ('post-commit',
  '$this->didCommitTransactions($object, $xactions);',
  'post-commit-hooks-cache'),
 ('cache-update', '->updateObject($object);', 'post-commit-cache-effects'),
 ('herald',
  '$herald_xactions = $this->applyHeraldRules($object, $xactions);',
  'herald-and-subeditor-effects'),
 ('post-transaction-hooks',
  '$xactions = $this->didApplyTransactions($object, $xactions);',
  'dynamic-post-transaction-hooks'),
 ('indexes', '$fields->rebuildIndexes($object);', 'index-mutation'),
 ('herald-header',
  'HeraldTranscript::saveXHeraldRulesHeader(',
  'herald-header-persistence'),
 ('publishing', '$this->queuePublishing();', 'publishing-queue')]


def validate(data, root=ROOT):
    if not isinstance(data, dict) or type(data.get('schema_version')) is not int or data['schema_version'] != 1:
        raise ValueError('Pipeline schema required.')
    if set(data) != {'schema_version', 'activation_blocked', 'scope', 'runtime_certified', 'method', 'source', 'preview_read_only_proved', 'source_sha256', 'stages', 'can_execute'}:
        raise ValueError('Pipeline metadata fields drift.')
    expected = {'source': SOURCE, 'source_sha256': SOURCE_SHA256,
                'method': 'PhabricatorApplicationTransactionEditor::applyTransactions',
                'scope': 'reviewed lexical stage inventory; not an AST graph or runtime authorization proof',
                'runtime_certified': False, 'can_execute': False,
                'activation_blocked': True, 'preview_read_only_proved': False}
    for key, value in expected.items():
        if type(data.get(key)) is not type(value) or data[key] != value:
            raise ValueError('Pipeline contract drift: '+key)
    path = Path(root)/SOURCE
    if path.is_symlink() or not path.resolve().is_relative_to(Path(root).resolve()):
        raise ValueError('Source scope refused.')
    raw = path.read_bytes()
    if hashlib.sha256(raw).hexdigest() != SOURCE_SHA256:
        raise ValueError('Native pipeline changed; manual review required.')
    rows = data.get('stages')
    wanted = [dict(id=i, anchor=a, boundary=b, whole_stage_verified=False)
              for i, a, b in STAGES]
    if rows != wanted or any(type(r.get('whole_stage_verified')) is not bool for r in rows):
        raise ValueError('Stage inventory drift or unsupported certification.')
    text = raw.decode('utf-8')
    start = text.index('  final public function applyTransactions(')
    finish = text.index('  private function queuePublishing()', start)
    body = text[start:finish]
    cursor = 0
    for row in rows:
        offset = body.find(row['anchor'], cursor)
        if offset < 0:
            raise ValueError('Missing or reordered stage: '+row['id'])
        cursor = offset + len(row['anchor'])
    return dict(schema_valid=True, source_sha256=SOURCE_SHA256,
                stage_count=len(rows), runtime_certified=False,
                can_execute=False, activation_blocked=True,
                scope='source-review bookkeeping only; no native runtime execution')


if __name__ == '__main__':
    print(json.dumps(validate(json.loads((ROOT/'docs/governance/batch-5-pipeline.json').read_text()))))
