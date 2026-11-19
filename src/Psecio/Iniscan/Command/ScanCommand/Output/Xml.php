<?php

namespace Psecio\Iniscan\Command\ScanCommand\Output;

class Xml extends \Psecio\Iniscan\Command\Output
{
    /**
     * @param \Psecio\Iniscan\Rule[] $results
     */
    public function render($results)
    {
        $output = $this->getOutput();
        $resultValues = array();

        foreach ($results as $result) {
        	$values = $result->values();
        	$test = $result->getTest();
        	$key = (isset($test->key)) ? $test->key : '';
        	$values['currentValue'] = $this->redactValue($key, $values['currentValue']);
        	$values['description'] = $this->redactText($values['description']);
        	$resultValues[] = $values;
        }

        $dom = new \DomDocument('1.0', 'UTF-8');
        $results = $dom->createElement('results');

        foreach ($resultValues as $result) {
            $resultXml = $dom->createElement('result');

            foreach ($result as $name => $value) {
                $property = $dom->createElement($name, $value);
                $resultXml->appendChild($property);
            }

            $results->appendChild($resultXml);
        }
        $dom->appendChild($results);

        $output->writeLn($dom->saveXML());
    }
}
