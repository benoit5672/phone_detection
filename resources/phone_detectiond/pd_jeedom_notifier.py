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
Classe extraite du fichier principal pour plus de clarte.

Cette classe permet de notifier Jeedom a partir du demon en utilisant un protocol proprietaire
"""

import logging
import requests
import time
import json


from pd_global import PLUGIN_NAME
from pd_base_notifier import BaseNotifier

class JeedomNotifier(BaseNotifier):
    def __init__(self, apiKey, url, deamonname):
        logging.info('Create jeedom connector for {}'.format(PLUGIN_NAME))
        self.apikey = apiKey
        self.url = url
        self.daemonname = deamonname;


    def __send(self, m):
        response = None
        m['source'] = self.daemonname;
        for i in range (0,3):
            logging.debug('Send to jeedom :  {}'.format(m))
            r = requests.post('{}?apikey={}'.format(self.url, self.apikey), data=json.dumps(m), verify=False)
            logging.debug('Status Code :  {}'.format(r.status_code))
            if r.status_code != 200:
                logging.error('Error on send request to jeedom, return code {} - {}'.format(r.status_code, r.reason))
                time.sleep(0.150)
            else:
                response = r.json()
                logging.debug('Jeedom reply :  {}'.format(response))
                break
        return response


    def heartbeat(self, isMonitoringAlive, version):
        logging.debug('jeedom-heartbeat: alive: {}, version: {}'.format(bool(isMonitoringAlive), version))
        r = self.__send({'action':'heartbeat', 'version': version, 'alive': isMonitoringAlive})
        if not r or not r.get('success'):
            logging.error('Error during heartbeat')
            return False
        return True

    def setDeviceStatus(self, device):
        logging.debug('jeedom-notifier[{}]: isReachable: {}'.format(device.deviceId, bool(device.isReachable)))
        if device.pluginVersion < 4:
            msg = {'action': 'update_device_status', 'id' : device.deviceId, 'value': (0,1)[device.isReachable]}
        else:
            msg = {'action': 'update_device_status', 'name' : device.humanName, 'isReachable': (0,1)[device.isReachable], 'macAddress': device.macAddress, 'lastStateDate': device.lastStateDate.isoformat()}

        r = self.__send(msg)
        if not r or not r.get('success'):
            logging.error('Error during update status')
            return False
        return True    

