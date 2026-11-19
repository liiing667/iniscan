<?php

namespace Psecio\Iniscan\Command\ScanCommand\Output;

class Console extends \Psecio\Iniscan\Command\Output
{
    /**
     * @param \Psecio\Iniscan\Rule[] $results
     */
    public function render($results)
    {
        $output = $this->getOutput();
        $path = $this->getOption('path');
        $failOnly = $this->getOption('failOnly');
        $deprecated = $this->getOption('deprecated');
        $verbose = $this->getOption('verbose');

        $output->writeLn("<fg=cyan>== Executing INI Scan [".date('m.d.Y H:i:s')."] ==</fg=cyan>");

        $this->renderSourceChain($output);

        // loop through the results and output color coded
        $output->writeLn("\nResults for ".$path.":\n".str_repeat('=', 12));
        $output->writeLn(
            str_pad("Status", 7, ' ').'| '
            .str_pad("Severity", 9, ' ').'| '
            .str_pad("PHP Version", 12, ' ').'| '
            .str_pad("Current Value", 14, ' ').'| '
            .str_pad("Key", 30, ' ').'| '
            .str_pad("Effective Source", 32, ' ').'| Description'
        );
        $output->writeLn(str_repeat('-', 130));
        $fail = 0;
        $pass = 0;
        $warn = 0;

        foreach ($results as $result) {
            if ($result->getStatus() === false) {
                // if we failed, see how bad it is
                $severity = $result->getLevel();
                ($severity == 'WARNING') ? $warn++ : $fail++;

                $fgcolor = 'black';
                $status = 'FAIL';

                switch($severity) {
                    case 'INFO':
                        $bgcolor = 'black';
                        $fgcolor = 'cyan';
                        break;
                    case 'WARNING':
                        $bgcolor = 'yellow';
                        break;
                    default:
                        $bgcolor = 'red';
                }
            } elseif ($result->getStatus() === null) {
                $fgcolor = 'magenta';
                $status = 'N/A';
                $bgcolor = 'black';
            } else {
                $pass++;
                $status = 'PASS';
                $fgcolor = 'green';
                $bgcolor = 'black';
            }
            if ($failOnly === true && $status !== 'FAIL') {
                continue;
            }

            $test = $result->getTest();
            $version = (isset($test->version)) ? $test->version : '';
            $test = (isset($test->key)) ? $test->key : '';
            $value = $result->getValue();
            $source = $this->formatSource($test);

            $output->writeLn(
                '<fg='.$fgcolor.';bg='.$bgcolor.'>'
                .str_pad($status, 7, ' ')
                .'| '.str_pad($result->getLevel(), 9, ' ')
                .'| '.str_pad($version, 12, ' ')
                .'| '.str_pad($value, 14, ' ')
                .'| '.str_pad($test, 30, ' ')
                .'| '.str_pad($source, 32, ' ')
                .'| '.$result->getDescription()
                .'</fg='.$fgcolor.';bg='.$bgcolor.'>'
                );

            if ($verbose === true && isset($result->info)) {
                $output->writeLn('INFO: '.$result->info."\n");
            }

            if ($verbose === true && $test !== '') {
                $history = $this->renderProvenance($test);
                if ($history !== '') {
                    $output->writeLn('<fg=cyan>        provenance: '.$history.'</fg=cyan>');
                }
            }
        }
        $output->writeLn("\n<info>".$pass." passing</info>\n"
            ."<error>".$fail." failure(s)</error> and <fg=yellow>".$warn." warnings</fg=yellow>"
        );

        if (!empty($deprecated)) {
            $output->writeLn("\n<error>WARNING: deprecated configuration items found:</error>");
            foreach ($deprecated as $dep) {
                $output->writeLn('<fg=yellow>-> '.$dep.'</fg=yellow>');
            }
            $output->writeLn("It's recommended that these settings be removed "
                ."as they will be removed from future PHP versions.\n");
        }

        return ($fail > 0) ? 1 : 0;
    }

    /**
     * Render the ordered list of merged INI sources plus any duplicate or
     * tolerated conflicting keys so the effective configuration is fully
     * explainable before a single rule is evaluated.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function renderSourceChain($output)
    {
        $sources = $this->getOption('sources');
        if (empty($sources)) {
            return;
        }

        $output->writeLn("\n<info>Configuration sources (effective merge order):</info>");
        foreach ($sources as $index => $source) {
            $output->writeLn(
                '  '.str_pad('#'.$index, 4, ' ').' '.$source
            );
        }

        $duplicates = $this->getOption('duplicates');
        if (!empty($duplicates)) {
            $output->writeLn(
                "\n<fg=yellow>Duplicate keys declared across sources: "
                .implode(', ', $duplicates)."</fg=yellow>"
            );
        }

        $conflicts = $this->getOption('conflicts');
        if (!empty($conflicts)) {
            $output->writeLn(
                '<fg=yellow>Tolerated overrides (later value wins, non-security keys): '
                .implode(', ', $conflicts)."</fg=yellow>"
            );
        }
    }

    /**
     * Build the short "file:line" label of the source that currently wins
     * for a rule's key.
     *
     * @param string $key INI key
     * @return string
     */
    protected function formatSource($key)
    {
        $provenance = $this->getOption('provenance');
        if (!isset($provenance[$key])) {
            return 'default';
        }

        $winning = null;
        foreach ($provenance[$key] as $entry) {
            if (!empty($entry['winning']) || $winning === null) {
                $winning = $entry;
            }
        }

        if ($winning === null) {
            return 'default';
        }

        return basename($winning['source']).':'.$winning['line'];
    }

    /**
     * Render the full declaration chain of one key for verbose mode.
     *
     * @param string $key INI key
     * @return string
     */
    protected function renderProvenance($key)
    {
        $provenance = $this->getOption('provenance');
        if (!isset($provenance[$key])) {
            return '';
        }

        $steps = array();
        foreach ($provenance[$key] as $entry) {
            $steps[] = '#'.$entry['sourceOrder'].' '
                .basename($entry['source']).':'.$entry['line']
                .' = "'.$entry['value'].'"'
                .(!empty($entry['winning']) ? ' [effective]' : '');
        }

        return implode(' -> ', $steps);
    }
}
