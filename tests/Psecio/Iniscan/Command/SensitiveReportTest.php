<?php

namespace Psecio\Iniscan\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs the scan and show commands against a fixed report input
 *     (tests/fixtures/secret-report.ini) holding a connection string,
 *     a password and an API token, and proves the secrets never
 *     reach the report unless local diagnostics are explicitly enabled
 */
class SensitiveReportTest extends \PHPUnit_Framework_TestCase
{
    private $secrets = array(
        's3ss!onP4ss',
        'Sup3rS3cretDbPw',
        'tok_live_9f8e7d6c5b',
    );

    private function fixturePath()
    {
        return __DIR__.'/../../../fixtures/secret-report.ini';
    }

    private function runCommand($name, $command, array $input)
    {
        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($application->find($name));
        $tester->execute($input);
        return $tester->getDisplay();
    }

    private function assertSecretsHidden($display)
    {
        foreach ($this->secrets as $secret) {
            $this->assertNotContains($secret, $display, 'Secret leaked into report: '.$secret);
        }
    }

    /**
     * The default scan report keeps keys, rules, source and severity
     *     but masks the sensitive values
     */
    public function testScanReportMasksSecretsByDefault()
    {
        $display = $this->runCommand('scan', new ScanCommand(), array(
            '--path' => $this->fixturePath()
        ));
        $this->assertSecretsHidden($display);
        $this->assertContains(\Psecio\Iniscan\Redactor::MASK, $display);
        // keys, rules, source and severity stay visible
        $this->assertContains('session.save_path', $display);
        $this->assertContains('Results for '.$this->fixturePath(), $display);
        $this->assertContains('Severity', $display);
        // legal non-sensitive values are kept
        $this->assertContains('shop.example.com', $display);
    }

    /**
     * The JSON report masks sensitive values as well
     */
    public function testScanJsonReportMasksSecretsByDefault()
    {
        $display = $this->runCommand('scan', new ScanCommand(), array(
            '--path' => $this->fixturePath(),
            '--format' => 'json'
        ));
        $this->assertSecretsHidden($display);
        $this->assertContains(\Psecio\Iniscan\Redactor::MASK, $display);
        $this->assertContains('shop.example.com', $display);
        $report = json_decode($display);
        $this->assertNotNull($report, 'JSON report is not parseable');
    }

    /**
     * Explicit local diagnostics echo the raw values again
     */
    public function testScanReportShowsSecretsWhenExplicitlyEnabled()
    {
        $display = $this->runCommand('scan', new ScanCommand(), array(
            '--path' => $this->fixturePath(),
            '--show-sensitive' => true
        ));
        $this->assertContains('s3ss!onP4ss', $display);
    }

    /**
     * The "show" configuration report masks password and token
     *     settings by default
     */
    public function testShowReportMasksSecretsByDefault()
    {
        $display = $this->runCommand('show', new ShowCommand(), array(
            '--path' => $this->fixturePath()
        ));
        $this->assertSecretsHidden($display);
        $this->assertContains(\Psecio\Iniscan\Redactor::MASK, $display);
        // keys and source stay visible
        $this->assertContains('mysqli.default_pw', $display);
        $this->assertContains('app.api_token', $display);
        $this->assertContains($this->fixturePath(), $display);
        // legal non-sensitive values are kept
        $this->assertContains('UTF-8', $display);
        $this->assertContains('shop.example.com', $display);
    }

    /**
     * Explicit local diagnostics echo the raw values again
     */
    public function testShowReportShowsSecretsWhenExplicitlyEnabled()
    {
        $display = $this->runCommand('show', new ShowCommand(), array(
            '--path' => $this->fixturePath(),
            '--show-sensitive' => true
        ));
        foreach ($this->secrets as $secret) {
            $this->assertContains($secret, $display);
        }
    }
}
