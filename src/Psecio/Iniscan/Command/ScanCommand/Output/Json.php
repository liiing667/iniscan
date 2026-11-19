<?php

namespace Psecio\Iniscan\Command\ScanCommand\Output;

class Json extends \Psecio\Iniscan\Command\Output
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

        $result = array('results' => $resultValues);

        $output->writeLn(json_encode($result));
    }
}
