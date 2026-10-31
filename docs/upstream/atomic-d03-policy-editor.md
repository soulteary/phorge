# Safe policy presentation and editing (D03)

This package extracts P01–P05 from PR #50 at
`47f9cd049c1a436e6a5ac377123627c4d2f010b0`. It changes policy presentation
and editing without replacing the native policy evaluator or an object's
permission workflow. Private creator templates and optional identity/maintenance
integrations are deferred to their own packages.

## Editor choices and existing policies

`policy.editor.hidden-rules` is a locked instance configuration value:

- `null` follows `phorge.product-profile`: `collaboration` hides lunar phase
  and Legalpad signature choices; `full` retains ordinary choices.
- An explicit class-name list overrides that profile. An empty list retains
  all ordinary choices; internal `shouldHideFromUI()` rules remain unavailable.
- Only choices for new custom policies are affected. Existing policies retain
  their stored rules, ordering, allow/deny values and default action.

A historical policy with an unavailable or internal rule opens read-only,
including when every editor choice has been hidden. Submitting fewer rules to
its old URL cannot create a partial replacement. Its full summary remains
visible and its evaluator continues to enforce the original rules.

To change such a policy, choose **Create Replacement Policy**, explicitly
configure all intended rules, and choose **Save Policy**. Starting this workflow
or cancelling it writes nothing. Saving creates a distinct immutable policy;
it does not apply it to the original object. The original object must then be
saved through its normal authorized form or native edit API.

The replacement marker requires a CSRF-validated form POST and cannot be used
with submitted rules or an existing policy identifier. Unknown, hidden,
inapplicable or internal rule classes cannot be posted as new rules. Invalid
rule JSON and translated/unknown allow/deny values are rejected without writes.

## Localization

Actions and known select values use native translated labels. Unknown values
and action labels remain text and are HTML-escaped; rendering never rewrites
stored values. New messages have English `pht()` sources and Chinese dictionary
entries. Both `en_US` and `zh_CN` are tested. Moon-phase labels are translated
as moon phases rather than generic fullness or novelty.

## Validation and compatibility

The pure presentation suite covers both locales, unknown-value escaping,
profile defaults/overrides, invalid configuration and unchanged lunar-rule
execution. The storage/controller suite exercises real native policy persistence,
CSRF form requests, read-only historical/internal rules, an empty choice set,
zero-write replacement startup/cancellation, forged submissions and explicit
application through `project.edit`.

Run the D01 gate after installing its foundation:

```bash
python3 tests/atomic/run.py --package D03 --output /tmp/phorge-d03-evidence
```

Storage cases require the gate's disposable MySQL and paired Gorge file/image
fixtures. Missing dependencies or skipped mandatory tests are failures, not
passes. `bin/unit` can run the two files directly in the same isolated environment.
This package adds no tables and changes no existing object policies. Hiding or
unhiding choices is reversible and never disables existing rule interpretation.
The retained PHP runtime, retired application cuts and Gorge responsibilities
remain in place.
