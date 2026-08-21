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

Cette classe permet de communiquer avec Jeedom a partir du demon
"""

import logging
import requests
import time
import json
from datetime import datetime, timezone

from pd_phone import Phone
from pd_global import PLUGIN_NAME, DATE_FORMAT

class JeedomConnector():
    def __init__(self, apikey, url, daemonname, absentInterval):
        self.apikey = apikey
        self.url = url
        self.daemonname = daemonname;
        self.absentInterval = absentInterval

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

    def test(self):
        logging.debug('Send to test connection to jeedom')
        r = self.__send({'action': 'test'})
        if not r or not r.get('success'):
            logging.error('Calling jeedom failed')
            return False
        return True

    def updateGlobalDevice(self):
        r = self.__send({'action': 'refresh_group'})
        if not r or not r.get('success'):
            logging.error('Error during updateGlobalDevice')
            return False
        return True

    def getDevices(self):
        logging.info('Get devices from Jeedom')
        devices = self.__send({'action':'get_devices'})
        if not devices or not devices.get('success'):
            logging.error('FAILED')
            return {}
        # values = json.loads(devices)
        r = {}
        for key in devices['value']:
            item = devices['value'][key]
            if 'id' in item:
                pluginVersion = 3
                id = item['id']
                isReachable = item['state']
            else:
                pluginVersion = 4
                id = item['deviceId']
                isReachable = item['isReachable']

            r[key] = Phone(item['macAddress'], id)
            r[key].humanName = item['name']
            #r[key].isReachable = isReachable
            r[key].isReachable = 0
            r[key].isReachableLastPolling = False
            r[key].thresholdInterval = self.absentInterval
            r[key].pluginVersion = pluginVersion
            try:
                r[key].lastStateDate = int(datetime.strptime(item['lastValueDate'], DATE_FORMAT).timestamp())
            except:
                r[key].lastStateDate = int(datetime.now(timezone.utc).timestamp())
            logging.debug('Adding device: {}, mac: {}, reachable: {}'.format(r[key].humanName, r[key].macAddress, r[key].isReachable))
        return r
