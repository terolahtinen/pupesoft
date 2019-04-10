<?php

require_once "inc/salasanat.php";

if (!isset($use_magento2_version)) {
    die("magento version not set");
}

if ($use_magento2_version === true) {
    require_once "magento_client_m1.php";
} else {
    require_once "magento_client_m1.php";
}