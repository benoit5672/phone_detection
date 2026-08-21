#!/usr/bin/env python3
# -*- coding:utf-8 -*-
#
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
# of the Software, and to permit persons to whom the Software is furnished to do so,
# subject to the following conditions:
#
# The above copyright notice and this permission notice shall be included in all copies
# or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
# WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR
# IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE
#
"""
2026/07/31: Benoit Rech

Version initiale permettant de transmettre les changements d'etat au format MQTT.
"""

import paho.mqtt.client as mqtt
import logging
import json
import time
from datetime import datetime, timezone

from pd_base_notifier import BaseNotifier
from pd_global import PLUGIN_NAME, DATE_FORMAT, DEVICES


class MqttNotifier(BaseNotifier):
    def __init__(self, host, port, baseTopic, daemonname, username = None, passwd = None):
        logging.debug('Create MQTT connector with client-id: {}-{}, base-topic: {}'.format(PLUGIN_NAME, daemonname, baseTopic.rstrip('/')))
        self.host = host
        self.port = int(port)
        self.baseTopic = baseTopic.rstrip('/')
        self.daemonname = daemonname
        client_id = '{}-{}'.format(PLUGIN_NAME, daemonname)
        try:
            self.client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1, client_id=client_id)
        except AttributeError:
            self.client = mqtt.Client(client_id=client_id)    

        self.client.on_connect = self.on_connect
        self.client.on_disconnect = self.on_disconnect        
        if username and passwd:
            logging.debug('Using user name: {}, password: ******'.format(username))
            self.client.username_pw_set(username=username, password=passwd)
        self.client.connect(self.host, self.port, 60)
        self.client.loop_start()

    def on_connect(self, client, userdata, flags, rc):
        if rc == mqtt.MQTT_ERR_SUCCESS:
            logging.info('MQTT connected to {}:{}'.format(self.host, self.port))
        else:
            logging.error('MQTT connection refused: rc={} ({})'.format(rc, mqtt.error_string(rc)))

    def on_disconnect(self, client, userdata, rc):
        logging.warning('MQTT disconnected: rc={} ({})'.format(rc, mqtt.error_string(rc)))


    def __publish_json(self, topic, payload):
        if not self.client.is_connected():
            logging.error('MQTT publish aborted: client is not connected')
            return False

        message = json.dumps(payload, separators=(',', ':'))

        try:
            info = self.client.publish(topic, message, qos=0, retain=False)
            logging.debug('MQTT publish queued: topic=[{} payload={} mid={} rc={} ({})'.format(topic, message, info.mid, info.rc, mqtt.error_string(info.rc)))
     
            if info.rc != mqtt.MQTT_ERR_SUCCESS:
                logging.error('MQTT publish rejected locally: {}'.format(mqtt.error_string(info.rc)))
                return False

            #info.wait_for_publish(timeout=5.0)
            deadline = time.monotonic() + 5.0
            while not info.is_published():
                if time.monotonic() >= deadline:
                    logging.error('MQTT publish timeout: topic={} mid={}'.format(topic, info.mid))
                    return False

                time.sleep(0.05)            

            if not info.is_published():
                logging.error('MQTT publish timeout: topic={} mid={}'.format(topic, info.mid))
                return False

            logging.debug('MQTT publish confirmed: topic={} mid={}'.format(topic, info.mid))
            return True

        except Exception as e:
            logging.error('MQTT publish exception on topic {}: {}'.format(topic, e))
            return False    

    def stop(self):
        try:
            self.client.loop_stop()
            self.client.disconnect()
        except Exception as e:
            logging.error('Error stopping MQTT client: {}'.format(e))

    def heartbeat(self, isMonitoringAlive, version):
        logging.debug('mqtt-hearbeat: alive: {}, version: {}'.format(bool(isMonitoringAlive), version))
        topic = '{}/{}/heartbeat'.format(self.baseTopic, self.daemonname)
        payload = {
            'alive': bool(isMonitoringAlive),
            'version': version,
            'timestamp': datetime.now(timezone.utc).isoformat()
        }
        return self.__publish_json(topic, payload)



    def setDeviceStatus(self, device):
        logging.debug('mqtt-notifier[{}]: isReachable: {}'.format(device.humanName, bool(device.isReachable)))
        topic = '{}/{}/status/{}'.format(self.baseTopic, self.daemonname, device.humanName)
        payload = {
            'macAddress': device.macAddress,
            'isReachable': bool(device.isReachable),
            'lastStateDate': datetime.fromtimestamp(device.lastStateDate).isoformat()
        }
        return self.__publish_json(topic, payload)
