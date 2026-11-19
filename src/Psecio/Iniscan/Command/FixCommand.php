<?php
namespace Psecio\Iniscan\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class FixCommand extends Command
{
    protected function configure()
    {
        $this->setName('fix')
                ->setDescription('Try to fix the settings in the specified PHP.ini file')
            ->setDefinition(array(
                new InputOption('path', 'path', InputOption::VALUE_OPTIONAL, 'Path to the php.ini'),
                new InputOption('root', 'root', InputOption::VALUE_OPTIONAL, 'Root directory the php.ini must stay inside'),
            ))
            ->setHelp(
                'Try to fix the settings in the specified PHP.ini file'
            );
    }

    /**
     * Execute the "fix" command
     *
     * @param  InputInterface $input Input object
     * @param  OutputInterface $output Output object
     * @throws \Exception
     * @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $input->getOption('path');
        $root = $input->getOption('root');
        $context = array();

        // if we're not given a path at all, try to figure it out
        if ($path === null) {
            $path = php_ini_loaded_file();
        }

        if ($path === null) {
            throw new \Psecio\Iniscan\Exceptions\InvalidPathException(
                'Path is null or not accessible'
            );
        }

        $root = ($root !== null) ? $root : getcwd();
        $scan = new \Psecio\Iniscan\Scan($path, $context, null, null, $root);
        $path = $scan->getPath();
        $scan->execute();

        $scan->getMarked();
        $result = pathinfo($path);

        // to start, we need a backup of the file (overwrite if there)
        $backupPath = './'.$result['basename'].'-'.date('mdy');
        copy($path, $backupPath);

        // Now les get our rules and parse them
        $scan = new \Psecio\Iniscan\Scan($path, $context, null, null, $root);
        $rules = get_object_vars($scan->getRules());

        $output->writeLn($this->generateIniOutput($rules));
    }

    /**
     * Generate the output string of the more secure ini settings
     *
     * @param array $rules Set of current rules
     * @return string INI string
     */
    public function generateIniOutput($rules)
    {
        $ini = '';
        $config = array();

        foreach ($rules as $section => $ruleSet) {
            foreach ($ruleSet as $rule) {
                if (isset($rule->test)) {
                    if (is_object($rule->test)) {
                        if (!in_array($rule->test->operation, array('isset'))) {
                            $ini .= $rule->test->key.' = '.$rule->test->value."\n";
                        }
                    } else if (is_string($rule->test)) {
                        // no test object defined, this is a custom test
                        $testPath = '\\Psecio\\Iniscan\\Rule\\'.$rule->test;
                        if (class_exists($testPath)) {
                            $test = new $testPath($config, $section);
                            if (method_exists($test, '__toString')) {
                                $ini .= $test;
                            }
                        }
                    }
                }
            }
        }
        $ini .= "\n";
        return $ini;
    }
}
