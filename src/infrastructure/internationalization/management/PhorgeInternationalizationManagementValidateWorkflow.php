<?php

final class PhorgeInternationalizationManagementValidateWorkflow
  extends PhabricatorInternationalizationManagementWorkflow {

  protected function didConstruct() {
    $this
      ->setName('validate')
      ->setExamples(
        '**validate** [__options__]')
      ->setSynopsis(pht(
        'Validate that all locales and translations are properly constructed.'))
      ->setArguments(
        array(
          array(
            'name' => 'extract',
            'help' => pht(
              'Do an implicit string extraction before validating. '.
              'If this is not set, it will validate based on the last '.
              'extracted strings.'),
          ),
          array(
            'name' => 'unused',
            'help' => pht(
              'Also report historical translation entries which are absent '.
              'from the current extracted source strings. These entries '.
              'are retained by default.'),
          ),
        ));
  }
  public function execute(PhutilArgumentParser $args) {
    $validator = new PhorgeInternationalizationValidator();
    $do_extract = $args->getArg('extract');
    $json = $validator->loadExtractions($do_extract);
    $errors = $validator->validateLibraries($json, $args->getArg('unused'));
    if (!count($errors)) {
     echo pht('No validation errors found!').PHP_EOL;
    } else {
      echo pht('The following validation errors were found:').PHP_EOL;
      foreach ($errors as $error) {
        echo $error.PHP_EOL;
      }
    }
    return count($errors) ? 1 : 0;
  }
}
