<?php

require_once dirname(__FILE__) . "/../../../../core/php/core.inc.php";
require_once dirname(__FILE__) . "/../class/phone_detection.class.php";
require_once dirname(__FILE__) . "/../class/phone_detection_remote.class.php";

if (!jeedom::apiAccess(init('apikey'), 'phone_detection')) {
    echo __('Vous n\'êtes pas autorise a effectuer cette action', __FILE__);
    die();
}

$results  = json_decode(file_get_contents("php://input"), true);
$action   = $results['action'];
$value    = 0;
$success  = phone_detection::deamonEventHandler($action, $results, $value);

$response = array('success' => $success, 'value' => $value);

echo json_encode($response);
?>
