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
    $caches_href = PhabricatorEnv::getDoclink('Managing Caches');

    return array(
      $this->newOption(
        'syntax-highlighter.engine',
        'class',
        'PhutilDefaultSyntaxHighlighterEngine')
        ->setBaseClass('PhutilSyntaxHighlighterEngine')
        ->setSummary(pht('Default non-pygments syntax highlighter engine.'))
        ->setDescription(
          pht(
            'You can provide a custom highlighter engine by extending '.
            'class %s.',
            'PhutilSyntaxHighlighterEngine')),
      $this->newOption('gorge.render.uri', 'string', null)
        ->setLocked(true)
        ->setSummary(pht('Base URI of the Gorge render service.'))
        ->setDescription(
          pht(
            'Base URI of the Gorge render service, a standalone service '.
            'which highlights source code and returns HTML using the same '.
            'CSS class names Pygments uses.'.
            "\n\n".
            'Setting this option does not enable the service by itself. To '.
            'use it, also set `syntax-highlighter.engine` to `%s`.'.
            "\n\n".
            'Do not include a trailing slash: the service routes exactly, '.
            'and a doubled slash produces an "ERR_NOT_FOUND" error instead '.
            'of highlighted source.',
            'PhabricatorGorgeSyntaxHighlighterEngine'))
        ->addExample('http://gorge-render:8140', pht('Compose service')),
      $this->newOption('gorge.render.token', 'string', null)
        ->setHidden(true)
        ->setDescription(
          pht(
            'Service token for the Gorge render service, sent with each '.
            'request in an "X-Service-Token" header. Leave this empty if '.
            'the service is configured without a token.')),
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
            'diff domain served by `gorge-render`. This domain uses the same '.
            '`gorge.render.uri` and `gorge.render.token` options as syntax '.
            'highlighting.'.
            "\n\n".
            'If the service is unavailable or returns an invalid response, '.
            'Phorge records the failure and falls back to its local '.
            'difference engines.')),
      $this->newOption('pygments.enabled', 'bool', false)
        ->setSummary(
          pht('Use Pygments to highlight code?'))
        ->setBoolOptions(
          array(
            pht('Use Pygments'),
            pht('Do Not Use Pygments'),
          ))
        ->setDescription(
          pht(
            'Syntax highlighting is supported for a few languages by '.
            'default, but you can install Pygments (a third-party syntax '.
            'highlighting tool) to provide support for many more languages.'.
            "\n\n".
            'To install Pygments, visit '.
            '[[ https://pygments.org/ | pygments.org ]] and follow the '.
            'download and install instructions.'.
            "\n\n".
            'Once Pygments is installed, enable this option '.
            '(`pygments.enabled`) to make use of Pygments when '.
            'highlighting source code.'.
            "\n\n".
            'After you install and enable Pygments, newly created source '.
            'code (like diffs and pastes) should highlight correctly. '.
            'You may need to clear caches to get previously '.
            'existing source code to highlight. For instructions on '.
            'managing caches, see [[ %s | Managing Caches ]].',
            $caches_href)),
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
        ->setSummary(
          pht('Set the language list which appears in dropdowns.'))
        ->setDescription(
          pht(
            'In places that we display a dropdown to syntax-highlight code, '.
            'this is where that list is defined.')),
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
            'This is an override list of regular expressions which allows '.
            'you to choose what language files are highlighted as. If your '.
            'projects have certain rules about filenames or use unusual or '.
            'ambiguous language extensions, you can create a mapping here. '.
            'This is an ordered dictionary of regular expressions which will '.
            'be tested against the filename. They should map to either an '.
            'explicit language as a string value, or a numeric index into '.
            'the captured groups as an integer.'))
      ->addExample(
        '{"@\\\.xyz$@": "php"}',
        pht('Highlight %s as PHP.', '*.xyz'))
      ->addExample(
        '{"@/httpd\\\.conf@": "apacheconf"}',
        pht('Highlight httpd.conf as "apacheconf".'))
      ->addExample(
        '{"@\\\.([^.]+)\\\.bak$@": 1}',
        pht(
          "Treat all '*.x.bak' file as '.x'. NOTE: We map to capturing group ".
          "1 by specifying the mapping as '1'")),
    );
  }

}
