"""
Classe permettant de regrouper les informations d'un téléphone
"""

import logging
from datetime import datetime, timezone
from pd_global import ABSENT_THRESHOLD


class Phone:
    def __init__(self, macAddress, deviceId):
        currentTime = int(datetime.now(timezone.utc).timestamp())
        self.macAddress = macAddress.upper()
        self.deviceId = deviceId
        self.humanName = ''
        self.isReachable = False
        self.isReachableLastPolling = False
        self.lastStateDate = currentTime
        self.lastRefreshDate = currentTime
        self.lastPollDate = currentTime - 3600
        self.mustUpdate = False
        self.pluginVersion = 0

    def setReachable(self):
        self.lastStateDate = int(datetime.now(timezone.utc).timestamp())
        self.isReachableLastPolling = True
        if not self.isReachable:
            self.isReachable = True
            logging.info('[{}] Set "{}" phone present [{}]'.format(self.deviceId, self.humanName, self.macAddress))
            self.mustUpdate = True
            return True

        self.mustUpdate = False
        return False

    def setNotReachable(self):
        thresholdDate = self.lastStateDate + ABSENT_THRESHOLD
        currentTime = int(datetime.now(timezone.utc).timestamp())
        logging.debug('[{}]: lastStateDate: {}'.format(self.deviceId, self.lastStateDate))
        logging.debug('[{}]: thresholdDate: {}'.format(self.deviceId, thresholdDate))
        logging.debug('[{}]: UTC seconds: {}'.format(self.deviceId, currentTime))
        logging.debug('[{}]: isReachableLastPolling: {}'.format(self.deviceId, self.isReachableLastPolling))
        logging.debug('[{}]: isReachable: {}, is UTC seconds > thresholdDate ? {}'.format(self.deviceId, self.isReachable, currentTime >= thresholdDate))
        self.isReachableLastPolling = False
        if self.isReachable and currentTime >= thresholdDate:
            self.isReachable = False
            self.lastStateDate = currentTime
            logging.info('[{}] Set "{}" phone absent [{}]'.format(self.deviceId, self.humanName, self.macAddress))
            self.mustUpdate = True
            return True

        self.mustUpdate = False
        return False

    def toJson(self):
        if self.pluginVersion < 4:
            r = {
                'macAddress': self.macAddress,
                'id': self.deviceId,
                'value': self.isReachable,
                'lastStateDate': self.lastStateDate.isoformat(),
                'humanName' : self.humanName
            }
        else:
            r = {
                'macAddress': self.macAddress,
                'deviceId': self.deviceId,
                'isReachable': self.isReachable,
                'lastStateDate': self.lastStateDate.isoformat(),
                'humanName' : self.humanName
            }
        return r