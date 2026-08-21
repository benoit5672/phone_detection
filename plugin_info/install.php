<?php

require_once dirname(__FILE__).'/../../../core/php/core.inc.php';

function phone_detection_install() {
    $sql = file_get_contents(dirname(__FILE__) . '/install.sql');
    DB::Prepare($sql, array(), DB::FETCH_TYPE_ROW);
    foreach (phone_detection::byType('phone_detection') as $phone_detection) {
        $phone_detection->save();
    }
    config::save('version',phone_detection::getVersion(),'phone_detection');
    file_put_contents(dirname(__FILE__) . '/../resources/phone_detectiond/version.txt', phone_detection::getVersion()); 
}

function phone_detection_update() {
    log::add('phone_detection', 'debug', 'phone_detection_update');
    $sql = file_get_contents(dirname(__FILE__) . '/install.sql');
    DB::Prepare($sql, array(), DB::FETCH_TYPE_ROW);
    foreach (phone_detection::byType('phone_detection') as $phone_detection) {
        $phone_detection->save();
    }
    

    $daemonInfo = phone_detection::deamon_info();
    if ($daemonInfo['state'] == 'ok') {
        phone_detection::deamon_stop();
    }

    $cache = cache::byKey('dependancy' . 'phone_detection');
    $cache->remove();


    // Save the version
    config::save('version',phone_detection::getVersion(),'phone_detection');
    file_put_contents(dirname(__FILE__) . '/../resources/phone_detectiond/version.txt', phone_detection::getVersion()); 

    if (config::byKey('allowUpdateAntennas','phone_detection',0) == 1) {
        log::add('phone_detection','info','Mise a jour des fichiers de toutes les antennes');
        message::add('phone_detection','Mise a jour automatique de vos antennes de fichiers sur vos antennes, relancer leurs dépendances si besoin ...');
        phone_detection::send_allremotes();
    } else {
        message::add('phone_detection','Pensez a mettre a jour vos antennes et relancer leurs dépendances si besoin ...');
    }

    phone_detection::dependancy_install();

    phone_detection::macAddressToUpperCase();

    message::removeAll('Phone_detection');
    message::add('Phone_detection', 'Mise a jour du plugin Phone_detection terminée, vous êtes en version' . phone_detection::getVersion());

    phone_detection::deamon_start();

}

function phone_detection_remove() {
   DB::Prepare('DROP TABLE IF EXISTS `phone_detection_remote`', array(), DB::FETCH_TYPE_ROW);
}

