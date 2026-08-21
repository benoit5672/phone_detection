<?php

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
if(file_exists(__DIR__ . '/../../vendor/autoload.php')){
	require_once __DIR__ . '/../../vendor/autoload.php';
}
require_once dirname(__FILE__) . '/phone_detection_remote.class.php';

class phone_detection extends eqLogic
{

    /*************** Attributs ***************/
    const DEFAULT_ABSENT_INTERVAL = 20;
    const DEFAULT_PRESENT_INTERVAL = 60;
    const DEFAULT_ABSENT_THRESHOLD = 180;
    const DEFAULT_TCP_SERVER_PORT = 55009;

    /************* Static methods ************/


    /**
     * @param string $action: the action to execute, extracted from MQTT or legacy message
     * @param array $params: an array of parameters specific to the action. 
     * @param array &$value: the return information, which is an array or a single value depending on the action
     */
    public static function deamonEventHandler($action, $params, &$value) {

        $antennas = phone_detection_remote::getCacheRemotes('allremotes', array());
        if (config::byKey('noLocal', 'phone_detection', 0) == 0){
            $local = new phone_detection_remote();
            utils::a2o($local, array( 'Id' => 0, 'RemoteName' => 'local'));
            array_push($antennas, $local);
        }
        $value   = 0;
        $success = false;

        switch ($action) {
            case 'update_device_status':
                $source      = $params['source'];
                $isReachable = (bool) ($params['isReachable'] ?? $params['value']);
                $eqLogic = null;
                if (isset($params['macAddress'])) {
                    // v4 version
                    $eqLogics = eqLogic::byTypeAndSearchConfiguration('phone_detection', array('macAddress' => $params['macAddress']));
                    $eqLogic = !empty($eqLogics) ? $eqLogics[0] : null;
                } else {
                    // legacy processing < v4
                    $eqLogic = eqLogic::byId($params['id']);
                }
                if (! is_object($eqLogic)) {
                    log::add('phone_detection', 'debug', 'no eqLogic for ' . print_r($params, true) . '. Ignore');
                    $success = false;
                } else {
                    // found it
                    log::add('phone_detection','info','Update device status (' . (int)$isReachable . ') from antenna ' . $source . ' for ' . $eqLogic->getHumanName());
                    if ($eqLogic->getConfiguration('deviceType') == 'phone' && $eqLogic->getIsEnable()) {
                        foreach ($antennas as $antenna){
                            $from = $antenna->getRemoteName();
                            if ($from == $source){
                                if (method_exists($antenna, 'setCache')) {
                                    $antenna->setCache('lastupdate', date("Y-m-d H:i:s"));
                                }
                                $statePropertyCmd = $eqLogic->getCmd(null, 'state_' . $source);
                                if (!is_object($statePropertyCmd)) {
                                    $statePropertyCmd = new phone_detectionCmd();
                                    $statePropertyCmd->setLogicalId('state_' . $source);
                                    $statePropertyCmd->setIsVisible(0);
                                    $statePropertyCmd->setIsHistorized(0);
                                    $statePropertyCmd->setName(__('Etat_'. $source, __FILE__));
                                    $statePropertyCmd->setType('info');
                                    $statePropertyCmd->setSubType('binary');
                                    $statePropertyCmd->setTemplate('dashboard', 'line');
                                    $statePropertyCmd->setTemplate('mobile', 'line');
                                    $statePropertyCmd->setEqLogic_id($eqLogic->getId());
                                    $statePropertyCmd->save();
                                    $eqLogic->checkAndUpdateCmd($statePropertyCmd, 0);
                                }
                                $currentState = (int)($statePropertyCmd->execCmd() == 1);
                                log::add('phone_detection','info', 'Update value from ' . (int)$currentState . ' to ' . (int)$isReachable . ' for ' . $statePropertyCmd->getHumanName());
                                $eqLogic->checkAndUpdateCmd($statePropertyCmd, $isReachable);
                                $eqLogic->computePresence();
                                phone_detection::updateGlobalDevice();
                                break;
                            }
                        }
                        $success = true;
                    }
                }
                break;

            case 'test':
                $source  = $params['source'];

                log::add('phone_detection','info','Receive a test from antenna ' . $source);
                if ($source != 'local'){
                    foreach ($antennas as $antenna){
                        if ($antenna->getRemoteName() == $source){
                            $antenna->setCache('lastupdate', date("Y-m-d H:i:s"));
                            break;
                        }
                    }
                }
                $value   = 0;
                $success = true;
                break;

            case 'heartbeat':
                $source  = $params['source'];
                $version = $params['version'];
                $alive   = $params['alive'];        
                log::add('phone_detection','debug','This is a heartbeat from antenna ' . $source . ' version=' . $version . ' alive=' . $alive);
                if ($source != 'local'){
                    foreach ($antennas as $antenna){
                        if ($antenna->getRemoteName() == $source){
                            $antenna->setCache('version', $version);
                            if ($alive == 0) {
                                if (phone_detection::stopremote($antenna->getId())) {
                                    log::add('phone_detection', 'error', 'Arret de l\'antenne ' . $antenna->getRemoteName() . ' suite a un probleme reporte par l\'antenne.');
                                    message::add('phone_detection', 'Arret de l\'antenne ' . $antenna->getRemoteName() . ' suite a un probleme reporte par l\'antenne.');
                                }
                            } else {
                                $antenna->setCache('lastupdate', date("Y-m-d H:i:s"));
                            }
                            break;
                        }
                    }
                } else {
                    if ($alive == 0) {
                        log::add('phone_detection', 'error', 'Arret de l\'antenne local suite a un probleme reporte par l\'antenne.');                       
                        phone_detection::deamon_stop();
                        message::add('phone_detection', 'Arret de l\'antenne local suite a un probleme reporte par l\'antenne.');
                    } 
                }
                $success = true;
                $value = 0;
                break;

            case 'get_status':
                $source  = $params['source'];
                $eqLogic = null;
                if (isset($params['macAddress'])) {
                    // v4 version
                    $eqLogics = eqLogic::byTypeAndSearchConfiguration('phone_detection', array('macAddress' => $params['macAddress']));
                    $eqLogic = !empty($eqLogics) ? $eqLogics[0] : null;
                } else {
                    // legacy processing < v4
                    $eqLogic = eqLogic::byId($params['id']);
                }
                if (! is_object($eqLogic)) {
                    log::add('phone_detection', 'debug', 'no eqLogic for ' . print_r($params, true) . '. Ignore');
                    $success = false;
                } else {
               
                    log::add('phone_detection','info','Receive get_status for ' . $eqLogic->getHumanName() . ' from antenna ' . $source);

                    $values = null;
                    foreach ($antennas as $antenna){
                        $from = $antenna->getRemoteName();

                        if ($from == $source){
                            if (method_exists($antenna, 'setCache')) {
                            $antenna->setCache('lastupdate', date("Y-m-d H:i:s"));
                            }
                            $statePropertyCmd = $eqLogic->getCmd(null, 'state_' . $source);
                            if (!is_object($statePropertyCmd)) {
                                $statePropertyCmd = new phone_detectionCmd();
                                $statePropertyCmd->setLogicalId('state_' . $source);
                                $statePropertyCmd->setIsVisible(0);
                                $statePropertyCmd->setIsHistorized(0);
                                $statePropertyCmd->setName(__('Etat_'. $source, __FILE__));
                                $statePropertyCmd->setType('info');
                                $statePropertyCmd->setSubType('binary');
                                $statePropertyCmd->setTemplate('dashboard', 'line');
                                $statePropertyCmd->setTemplate('mobile', 'line');
                                $statePropertyCmd->setEqLogic_id($eqLogic->getId());
                                $statePropertyCmd->save();
                                $eqLogic->checkAndUpdateCmd($statePropertyCmd, 0);
                            }
                            $value = (int) ($statePropertyCmd->execCmd() == 1);
                            break;
                        }
                    }
                    $success = true;
                }
                break;

            case 'refresh_group':
                $source  = $params['source'];

                log::add('phone_detection','info','Receive refresh_group from antenna ' . $source);
                phone_detection::updateGlobalDevice();
                $success = true;
                break;

            case 'get_devices':
                $source  = $params['source'];

                log::add('phone_detection','info','Receive get_devices from antenna ' . $source);
                phone_detection::updateGlobalDevice();
                $devices = eqLogic::byType("phone_detection", true);
                $values = Null;

                foreach($devices as $d) {
                    if ($d->getConfiguration('deviceType') != 'phone' || $d->getIsEnable() == false) {
                        continue;
                    }

                    foreach ($antennas as $antenna){
                        $from = $antenna->getRemoteName();
                        if ($from == $source){
                            if (method_exists($antenna, 'setCache')) {
                            $antenna->setCache('lastupdate', date("Y-m-d H:i:s"));
                            }
                            $statePropertyCmd = $d->getCmd(null, 'state_' . $source);
                            if (!is_object($statePropertyCmd)) {
                                $statePropertyCmd = new phone_detectionCmd();
                                $statePropertyCmd->setLogicalId('state_' . $source);
                                $statePropertyCmd->setIsVisible(0);
                                $statePropertyCmd->setIsHistorized(0);
                                $statePropertyCmd->setName(__('Etat_'. $source, __FILE__));
                                $statePropertyCmd->setType('info');
                                $statePropertyCmd->setSubType('binary');
                                $statePropertyCmd->setTemplate('dashboard', 'line');
                                $statePropertyCmd->setTemplate('mobile', 'line');
                                $statePropertyCmd->setEqLogic_id($d->getId());
                                $statePropertyCmd->save();
                                $d->checkAndUpdateCmd($statePropertyCmd, 0);
                            }
                            $stateValue   = (int)($statePropertyCmd->execCmd() == 1);
                            $getValueDate = $statePropertyCmd->getValueDate();
                            $name         = $d->getName();
                            $humanName    = $d->getHumanName();
                            $id           = $d->getId();
                            $macAddress   = $d->getConfiguration('macAddress');

                            $values[$id] = [
                                'isReachable'   => $stateValue,
                                'lastValueDate' => $getValueDate,
                                'name'          => $name,
                                'humanName'     => $humanName,
                                'deviceId'      => $id,
                                'macAddress'    => $macAddress
                            ];
                            break;
                        }
                    }
                }
                $success = true;
                $value = $values;
                break;
        }
        return $success;
    }



    /**
     * Call the python daemon, local and remotes
     * @param  string $action Action calling.
     * @param  string $args   Other arguments.
     * @return array  Result of the callZiGate.
     */
    public static function callDaemons($action, $args = '')
    {
        log::add('phone_detection', 'debug', 'callDaemons ' . print_r($action, true) . ' ' .print_r($args, true));
        $query = array(
           'action' => $action,
           'args' => $args,
           'apikey' => jeedom::getApiKey('phone_detection')
        );

        if (config::byKey('noLocal', 'phone_detection', 0) == 0){
            $sock = 'tcp://127.0.0.1:' . config::byKey('socketport', 'phone_detection', phone_detection::DEFAULT_TCP_SERVER_PORT);
	        phone_detection::callDaemon($query, $sock);
        }

        $remotes = phone_detection_remote::getCacheRemotes('allremotes',array());
        foreach ($remotes as $remote) {
            phone_detection::callRemoteDaemon($query, $remote);
        }
    }

    /**
     * Call the call Python daemon (local or remote).
     *
     */

    /**
     * @param string $query: the command to execute on the remote antenna.
     * @param string $sock: the socket used to communicate with the remote antenna, using proprietary protocol 
     */
    public static function callDaemon($query, $sock)
    {
        log::add('phone_detection', 'debug', 'callDaemon (' .$sock.') '. print_r($query, true));
        $fp = stream_socket_client($sock, $errno, $errstr, 5);
        $result = '';
        log::add('phone_detection', 'debug', 'error ' . $errno .' : '. $errstr);

        if ($fp) {
            try {
		        stream_set_timeout($fp, 5);
                if (false !== fwrite($fp, json_encode($query))) {
                    while (!feof($fp)) {
                        $result .= fgets($fp, 1024);
	                $info = stream_get_meta_data($fp);
		        if ($info['timed_out']) {
                            log::add('phone_detection', 'info', 'timeout in callDaemon('.$sock.') '.print_r($result, true));
			    $result = '';
		        }
                    }
	        }
            } catch( Exception $ex) {
                log::add('phone_detection', 'info', print_r($ex, true));
            } finally {
                fclose($fp);
            }
        }
        $result = (is_json($result)) ? json_decode($result, true) : $result;
        log::add('phone_detection', 'debug', 'result callDaemon '.print_r($result, true));
        return $result;
    }

    public static function updateGlobalDevice() {

        $devices = eqLogic::byType("phone_detection", true);
        $deviceCount = 0;

        foreach($devices as $d) {
            if ($d->getConfiguration('deviceType') != 'phone') {
                continue;
            }
            $statePropertyCmd = $d->getCmd('info', 'state');
            $stateValue       = (int) ($statePropertyCmd->execCmd() == 1);
            log::add('phone_detection', 'debug', '    processing updateGlobalDevice with ' . $d->getHumanName() . '-->' . $stateValue);
            $deviceCount += $stateValue;
        }

        $globalDevice = self::byLogicalId('GlobalGroup', 'phone_detection');
        if ($globalDevice != 0) {
            $stateCmd = $globalDevice->getCmd('info', 'state');
            $newState = ($deviceCount > 0 ? 1 : 0);
            $globalDevice->checkAndUpdateCmd($stateCmd, $newState);

            $deviceCountCmd = $globalDevice->getCmd('info', 'count');
            $globalDevice->checkAndUpdateCmd($deviceCountCmd, $deviceCount);
            log::add('phone_detection', 'debug', 'updateGlobalDevice: state=' . $newState . '/nb1=' . $deviceCount . '/nbDevices=' .(count($devices) - 1));
        }
    }

    //
    // Gestion des antennes distantes, base sur le plugin BLEA
    //
    /**
     * @param int $_remoteId: the remote uniq identifier in DB 
     */
    public static function sendRemoteFiles($_remoteId) {
        phone_detection::stopremote($_remoteId);
        $remoteObject = phone_detection_remote::byId($_remoteId);
        if ($remoteObject->isRemoteManaged() == false) {
            log::add('phone_detection', 'info', 'L\'antenne ' . $remoteObject->getRemoteName() . ' n\'est pas geree (pas d\'envoie de fichiers).');
            return true;
        }            
        $user=$remoteObject->getConfiguration('remoteUser');
        $script_path = dirname(__FILE__) . '/../../resources/';
        log::add('phone_detection','info','Compression du dossier local');
        exec('tar -zcvf /tmp/folder-phone_detection.tar.gz ' . $script_path);
        log::add('phone_detection','info','Envoie du fichier  /tmp/folder-phone_detection.tar.gz');
        $result = false;
        $result = $remoteObject->execCmd(['sudo rm -Rf /home/'.$user.'/phone_detectiond','mkdir -p /home/'.$user.'/phone_detectiond']);
        if ($remoteObject->sendFiles('/tmp/folder-phone_detection.tar.gz','/home/'.$user.'/folder-phone_detection.tar.gz')) {
            log::add('phone_detection','info',__('Décompression du dossier distant',__FILE__));
            $result = $remoteObject->execCmd(['tar -zxf /home/'.$user.'/folder-phone_detection.tar.gz -C /home/'.$user.'/phone_detectiond','rm -f /home/'.$user.'/folder-phone_detection.tar.gz']);
        }
        log::add('phone_detection','info',__('Suppression du zip local',__FILE__));
        exec('rm -f /tmp/folder-phone_detection.tar.gz');
        log::add('phone_detection','info',__('Finie',__FILE__));
        return $result;
    }

    /**
     * @param int $_remoteId: the remote uniq identifier in DB 
     */
    public static function getRemoteLog($_remoteId, $_dependancy='', $_append=false) {
        $remoteObject = phone_detection_remote::byId($_remoteId);
        if ($remoteObject->isRemoteManaged() == false) {
            log::add('phone_detection', 'info', 'L\'antenne ' . $remoteObject->getRemoteName() . ' n\'est pas geree (pas de recuperation de fichiers de log).');
            return true;
        }
        $name = $remoteObject->getRemoteName();
        $local = dirname(__FILE__) . '/../../../../log/phone_detection_'.str_replace(' ','-',$name).$_dependancy;
        if ($_append == false && file_exists($local)) {
            log::add('phone_detection','info','Suppression de la log ' . $local);
            unlink($local);
        }
        log::add('phone_detection','info',__('Récuperation de la log distante sur '.$name,__FILE__));
        if ($remoteObject->getFiles($local, '/tmp/phone_detection'.$_dependancy, $_append)) {
            $remoteObject->execCmd(['cat /dev/null > /tmp/phone_detection'.$_dependancy]);
            return true;
        }
        return false;
    }

    /**
     * @param int $_remoteId: the remote uniq identifier in DB 
     */
    public static function dependancyRemote($_remoteId) {
        log::add('phone_detection', 'debug', 'entering dependancyRemote');
        phone_detection::stopremote($_remoteId);
        log::add('phone_detection', 'debug', 'remote stopped');
        $remoteObject = phone_detection_remote::byId($_remoteId);
        if ($remoteObject->isRemoteManaged() == false) {
            log::add('phone_detection', 'info', 'L\'antenne ' . $remoteObject->getRemoteName() . ' n\'est pas geree (pas d\'installation de dependances).');
            return true;
        }
        $user = $remoteObject->getConfiguration('remoteUser');
        log::add('phone_detection','info',__('Installation des dépendances sur ' . $remoteObject->getRemoteName(),__FILE__));
        return $remoteObject->execCmd(['bash /home/'.$user.'/phone_detectiond/resources/install_apt.sh /tmp/phone_detection_dependancy 2>&1 &']);
    }

    /**
     * @param int $_remoteId: the remote uniq identifier in DB 
     */
    public static function launchremote($_remoteId) {
        $remoteObject = phone_detection_remote::byId($_remoteId);
        if ($remoteObject->isRemoteManaged() == false) {
            log::add('phone_detection', 'info', 'L\'antenne ' . $remoteObject->getRemoteName() . ' n\'est pas geree (pas de demarrage du demon).');
            return true;
        }
        log::add('phone_detection','info',__('Lancement du démon distant',__FILE__));
        $last = $remoteObject->getCache('lastupdate','0');
        phone_detection::stopremote($_remoteId);
        sleep(5);
        $user   = $remoteObject->getConfiguration('remoteUser');
        $device = $remoteObject->getConfiguration('remoteDevice');
        $ip     = $remoteObject->getConfiguration('remoteIp');
        $script_path = '/home/'.$user.'/phone_detectiond/resources/phone_detectiond';
        $interval = config::byKey('interval', 'phone_detection', phone_detection::DEFAULT_ABSENT_INTERVAL);
        $present_interval = config::byKey('present_interval', 'phone_detection', phone_detection::DEFAULT_PRESENT_INTERVAL);
        $absent_threshold = config::byKey('absent_threshold', 'phone_detection', phone_detection::DEFAULT_ABSENT_THRESHOLD);


        // Use v3 style commands for compatibility, will upgrade to v4 later
        $cmd = 'sudo /usr/bin/python3 ' . $script_path . '/phone_detectiond.py';
        $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel('phone_detection'));
        $cmd .= ' --device ' . $device;
        $cmd .= ' --socketport ' . config::byKey('socketport', 'phone_detection');
        $cmd .= ' --sockethost "' . $ip .'"';
        $cmd .= ' --callback ' . network::getNetworkAccess('internal') . '/plugins/phone_detection/core/php/phone_detection.php';
        $cmd .= ' --apikey ' . jeedom::getApiKey('phone_detection');
        $cmd .= ' --daemonname "' . $remoteObject->getRemoteName() . '"';
        $cmd .= ' --interval ' . $interval;
        $cmd .= ' --present_interval ' . $present_interval;
        $cmd .= ' --absentThreshold ' . $absent_threshold;

        log::add('phone_detection', 'info', 'Using mode: ' . config::byKey('notif_mode', 'phone_detection', 'legacy'));
        if ('mqtt' === config::byKey('notif_mode', 'phone_detection', 'legacy')) {
            $mqtt = mqtt2::getFormatedInfos();
            $mqtt_topic = config::byKey('mqtt_topic', 'phone_detection', __CLASS__);
   			$mqtt_topic = trim($mqtt_topic, '/');

            $cmd .= ' --mqtt-host ' . $mqtt['ip'];
            $cmd .= ' --mqtt-port ' . $mqtt['port'];
            $cmd .= ' --mqtt-protocol ' . $mqtt['protocol'];
            $cmd .= ' --mqtt-username ' . $mqtt['user'];
            $cmd .= ' --mqtt-password ' . $mqtt['password'];
            $cmd .= ' --mqtt-topic ' . $mqtt_topic;
        }

        $cmd .= ' >> ' . '/tmp/phone_detection' . ' 2>&1 &';
        log::add('phone_detection','info','Lancement du démon distant ' . $cmd);
        phone_detection_remote::setCacheRemotes('allremotes',phone_detection_remote::all());
        return $remoteObject->execCmd([$cmd]);
    }

    /**
     * @param int $_remoteId: the remote uniq identifier in DB 
     */
    public static function stopremote($_remoteId) {
        $remoteObject = phone_detection_remote::byId($_remoteId);
        if ($remoteObject->isRemoteManaged() == false) {
            log::add('phone_detection', 'info', 'L\'antenne ' . $remoteObject->getRemoteName() . ' n\'est pas geree (pas d\'arret du deamon).');
            return false;
        }
        log::add('phone_detection','info',__('Arret du demon distant ' . $_remoteId,__FILE__));
        $value = array('apikey' => jeedom::getApiKey('phone_detection'), 'action' => 'stop', 'args' => '');
        phone_detection::callRemoteDaemon($value, $remoteObject);
        $port   = config::byKey('socketport', 'phone_detection', phone_detection::DEFAULT_TCP_SERVER_PORT);
        $remoteObject->execCmd(['fuser -k ' . $port . '/tcp >> /dev/null 2>&1 &']);
        return true;
    }


    /**************** Methods ****************/
    /**
     * Return plugin version.
     *
     * @return string Version of the plugin.
     */
    public static function getVersion()
    {
        $pluginVersion = 'Error';
        if (!file_exists(dirname(__FILE__) . '/../../plugin_info/info.json')) {
            log::add('phone_detection', 'warning', 'Pas de fichier info.json');
        }
        $data = json_decode(file_get_contents(dirname(__FILE__) . '/../../plugin_info/info.json'), true);
        if (!is_array($data)) {
            log::add('phone_detection', 'warning', 'Impossible de décoder le fichier info.json');
        }
        try {
            $pluginVersion = $data['version'];
        } catch (\Exception $e) {
            log::add('phone_detection', 'warning', 'Impossible de récupérer la version.');
        }
        return $pluginVersion;
    }

    /**
     * Get lib dependancy information.
     *
     * @return array Python3 command return.
     */
    public static function dependancy_info()
    {
        $return = [
            'state' => 'nok',
            'log' => 'phone_detection_update',
            'progress_file' => jeedom::getTmpFolder('phone_detection') . '/dependance'
        ];

        $return['state'] = 'ok';

        return $return;
    }

    /**
     * Return information (status) about daemon.
     *
     * @return array Shell command return.
     */
    public static function deamon_info()
    {
        $return = array();
        $return['log'] = 'phone_detection';
        $return['state'] = 'nok';
        if (config::byKey('noLocal', 'phone_detection', 0) == 1){
            $return['state'] = 'ok';
            $return['launchable'] = 'ok';
            return $return;
        }
        $pid_file = jeedom::getTmpFolder('phone_detection') . '/phone_detectiond.pid';
        if (file_exists($pid_file)) {
            if (posix_getsid(trim(file_get_contents($pid_file)))) {
                $return['state'] = 'ok';
            } else {
                shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
            }
        }
        $return['launchable'] = 'ok';

        $btport = config::byKey('btport', 'phone_detection');
        $interval = config::byKey('interval', 'phone_detection', phone_detection::DEFAULT_ABSENT_INTERVAL);
        $present_interval = config::byKey('present_interval', 'phone_detection', phone_detection::DEFAULT_PRESENT_INTERVAL);
        $absent_threshold = config::byKey('absent_threshold', 'phone_detection', phone_detection::DEFAULT_ABSENT_THRESHOLD);
        $port = config::byKey('socketport', 'phone_detection', phone_detection::DEFAULT_TCP_SERVER_PORT);

        if (phone_detection::dependancy_info()['state'] == 'nok') {
            $cache = cache::byKey('dependancy' . 'phone_detection');
            $cache->remove();
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Veuillez (ré-)installer les dépendances', __FILE__);
            return $return;
        }

        if ($btport == "none" || $btport == "" || empty($btport)) {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Veuillez sélectionner un contrôleur bluetooth', __FILE__);
            return $return;
        }

        if($interval == 0 || empty($interval)) {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Veuillez renseigner un interval de mise à jour en absence supérieur à 0', __FILE__);
        }

        if($present_interval == 0 || empty($present_interval)) {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Veuillez renseigner un interval de mise à jour en présence supérieur à 0', __FILE__);
        }

        if($absent_threshold == 0 || empty($absent_threshold)) {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Veuillez renseigner un délai d\'absence supérieur à 0', __FILE__);
        }

        if($port == 0 || empty($port)) {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __('Veuillez renseigner un port (default 55009)', __FILE__);
        }

        if ('mqtt' === config::byKey('notif_mode', 'phone_detection', 'legacy')) {
            if (!class_exists('mqtt2')) {
                $return['launchable'] = 'nok';
                $return['launchable_message'] = __("Le plugin MQTT Manager n'est pas installé", __FILE__);
            } else {
                if (mqtt2::deamon_info()['state'] != 'ok') {
                    $return['launchable'] = 'nok';
                    $return['launchable_message'] = __("Le démon MQTT Manager n'est pas démarré", __FILE__);
                }
            }
        }        

        return $return;
    }
    


    /**
     * Start python daemon.
     *
     * @return array Shell command return.
     */
    public static function deamon_start($_debug = false)
    {
        self::deamon_stop();
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
        }
        $btport = config::byKey('btport', 'phone_detection');
        $deamon_path = dirname(__FILE__) . '/../../resources';
        $interval = config::byKey('interval', 'phone_detection', phone_detection::DEFAULT_ABSENT_INTERVAL);
        $present_interval = config::byKey('present_interval', 'phone_detection', phone_detection::DEFAULT_PRESENT_INTERVAL);
        $absent_threshold = config::byKey('absent_threshold', 'phone_detection', phone_detection::DEFAULT_ABSENT_THRESHOLD);
        $tcpport = config::byKey('socketport', 'phone_detection', phone_detection::DEFAULT_TCP_SERVER_PORT);
        $callback = network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/phone_detection/core/php/phone_detection.php';
    
        // Use v3 style commands for compatibility, will upgrade to v4 later
        $cmd = 'sudo /usr/bin/python3 ' . $deamon_path . '/phone_detectiond/phone_detectiond.py ';
        $cmd .= ' --device ' . $btport;
        $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel('phone_detection'));
        $cmd .= ' --apikey ' . jeedom::getApiKey('phone_detection');
        $cmd .= ' --pidfile ' . jeedom::getTmpFolder('phone_detection') . '/phone_detectiond.pid';
        $cmd .= ' --socketport ' . config::byKey('socketport', 'phone_detection');
        $cmd .= ' --sockethost "127.0.0.1"';
        $cmd .= ' --callback ' . $callback;
        $cmd .= ' --daemonname "local"';
        $cmd .= ' --interval ' . $interval;
        $cmd .= ' --present_interval ' . $present_interval;
        $cmd .= ' --absentThreshold ' . $absent_threshold;

        log::add('phone_detection', 'info', 'Using mode: ' . config::byKey('notif_mode', 'phone_detection', 'legacy'));
        if ('mqtt' === config::byKey('notif_mode', 'phone_detection', 'legacy')) {
            $mqtt = mqtt2::getFormatedInfos();
            $mqtt_topic = config::byKey('mqtt_topic', 'phone_detection', __CLASS__);
   			$mqtt_topic = trim($mqtt_topic, '/');

            $cmd .= ' --mqtt-host ' . $mqtt['ip'];
            $cmd .= ' --mqtt-port ' . $mqtt['port'];
            $cmd .= ' --mqtt-protocol ' . $mqtt['protocol'];
            $cmd .= ' --mqtt-username ' . $mqtt['user'];
            $cmd .= ' --mqtt-password ' . $mqtt['password'];
            $cmd .= ' --mqtt-topic ' . $mqtt_topic;
        }

        log::add('phone_detection', 'info', 'Lancement démon phone_detection : ' . $cmd);
        exec($cmd . ' >> ' . log::getPathToLog('phone_detection') . ' 2>&1 &');
        $i = 0;
        while ($i < 5) {
            $deamon_info = self::deamon_info();
            if ($deamon_info['state'] == 'ok') {
                break;
            }
            sleep(1);
            $i++;
        }

        if ($i >= 5) {
            log::add('phone_detection', 'error', __('Impossible de lancer le démon phone_detection, relancer le démon en debug et vérifiez la log', 'unableStartaemon', __FILE__));
            return false;
        }


        // demarrage des demons distants
        //
        phone_detection_remote::setCacheRemotes('allremotes',phone_detection_remote::all());
        phone_detection::launch_allremotes();
        message::removeAll('phone_detection', 'unableStartDaemon');
        log::add('phone_detection', 'info', 'Démon phone_detection lancé');
        return true;
    }

    public static function launch_allremotes(){
        log::add('phone_detection','info','Launching remotes ...');
        $remotes = phone_detection_remote::all();
        foreach ($remotes as $remote) {
            phone_detection::launchremote($remote->getId());
            sleep(1);
        }
    }

    public static function update_allremotes(){
        log::add('phone_detection','info','Updating remotes ...');
        $remotes = phone_detection_remote::all();
        foreach ($remotes as $remote) {
            phone_detection::dependancyRemote($remote->getId());
            phone_detection::launchremote($remote->getId());
        }
    }

    public static function send_allremotes(){
        log::add('phone_detection','info','Updating files on remotes ...');
        $remotes = phone_detection_remote::all();
        foreach ($remotes as $remote) {
            phone_detection::sendRemoteFiles($remote->getId());
            phone_detection::launchremote($remote->getId());
        }
    }


    public static function stop_allremotes(){
        log::add('phone_detection','info','Stopping remotes ...');
        $remotes = phone_detection_remote::all();
        foreach ($remotes as $remote) {
            phone_detection::stopremote($remote->getId());
        }
    }

  	public static function postConfig_mqtt_topic($_value = null) {
    	if (!class_exists('mqtt2')) {
    	  	return;
    	}
    	if (method_exists('mqtt2', 'removePluginTopicByPlugin')) {
      		mqtt2::removePluginTopicByPlugin(__CLASS__);
    	}
   		if ('mqtt' === config::byKey('notif_mode', 'phone_detection', 'legacy')) {
       		log::add('phone_detection', 'debug', 'Inscription au plugin mqtt2');
            $mqtt_topic = config::byKey('mqtt_topic', 'phone_detection', __CLASS__);
   	    	$mqtt_topic = trim($mqtt_topic, '/');        
   		    mqtt2::addPluginTopic(__CLASS__, $mqtt_topic);
        }
  	}  

    /**
     * @param string $_datas: an array representing the MQTT message. The data contains a json message
     */
    public static function handleMqttMessage($_datas) {

        try {
            // If $_datas is already an array, do not decode it again.
            $data = is_string($_datas)
                ? json_decode($_datas, true, 512, JSON_THROW_ON_ERROR)
                : $_datas;

            log::add('phone_detection', 'debug', 'Receiving MQTT message: ' . json_encode($data));

            $rootTopic = config::byKey('mqtt_topic', 'phone_detection', __CLASS__);
   			$rootTopic = trim($rootTopic, '/');

            // 1. On verfiie qu'on est bien dans notre base topic.
            if (!isset($data[$rootTopic]) || !is_array($data[$rootTopic])) {
                log::add('phone_detection', 'debug', 'MQTT message received, but root topic is not for phone_detection');
                return;
            }    

            // 2. On parcours les antennes.
            foreach ($data[$rootTopic] as $antenna => $antennaData) {

                if (!is_array($antennaData)) {
                    log::add('phone_detection', 'warning', 'Message MQTT invalide (' . $antenna . '(' . print_r($antennaData, true) . ')');
                    continue;
                }

                // 3. On parcours les types de message ("hearbeat" ou "status")
                foreach ($antennaData as $messageType => $payloadData) {
                    $retval = 0;
                    switch ($messageType) {
                        case 'status':
                            //4. On a le mobile humanName
                            foreach ($payloadData as $deviceName => $devicePayload) {
                                if (!is_array($devicePayload)) {
                                    log::add('phone_detection', 'warning', 'Message MQTT status invalide (' . $antenna . '(' . print_r($antennaData) . ')');
                                    continue;
                                }
                                // On injecte le nom de l'antenne et l'identifiant du device 
                                // dans le payload pour que la méthode update_device_status ait toutes les infos
                                $devicePayload['source'] ??= $antenna;
                                $devicePayload['name'] ??= $deviceName;
                                phone_detection::deamonEventHandler('update_device_status', $devicePayload, $retval);
                            }                                      
                            break;

                        case 'heartbeat':
                            $payloadData['source'] ??= $antenna;
                            phone_detection::deamonEventHandler('heartbeat', $payloadData, $retval);
                            break;

                        default:
                            log::add('phone_detection', 'warning', 'Unexpected MQTT message type: ' . $messageType . ', antenna: ' . $antenna . ', payload: ' . json_encode($payloadData));
                            break;
                    }
                }
            }
        } catch (JsonException $e) {
            log::add('phone_detection', 'error', 'Invalid MQTT JSON: ' . $e->getMessage());
        }            
    }


    public static function macAddressToUpperCase() {

        $allEqlogic = eqLogic::byType('phone_detection');
        foreach ($allEqlogic as $eqLogic) {
            $macAddress = $eqLogic->getConfiguration('macAddress');
            $eqLogic->setConfiguration('macAddress', strtoupper($macAddress));
        }
    }

    public static function health() {
        $return = array();
        $remotes = phone_detection_remote::getCacheRemotes('allremotes',array());
        if (count($remotes) !=0){
            $return[] = array(
                'test' => __('Nombre d\'antennes', __FILE__),
                'result' => count($remotes),
                'advice' =>  '',
                'state' => True,
            );
            foreach ($remotes as $remote){
                $last = $remote->getCache('lastupdate','0');
                $name = $remote->getRemoteName();
                if ($last == '0' or time() - strtotime($last)>60){
                    $result = 'NOK';
                    $advice = __('Vérifier le démon sur votre antenne',__FILE__);
                    $state = False;
                } else {
                    $result = 'OK';
                    $advice = '';
                    $state = True;
                }
                $return[] = array(
                    'test' => __('Démon ' . $name, __FILE__),
                    'result' => $result,
                    'advice' =>  $advice,
                    'state' =>$state,
                );
            }
        }
        return $return;
    }

    public static function cron() {
        $remotes = phone_detection_remote::getCacheRemotes('allremotes',array());
        $allEqlogic = eqLogic::byType('phone_detection');
        foreach ($remotes as $remote) {
            if (!is_object($remote)) {
                continue;
            }
            $last = $remote->getCache('lastupdate','0');
            if (($last == '0' or time() - strtotime($last) > 65)) {
                $auto = $remote->getConfiguration('remoteDaemonAuto','0');
                foreach ($allEqlogic as $eqLogic){
                    $stateCmd = $eqLogic->getCmd(null, 'state_' . $remote->getRemoteName());
                    $eqLogic->checkAndUpdateCmd($stateCmd, 0);
                    $eqLogic->computePresence();
                }
                // Update the global presence indicator
                phone_detection::updateGlobalDevice();
                if ($auto == 1){
                    log::add('phone_detection','info','Restarting daemon on remote ' . $remote->getRemoteName());
                    phone_detection::launchremote($remote->getId());
                }
            }
        }
        $deamon_info = self::deamon_info();
        if ($deamon_info['state'] != 'ok'){
            foreach ($allEqlogic as $eqLogic){
                $stateCmd = $eqLogic->getCmd(null, 'state_local');
                $eqLogic->checkAndUpdateCmd($stateCmd, 0);
                $eqLogic->computePresence();
            }
            // Update the globalPresence indicator
            phone_detection::updateGlobalDevice();
        }
    }

    public static function cron15() {
        $remotes = phone_detection_remote::getCacheRemotes('allremotes',array());
        $availremote= array();
        foreach ($remotes as $remote) {

	        if (is_object($remote) && method_exists($remote, 'getRemoteName')) {
                $availremote[] = $remote->getRemoteName();
                self::getRemoteLog($remote->getId(), '', true);
	        }
        }
        foreach (eqLogic::byType('phone_detection') as $eqLogic){
            foreach ($eqLogic->getCmd('info') as $cmd) {
                $logicalId = $cmd->getLogicalId();
                if (substr($logicalId,0,6) == 'state_') {
                    $remotename= substr($logicalId,6);
                    if ($remotename != 'local' && !(in_array($remotename,$availremote))){
                        $cmd->remove();
                    } else if ($remotename == 'local') {
                        if (config::byKey('noLocal', 'phone_detection', 0) == 1){
                            $cmd->remove();
                        }
                    }
                }
            }
        }
    }

    /**
     * @param string $query: the command to execute on the remote host
     * @param phone_detection_remote $remote: the name of the remote host (the antenna name)
     */
    public static function callRemoteDaemon($query, $remote) {
        $ip = $remote->getConfiguration('remoteIp');
        if (isset($ip)) {
            $sock = 'tcp://' . $ip . ':' . config::byKey('socketport', 'phone_detection', phone_detection::DEFAULT_TCP_SERVER_PORT);
            $remote->setCache('lastupdate','0');
            phone_detection::callDaemon($query, $sock);
        }
    }

    /**
     * @param string $_level log level (debug, info, warning, error, critical)
     */
    public static function changeLogLive($_level) {
        phone_detection::callDaemons($_level);
    }


    public function computePresence() {
        if ($this->getConfiguration('deviceType') != 'phone') {
            return;
        }
        $globalState = 0;
        $stateCmd = $this->getCmd(null, 'state');
        if (!is_object($stateCmd)) {
            $stateCmd = new phone_detectionCmd();
            $stateCmd->setLogicalId('state');
            $stateCmd->setIsVisible(0);
            $stateCmd->setIsHistorized(0);
            $stateCmd->setName(__('Etat', __FILE__));
            $stateCmd->setType('info');
            $stateCmd->setSubType('binary');
            $stateCmd->setTemplate('dashboard','line');
            $stateCmd->setTemplate('mobile','line');
            $stateCmd->setEqLogic_id($this->getId());
            $stateCmd->save();
            $this->checkAndUpdateCmd($stateCmd, 0);
        }
        if ($stateCmd->getConfiguration('returnStateValue') == 0 || $stateCmd->getConfiguration('returnStateTime') == 2){
            $stateCmd->setConfiguration('returnStateValue','');
            $stateCmd->setConfiguration('returnStateTime','');
            $stateCmd->save();
        }
        foreach ($this->getCmd('info') as $cmd) {
            if (substr($cmd->getLogicalId(),0,6) == 'state_' && $cmd->getLogicalId() != 'state'){
                $globalState += (int) ($cmd->execCmd() == 1);
            }
        }
        log::add('phone_detection', 'debug', 'computePresence = ' . $globalState);
        $this->checkAndUpdateCmd($stateCmd, ($globalState > 0 ? 1 : 0));
    }

    /// END REMOTE ANTENNAS

    /**
     * Stop python daemon.
     *
     * @return array Shell command return.
     */
    public static function deamon_stop()
    {
        $deamon_info = self::deamon_info();
        $pid_file = jeedom::getTmpFolder('phone_detection') . '/phone_detectiond.pid';
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file)));
            system::kill($pid);
        }

        // Check if we have a local daemon which is supposed to run
        if (config::byKey('noLocal', 'phone_detection', 0) == 1){
            return;
        }
        
        $i = 0;
        while ($i < 5) {
            $deamon_info = self::deamon_info();
            if ($deamon_info['state'] == 'nok') {
                break;
            }
            sleep(1);
            $i++;
        }
        if ($i >= 5) {
            log::add('phone_detection', 'error', __('Impossible d\'arrêter le démon phone_detection, tuons-le', __FILE__));
            system::kill('phone_detectiond.py');
        }
    }



    /**
     * Install dependancies.
     *
     * @return array Shell script command return.
     */
    public static function dependancy_install()
    {
        log::remove(__CLASS__ . '_update');
        return [
            'script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder('phone_detection') . '/dependance',
            'log' => log::getPathToLog(__CLASS__ . '_update')
        ];
    }

    public function postInsert() {
        log::add('phone_detection', 'debug', 'postInsert()');
        if( $this->getConfiguration('deviceType') == 'phone') {
            phone_detection::callDaemons('insert_device',
                [
                    $this->getId(),
                    $this->getName(),
                    $this->getConfiguration('macAddress')
                ]
            );
        }
    }

    public function preRemove() {
        log::add('phone_detection', 'debug', 'preRemove()');
        if( $this->getConfiguration('deviceType') == 'phone') {
            phone_detection::callDaemons('remove_device',
                [
                    $this->getId(),
                    $this->getName(),
                    $this->getConfiguration('macAddress')
                ]
            );
        }
    }

    public function postUpdate()
    {
        log::add('phone_detection', 'debug', 'postUpdate()');

        if (empty($this->getConfiguration('deviceType'))) {
            log::add('phone_detection', 'info', 'deviceType must be set to phone');
            $this->setConfiguration('deviceType', 'phone');
            $this->save();
        }

        $deviceType = $this->getConfiguration('deviceType');

        if ($deviceType == 'phone') {

            $getDataCmd = $this->getCmd(null, 'state');
            if (!is_object($getDataCmd)) {
                // Création de la commande
                $cmd = new phone_detectionCmd();
                // Nom affiché
                $cmd->setName('Etat');
                // Identifiant de la commande
                $cmd->setLogicalId('state');
                // Identifiant de l'équipement
                $cmd->setEqLogic_id($this->getId());
                // Type de la commande
                $cmd->setType('info');
                // Sous-type de la commande
                $cmd->setSubType('binary');
                // Visibilité de la commande
                $cmd->setIsVisible(1);
                // Sauvegarde de la commande
                $cmd->save();
            }
            $getDataCmd = $this->getCmd(null, 'refresh');
            if (!is_object($getDataCmd)) {
                // Création de la commande
                $cmd = new phone_detectionCmd();
                // Nom affiché
                $cmd->setName('Rafraichir');
                // Identifiant de la commande
                $cmd->setLogicalId('refresh');
                // Identifiant de l'équipement
                $cmd->setEqLogic_id($this->getId());
                // Type de la commande
                $cmd->setType('action');
                // Sous-type de la commande
                $cmd->setSubType('other');
                // Visibilité de la commande
                $cmd->setIsVisible(1);
                // Sauvegarde de la commande
                $cmd->save();
            }

            if ($this->getIsEnable()) {
                phone_detection::callDaemons('update_device',
                    [
                        $this->getId(),
                        $this->getName(),
                        $this->getConfiguration('macAddress')
                    ]
                );
            } else {
                phone_detection::callDaemons('remove_device',
                [
                    $this->getId(),
                    $this->getName(),
                    $this->getConfiguration('macAddress')
                ]
            );
            }
        }

        if ($deviceType == 'GlobalGroup') {
            $getRefreshCmd = $this->getCmd(null, 'refresh');
            if (is_object($getRefreshCmd)) {
                $getRefreshCmd->remove();
            }
        }

        self::createGlobalGroup();
    }

    private static function createGlobalGroup() {
        if (is_object(self::byLogicalId('GlobalGroup', 'phone_detection'))) {
            return;
        }

        try {
            log::add('phone_detection', 'debug', 'create Global Group device');

            $group = new self();
            $group->setLogicalId('GlobalGroup');

            log::add('phone_detection', 'debug', '   --> set Name');
            $group->setName('Tous les téléphones');

            log::add('phone_detection', 'debug', '   --> set eqTypeName');
            $group->setEqType_name('phone_detection');

            log::add('phone_detection', 'debug', '   --> set deviceType');
            $group->setConfiguration('deviceType', 'GlobalGroup');

            log::add('phone_detection', 'debug', '   --> set visible = 0');
            $group->setIsVisible(0);

            log::add('phone_detection', 'debug', '   --> set enable = 1');
            $group->setIsEnable(1);

            log::add('phone_detection', 'debug', '   --> set category ');
            $group->setConfiguration('category', 'group');

            log::add('phone_detection', 'debug', '   --> set group id');
            $group->setConfiguration('id', 0);

            $group->save();
            $group = self::byLogicalId('GlobalGroup', 'phone_detection');

            $getDataCmd = $group->getCmd(null, 'state');
            if (!is_object($getDataCmd)) {
                // Création de la commande
                $cmd = new phone_detectionCmd();
                // Nom affiché
                $cmd->setName('Etat');
                // Identifiant de la commande
                $cmd->setLogicalId('state');
                // Identifiant de l'équipement
                $cmd->setEqLogic_id($group->getId());
                // Type de la commande
                $cmd->setType('info');
                // Sous-type de la commande
                $cmd->setSubType('binary');
                // Visibilité de la commande
                $cmd->setIsVisible(1);
                // Sauvegarde de la commande
                $cmd->save();
            }

            $getDataCmd = $group->getCmd(null, 'count');
            if (!is_object($getDataCmd)) {
                // Création de la commande
                $cmd = new phone_detectionCmd();
                // Nom affiché
                $cmd->setName('Nombre de téléphones présents');
                // Identifiant de la commande
                $cmd->setLogicalId('count');
                // Identifiant de l'équipement
                $cmd->setEqLogic_id($group->getId());
                // Type de la commande
                $cmd->setType('info');
                // Sous-type de la commande
                $cmd->setSubType('numeric');
                // Visibilité de la commande
                $cmd->setIsVisible(1);
                // Sauvegarde de la commande
                $cmd->save();
            }

            $getRefreshCmd = $group->getCmd(null, 'refresh');
            if (is_object($getRefreshCmd)) {
                $getRefreshCmd->remove();
                $group->save();
            }

        } catch( Exception $ex) {
            log::add('phone_detection', 'debug', print_r($ex, true));
        }
    }

    /********** Getters and setters **********/

}

class phone_detectionCmd extends cmd
{

    /*************** Attributs ***************/

    /************* Static methods ************/

    /**************** Methods ****************/

    public function execute($_options = array()) {
        log::add('phone_detection', 'debug', 'cmdId:' . $this->getLogicalId());

        // Test pour ne répondre qu'à la commande rafraichir
        if ($this->getLogicalId() == 'refresh') {
            // On récupère l'équipement à partir de l'identifiant fournit par la commande
            $phone_detectionObj = phone_detection::byId($this->getEqlogic_id());
            log::add('phone_detection', 'debug', 'eqLogic Id:' . $this->getEqLogic_id());

            if ($phone_detectionObj->getConfiguration('deviceType') == 'phone') {

                // On récupère la commande 'data' appartenant à l'équipement
                $dataCmd = $phone_detectionObj->getCmd('info', 'state');

                // On récupère la mac address de l'équipement
                $macAddress = $phone_detectionObj->getConfiguration('macAddress');
                log::add('phone_detection','debug', 'mac address: '.$macAddress);

                // On ping le device pour savoir s'il est là
                $btController = config::byKey('btport', 'phone_detection');

                // $btController = $phone_detectionObj->getConfiguration('btport');
                log::add('phone_detection','info', 'BT Device: '.$btController);

                $btController = ( $btController == '' ? 'hci0' : $btController );

                $name = shell_exec("sudo hcitool -i ". $btController ." name " . $macAddress);
                log::add('phone_detection', 'debug', 'device name: '. $name);

                $state = (empty($name) ? 0 : 1);
                log::add('phone_detection', 'debug', 'device state: '. $state);
            }
        }
        phone_detection::updateGlobalDevice();
    }
    /********** Getters and setters **********/
}
