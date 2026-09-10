<?php

final class PhabricatorSyntaxHighlightingConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Syntax Highlighting');
  }

  public function getDescription() {
    return pht('Options relating to syntax highlighting source code.');
  }

  public function getIcon() {
    return 'fa-code';
  }

  public function getGroup() {
    return 'core';
  }

  public function getApplicationClassName() {
    return PhabricatorSystemApplication::class;
  }

  public function getOptions() {
    return array(
      $this->newOption(
        'syntax-highlighter.engine',
        'class',
        'PhutilDefaultSyntaxHighlighterEngine')
        ->setBaseClass('PhutilSyntaxHighlighterEngine')
        ->setSummary(pht('Default syntax highlighter engine.'))
        ->setDescription(
          pht(
            'The bundled deployment uses `%s` for advanced syntax '.
            'highlighting. The default engine keeps only the small built-in '.
            'lexers for explicit Gorge-off and recovery deployments.',
            'PhabricatorGorgeSyntaxHighlighterEngine')),
      $this->newOption('gorge.render.uri', 'string', null)
        ->setLocked(true)
        ->setSummary(pht('Base URI of the Gorge render service.'))
        ->setDescription(
          pht(
            'Base URI of the Gorge render service, which highlights source '.
            'code and returns HTML using the CSS class names expected by '.
            'Phorge.'.
            "\n\n".
            'Setting this option does not enable the service by itself. To '.
            'use it, also set `syntax-highlighter.engine` to `%s`.',
            'PhabricatorGorgeSyntaxHighlighterEngine'))
        ->addExample('http://gorge-render:8140', pht('Compose service')),
      $this->newOption('gorge.render.token', 'string', null)
        ->setHidden(true)
        ->setDescription(
          pht(
            'Service token for the Gorge render service, sent with each '.
            'request in an "X-Service-Token" header.')),
      $this->newOption('gorge.diff.enabled', 'bool', false)
        ->setSummary(pht('Generate text differences with Gorge?'))
        ->setBoolOptions(
          array(
            pht('Use Gorge'),
            pht('Use Local Difference Engines'),
          ))
        ->setDescription(
          pht(
            'Route unified and prose difference computation through the '.
            'diff domain served by `gorge-render`. The global Gorge service '.
            'policy controls whether a failure is exposed or may use the '.
            'migration fallback.')),
      $this->newOption('pygments.enabled', 'bool', false)
        ->setLocked(true)
        ->setSummary(pht('Retired Pygments compatibility key.'))
        ->setDescription(
          pht(
            'This option is retained only so existing configuration remains '.
            'readable. Pygments execution support has been removed and this '.
            'value is always treated as disabled. Use `%s` for advanced '.
            'syntax highlighting.',
            'PhabricatorGorgeSyntaxHighlighterEngine')),
      $this->newOption(
        'pygments.dropdown-choices',
        'wild',
        array(
          'apacheconf' => 'Apache Configuration',
          'bash' => 'Bash Scripting',
          'brainfuck' => 'Brainf*ck',
          'c' => 'C',
          'coffee-script' => 'CoffeeScript',
          'cpp' => 'C++',
          'csharp' => 'C#',
          'css' => 'CSS',
          'd' => 'D',
          'diff' => 'Diff',
          'django' => 'Django Templating',
          'docker' => 'Docker',
          'erb' => 'Embedded Ruby/ERB',
          'erlang' => 'Erlang',
          'go' => 'Golang',
          'groovy' => 'Groovy',
          'haskell' => 'Haskell',
          'html' => 'HTML',
          'http' => 'HTTP',
          'invisible' => 'Invisible',
          'java' => 'Java',
          'js' => 'Javascript',
          'json' => 'JSON',
          'make' => 'Makefile',
          'mysql' => 'MySQL',
          'nginx' => 'Nginx Configuration',
          'objc' => 'Objective-C',
          'perl' => 'Perl',
          'php' => 'PHP',
          'postgresql' => 'PostgreSQL',
          'pot' => 'Gettext Catalog',
          'puppet' => 'Puppet',
          'python' => 'Python',
          'rainbow' => 'Rainbow',
          'remarkup' => 'Remarkup',
          'rst' => 'reStructuredText',
          'robotframework' => 'RobotFramework',
          'ruby' => 'Ruby',
          'sql' => 'SQL',
          'tex' => 'LaTeX',
          'text' => 'Plain Text',
          'twig' => 'Twig',
          'xml' => 'XML',
          'yaml' => 'YAML',
        ))
        ->setSummary(pht('Set the language list which appears in dropdowns.'))
        ->setDescription(
          pht(
            'This legacy key now only owns the dropdown vocabulary; it does '.
            'not enable or invoke a Pygments runtime.')),
      $this->newOption(
        'syntax.filemap',
        'custom:PhabricatorConfigRegexOptionType',
        array(
          '@\.arcconfig$@' => 'json',
          '@\.arclint$@' => 'json',
          '@\.divinerconfig$@' => 'json',
        ))
        ->setSummary(
          pht('Override what language files (based on filename) highlight as.'))
        ->setDescription(
          pht(
            'This is an ordered dictionary of regular expressions which '.
            'maps filenames to explicit syntax languages.'))
      ->addExample(
        '{"@\\\.xyz$@": "php"}',
        pht('Highlight %s as PHP.', '*.xyz'))
      ->addExample(
        '{"@/httpd\\\.conf@": "apacheconf"}',
        pht('Highlight httpd.conf as "apacheconf".'))
      ->addExample(
        '{"@\\\.([^.]+)\\\.bak$@": 1}',
        pht("Treat all '*.x.bak' file as '.x'.")),
    );
  }

}
