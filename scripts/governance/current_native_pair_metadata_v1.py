"""Exact CURRENT collector metadata inverse; returned parent bytes are DATA only."""
import hashlib
CURRENT_SHA='6090e48c4458829fd1ac84b7f3076ed2b7c321f7dca90d9399ebc06e73fb152f'
PARENT_SHA='c2c614ab497e6590c13455bd1b495f9f9230c62acd066884b738d460023be300'
BYTES=125897
CURRENT_LITERAL=b"'source_revision': '16318f87a017f82c28f3011b362364cf24b73ef8'"
PARENT_LITERAL=b"'source_revision': '83351b0e7eba38e50ff076ba6763ca68ebc57e1b'"
def parent_DATA(raw,expected_parent_sha):
 if type(raw)is not bytes or type(expected_parent_sha)is not str or expected_parent_sha!=PARENT_SHA or len(raw)!=BYTES or hashlib.sha256(raw).hexdigest()!=CURRENT_SHA:raise ValueError('current_native_pair_metadata_refused')
 if raw.count(CURRENT_LITERAL)!=1:raise ValueError('current_native_pair_metadata_refused')
 prior=raw.replace(CURRENT_LITERAL,PARENT_LITERAL,1)
 if len(prior)!=BYTES or hashlib.sha256(prior).hexdigest()!=PARENT_SHA:raise ValueError('current_native_pair_metadata_refused')
 return prior
