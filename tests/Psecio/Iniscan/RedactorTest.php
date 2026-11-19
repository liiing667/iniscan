<?php

namespace Psecio\Iniscan;

class RedactorTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Test that keys holding secrets are masked
     *
     * @covers \Psecio\Iniscan\Redactor::redact
     * @covers \Psecio\Iniscan\Redactor::isSensitiveKey
     */
    public function testSensitiveKeysAreMasked()
    {
        $keys = array(
            'mysqli.default_pw',
            'db.password',
            'app.api_token',
            'oauth.client_secret',
            'db.dsn',
            'service.connection_string',
            'aws.secret_access_key',
        );
        foreach ($keys as $key) {
            $this->assertTrue(Redactor::isSensitiveKey($key), 'Key not detected as sensitive: '.$key);
            $this->assertEquals(Redactor::MASK, Redactor::redact($key, 'some-value'));
        }
    }

    /**
     * Test that connection strings with credentials are masked,
     *     no matter which key they sit under
     *
     * @covers \Psecio\Iniscan\Redactor::redact
     * @covers \Psecio\Iniscan\Redactor::isSensitiveValue
     */
    public function testConnectionStringValuesAreMasked()
    {
        $values = array(
            'tcp://session-user:s3ss!onP4ss@memcache.internal:11211',
            'mysql://dbuser:dbpass@localhost/app',
            'redis://:onlypassword@cache.internal:6379',
            'host=db.internal;Password=topsecret;User ID=app',
        );
        foreach ($values as $value) {
            $this->assertTrue(Redactor::isSensitiveValue($value), 'Value not detected as sensitive: '.$value);
            $this->assertEquals(Redactor::MASK, Redactor::redact('session.save_path', $value));
        }
    }

    /**
     * Test that legal non-sensitive values are kept as they are
     *
     * @covers \Psecio\Iniscan\Redactor::redact
     */
    public function testNonSensitiveValuesAreKept()
    {
        $values = array(
            'session.cookie_domain' => 'shop.example.com',
            'display_errors' => '0',
            'default_charset' => 'UTF-8',
            'session.save_path' => 'tcp://127.0.0.1:11211',
            'session.save_path#2' => '/var/lib/php/sessions',
            'memory_limit' => '128M',
        );
        foreach ($values as $key => $value) {
            $this->assertFalse(Redactor::isSensitiveKey($key), 'Key wrongly flagged as sensitive: '.$key);
            $this->assertEquals($value, Redactor::redact($key, $value));
        }
    }

    /**
     * Test that the mask is uniform, no matter the secret
     *
     * @covers \Psecio\Iniscan\Redactor::redact
     */
    public function testMaskIsUniform()
    {
        $this->assertEquals(
            Redactor::redact('db.password', 'short'),
            Redactor::redact('db.password', 'a-much-longer-secret-value')
        );
        $this->assertEquals(Redactor::MASK, Redactor::redact('db.password', 'short'));
    }

    /**
     * Test that non-string and empty values are left untouched
     *
     * @covers \Psecio\Iniscan\Redactor::redact
     */
    public function testNonStringValuesAreUntouched()
    {
        $this->assertNull(Redactor::redact('db.password', null));
        $this->assertFalse(Redactor::redact('db.password', false));
        $this->assertEquals('', Redactor::redact('db.password', ''));
    }

    /**
     * Test that connection strings inside free text (ex. descriptions)
     *     are masked while the rest of the text is kept
     *
     * @covers \Psecio\Iniscan\Redactor::redactString
     */
    public function testRedactStringMasksConnectionStrings()
    {
        $text = 'Path tcp://session-user:s3ss!onP4ss@memcache.internal:11211 is world writeable';
        $result = Redactor::redactString($text);
        $this->assertNotContains('s3ss!onP4ss', $result);
        $this->assertContains(Redactor::MASK, $result);
        $this->assertContains('is world writeable', $result);
    }

    /**
     * Test that plain text without secrets is kept as it is
     *
     * @covers \Psecio\Iniscan\Redactor::redactString
     */
    public function testRedactStringKeepsPlainText()
    {
        $text = 'Path /var/lib/php/sessions is world writeable';
        $this->assertEquals($text, Redactor::redactString($text));
    }
}
