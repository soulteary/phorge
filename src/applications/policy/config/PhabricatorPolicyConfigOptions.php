<?php

final class PhabricatorPolicyConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Policy');
  }

  public function getDescription() {
    return pht('Options relating to object visibility.');
  }

  public function getIcon() {
    return 'fa-lock';
  }

  public function getGroup() {
    return 'apps';
  }

  public function getApplicationClassName() {
    return PhabricatorPolicyApplication::class;
  }

  public function getOptions() {
    $policy_locked_type = 'custom:PolicyLockOptionType';
    $policy_locked_example = array(
      'people.create.users' => 'admin',
    );
    $json = new PhutilJSON();
    $policy_locked_example = $json->encodeFormatted($policy_locked_example);

    return array(
      $this->newOption('policy.editor.hidden-rules', 'wild', null)
        ->setLocked(true)
        ->setSummary(pht('Rules unavailable for new custom-policy editing.'))
        ->setDescription(pht(
          'Use null to inherit the product profile: collaboration hides lunar '.
          'phase and Legalpad signature conditions, while full keeps them. '.
          'An explicit list of rule class names overrides this default; an '.
          'empty list keeps all ordinary rules available. Existing policies '.
          'continue to evaluate unchanged and unavailable rules are shown '.
          'read-only until an explicit replacement is configured.')),
      $this->newOption('policy.allow-public', 'bool', false)
        ->setBoolOptions(
          array(
            pht('Allow Public Visibility'),
            pht('Require Login'),
          ))
        ->setSummary(pht('Allow users to set object visibility to public.'))
        ->setDescription(
          pht(
            "This software allows you to set the visibility of objects (like ".
            "repositories and tasks) to 'Public', which means **anyone ".
            "on the internet can see them, without needing to log in or ".
            "have an account**.".
            "\n\n".
            "This is intended for open source projects. Many installs will ".
            "never want to make anything public, so this policy is disabled ".
            "by default. You can enable it here, which will let you set the ".
            "policy for objects to 'Public'.".
            "\n\n".
            "Enabling this setting will immediately open up some features, ".
            "like the user directory. Anyone on the internet will be able to ".
            "access these features.".
            "\n\n".
            "With this setting disabled, the 'Public' policy is not ".
            "available, and the most open policy is 'All Users' (which means ".
            "users must have accounts and be logged in to view things).")),
      $this->newOption('policy.locked', $policy_locked_type, array())
        ->setLocked(true)
        ->setSummary(pht(
          'Lock specific application policies so they can not be edited.'))
        ->setDescription(pht(
          'This software has application policies which can dictate whether '.
          'users can take certain actions, such as creating new users. '."\n\n".
          'This setting allows for "locking" these policies such that no '.
          'further edits can be made on a per-policy basis.'))
          ->addExample(
            $policy_locked_example,
            pht('Lock Create User Policy To Admins')),
    );
  }

}
