<?php

$path = '/home/sauletela/projects/heater/pupesoft/pupesoft';
set_include_path(get_include_path() . PATH_SEPARATOR . $path);

require '../magento_client.php';
require 'inc/salasanat.php';

function pupesoft_log($log_name, $message){
    echo "logname: $log_name, message: $message\n";
}

class TestUtil
{
  public static function callMethod($obj, $name, array $args) {
        $class = new \ReflectionClass($obj);
        $method = $class->getMethod($name);
        $method->setAccessible(true);
        return $method->invokeArgs($obj, $args);
    }
}

$magento_client = new MagentoClient ($magento_api_base_url, $magento_bearer);

// $returnVal = TestUtil::callMethod(
//     $magento_client,
//     'getProductList', 
//     array(true)
//  );

//  print_r($returnVal);

 //unset($returnVal[5725]);

 $paivita_arvo[] = [
     'tuoteno' => 'jokutoinentesti123456',
     'myytavissa' => 123,
     'vaihtoehtoiset_saldot' => []
 ];

 $returnVal = TestUtil::callMethod(
    $magento_client,
    'paivita_saldot', 
    array($paivita_arvo)
 );
