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
"""

import logging
import threading
import gc
import time

class HeartbeatThread:
    def __init__(self, notifier, monitoringCallback, version):
        self._stop = False
        self.notifier = notifier
        self.monitoringCallback = monitoringCallback
        self.version = version

    def start(self):
        logging.info('Start heartbeat thread')
        self._stop = False
        self.t = threading.Thread(target=self.__run)
        self.t.daemon = True
        self.t.start()

    def stop(self, waitForStop = True):
        logging.info('Stop heartbeat thread')
        self._stop = True
        if waitForStop:
            self.t.join()
            del self.t
            gc.collect()

    def __run(self):
        sleepTime = 30
        while not self._stop:
            isMonitoringAlive = self.monitoringCallback.isMonitoringAlive()
            self.notifier.heartbeat(isMonitoringAlive, self.version)
            time.sleep(sleepTime)

