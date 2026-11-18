# Exact merge source proof

GitHub merge commits use a different signing key from the project's fixed source
signer. The external CI admission tool may therefore accept a separately signed
annotated tag for the exact merge. This is CI tooling only: no application, runtime
permission, deployment, or trusted-key change is involved.

The default direct-signature path remains unchanged. The opt-in path fetches only
`source-proof/<expected full commit>` from this repository and verifies the existing
project fingerprint. It binds the exact commit, full tree, ordered parents, reviewed
PR head, CI head and PR number. Merge tree equality and parent ancestry are mandatory.
Missing, invalid, expired or mismatched proof fails closed. All CI tests still run.

## Operator procedure

1. Check the exact PR head's required CI and final review, and confirm its merged
   commit on main. Record these identifiers; do not infer success from a tag.
2. In a trusted local checkout containing both parents, use the existing project key:

   ```sh
   python3 scripts/ci/create_merge_source_proof.py --root "$PWD" \
     --commit "$MERGE_SHA" --pr-head "$PR_HEAD" --ci-head "$PR_HEAD" \
     --pr-number "$PR_NUMBER"
   ```

3. Inspect `git show source-proof/$MERGE_SHA` and `git verify-tag --raw
   source-proof/$MERGE_SHA`. Publish only that tag, without force:
   `git push origin refs/tags/source-proof/$MERGE_SHA`.
4. Run CI on the exact main commit. A proof establishes source identity, never test
   success. A changed tree requires a new review and proof; never move existing tags.

No private key is exported or installed on runners. The helper creates the local tag
but does not query GitHub review status or publish it. Those are explicit operator
steps. No proof is required for a directly project-signed PR head.
