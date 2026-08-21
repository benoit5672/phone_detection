<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */

require_once __DIR__  . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/../../vendor/autoload.php';


use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;

class phone_detection_remote {
	/*     * *************************Attributs****************************** */
	private $id;
	private $remoteName;
	private $configuration;

	/*     * ***********************Methode static*************************** */

	public static function byId($_id) {

		$values = array(
			'id' => $_id,
		);
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
		FROM phone_detection_remote
		WHERE id=:id';
		return DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function all() {
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
		FROM phone_detection_remote';
		return DB::Prepare($sql, array(), DB::FETCH_TYPE_ALL, PDO::FETCH_CLASS, __CLASS__);
	}

	/*     * *********************Methode d'instance************************* */

	public function save() {
		DB::save($this);
		self::setCacheRemotes('allremotes',self::all());
		return;
	}

	public function remove() {
		DB::remove($this);
		return self::setCacheRemotes('allremotes',self::all());
	}

	public function getCache($_key = '', $_default = '') {
		$cache = cache::byKey('PhoneDetectionPluginRemote' . $this->getId())->getValue();
		return utils::getJsonAttr($cache, $_key, $_default);
	}

	public function setCache($_key, $_value = null) {
		cache::set('PhoneDetectionPluginRemote' . $this->getId(), utils::setJsonAttr(cache::byKey('PhoneDetectionPluginRemote' . $this->getId())->getValue(), $_key, $_value));
	}

	public static function getCacheRemotes($_key = '', $_default = '') {
		$cache = cache::byKey('PhoneDetectionPluginRemotes')->getValue();
		return utils::getJsonAttr($cache, $_key, $_default);
	}

	public static function setCacheRemotes($_key, $_value = null) {
		cache::set('PhoneDetectionPluginRemotes', utils::setJsonAttr(cache::byKey('PhoneDetectionPluginRemotes')->getValue(), $_key, $_value));
	}

	public function execCmd($_cmds) {

		if ($this->isRemoteManaged() == false) {
            log::add('phone_detection', 'info', 'L\'antenne ' . $this->getRemoteName() . ' n\'est pas geree (pas d\'envoie de commandes).');
			return true;
		}
		$ip = $this->getConfiguration('remoteIp');
		$port = $this->getConfiguration('remotePort', 22);
		$user = $this->getConfiguration('remoteUser');
		$pass = $this->getConfiguration('remotePassword');
		$outputs = [];
	
		$ssh = new SSH2($ip, $port, 30);
		if (!$ssh->login($user, $pass)) {
		  $error = "Authentication SSH KO for {$user}@{$ip}:{$port}; please check user and password.";
		  log::add('phone_detection', 'error', $error, 'authentication failed');
		  throw new Exception($error);
		}
	
		$output = [];
		foreach ($_cmds as $cmd) {
		  	$cmd = str_replace("{user}", $user, $cmd);
		  	log::add('phone_detection', 'info', __('Commande par SSH ',__FILE__) . $cmd .  __(' sur ',__FILE__) . $ip);
		  	$output = $ssh->exec($cmd);
		  	if (trim($output) != '') {
				log::add('phone_detection','debug',$output);
			}		 
			$outputs[] = explode("\n", $output);
		}
		return $outputs;
	}

	public function sendFiles($_local, $_target) {

		if ($this->isRemoteManaged() == false) {
            log::add('phone_detection', 'info', 'L\'antenne ' . $this->getRemoteName() . ' n\'est pas geree (pas d\'envoie de fichiers).');
			return true;
		}		
		$ip = $this->getConfiguration('remoteIp');
		$port = $this->getConfiguration('remotePort', 22);
		$user = $this->getConfiguration('remoteUser');
		$pass = $this->getConfiguration('remotePassword');

		$sftp = new SFTP($ip, $port, 30);
		if ($sftp->login($user, $pass)) {
			log::add('phone_detection', 'debug', "send file {$_local} to {$ip}:{$_target}");
			return $sftp->put($_target, $_local, SFTP::SOURCE_LOCAL_FILE);
		}
		log::add('phone_detection', 'debug', "login failed, could not send file {$_target}");
		return false;
	  }	



	private function appendFileContents($sourceFile, $targetFile) {
		$source = null;
		$target = null;
		try {
			// Open the source file in read mode and the target file in append mode
			$source = fopen($sourceFile, 'r');
			$target = fopen($targetFile, 'a');
	
			if ($source && $target) {
				// Read the content of the source file and append it to the target file
				while (($line = fgets($source)) !== false) {
					fwrite($target, $line);
				}
			} else {
				log::add('phone_detection', 'error', 'Error opening files: ' . $sourceFile . ' or ' . $targetFile);
			}
		} catch (Exception $e) {
			log::add('phone_detection', 'error', 'An error occurred: ' . $e->getMessage());
		}
		finally {
			if (null != $source) {
				fclose($source);
			}
			if (null != $target) {
				fclose($target);
			}
		}
	}

	public function getFiles($_local, $_target, $_append=false) {
		if ($this->isRemoteManaged() == false) {
            log::add('phone_detection', 'info', 'L\'antenne ' . $this->getRemoteName() . ' n\'est pas geree (pas de recuperation de fichiers).');
			return true;
		}

		$ip = $this->getConfiguration('remoteIp');
		$port = $this->getConfiguration('remotePort', 22);
		$user = $this->getConfiguration('remoteUser');
		$pass = $this->getConfiguration('remotePassword');

		if ($_append == false) {
			$localFile = $_local;
		} else {
			$localFile = jeedom::getTmpFolder('phone_detection') . basename($_local);
		}

		$sftp = new SFTP($ip, $port, 30);
		if ($sftp->login($user, $pass)) {
		  	log::add('phone_detection', 'debug', "get file '{$_target}' from {$ip}");
		  	if (!file_exists($localFile)) {
				touch($localFile);
			}
			return $sftp->get($_target, $localFile);
		} else {
			log::add('phone_detection', 'debug', "login failed, could not get file {$_target}");
			return false;
		}		
		// Append the file if needed
		if ($_append == true) {
			$this->appendFileContents($localFile, $_local);
			unlink($localFile);
		}
		return true;
	}	

	/*     * **********************Getteur Setteur*************************** */

	public function getId() {
		return $this->id;
	}

	public function setId($id) {
		$this->id = $id;
		return $this;
	}

	public function getRemoteName() {
		return $this->remoteName;
	}

	public function setRemoteName($name) {
		$this->remoteName = $name;
		return $this;
	}

	public function getConfiguration($_key = '', $_default = '') {
		return utils::getJsonAttr($this->configuration, $_key, $_default);
	}

	public function setConfiguration($_key, $_value) {
		$this->configuration = utils::setJsonAttr($this->configuration, $_key, $_value);
		return $this;
	}
	
	public function isRemoteManaged() {
		return (null != $this->getConfiguration('remoteIp'));
	}
}	
