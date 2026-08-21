#!/usr/bin/env python3
'''
phone_detectiond daemon
created by Sebastien FERRAND
sebastien.ferrand@vbmaf.net
04/11/2019

2021/05/25: Benoit Rech 
support multi-antennas. Each antenna sends the presence information
to plugin phone_detection that runs on jeedom. Jeedom consolidates the information from the 
different antennas to build the "global" device status.

2022/01/03: Benoit Rech
Use pybluez instead of system calls which can cause locks on raspberry
Install hcidump to be able to monitor hci frame directly on the antenna.

2023/12/13: Benoit Rech
Do not use system calls, or pyBluez because there are lots of issues on Debian 11 (bullseye).
Rely on a class (aiobtname.py) developped by François Wautier, which uses direct HCI socket 
with the system, and asynchonous calls that avoid using multi-threads. 
A request is sent for each mobile, and the mobile's responses are parsed on the fly.
The polling interval is also more accurate, as well as the monitoring of the 'unreachable' threshold.

2026/07/30: Benoit Rech
For Debian11 and Debian12, use hciconfig (legacy mode), and use direct python calls when possible on Debian13.
btmgmt doesn't work as expected, keeping hciconfig to get the bluetooth interface UP if needed 
'''

import logging
import sys
import time
import signal
import json
import argparse
import socketserver
import threading
import gc
import re
import random
import asyncio as aio
import os
import aiobtname

from math import gcd
from functools import partial
from datetime import datetime, timezone

from pd_jeedom_notifier import JeedomNotifier
from pd_mqtt_notifier import MqttNotifier
from pd_jeedom_connector import JeedomConnector
from pd_heartbeat import HeartbeatThread
from pd_bluetooth import BluetoothController
from pd_phone import Phone
from pd_global import DEVICES, PLUGIN_NAME, ABSENT_THRESHOLD

BASE_PATH = os.path.join(os.path.dirname(__file__), '..', '..', '..', '..')
BASE_PATH = os.path.abspath(BASE_PATH)
LOGLEVEL = logging.WARNING
PAGE_TIMEOUT = 2500  # represent 1.5625s

server = None


"""
Class gérant le thread de détection pour l'ensemble des telephones
"""


class PhonesDetection:
    def __init__(self, btController, absentInterval, presentInterval, notifier):
        self.btController = btController
        self.absentInterval = absentInterval
        self.presentInterval = presentInterval
        self._stop = False
        self.notifier = notifier
        self.macList = []
        self.nbConnectionFailure = 0
        self.nbSendFailure = 0

    def start(self):

        for device in DEVICES.values():
            logging.info('[{}] Starting monitoring for {} [{}]'.format(device.deviceId, device.humanName, device.macAddress))
            #deviceStatus = self.notifier.getDeviceStatus(device.deviceId)
            deviceStatus = device.isReachable
            logging.debug('Jeedom {} device status: {}'.format(device.deviceId, deviceStatus))
            if deviceStatus == True:
                device.setReachable()
            else:
                device.setNotReachable()

        self._stop = False
        self.t = threading.Thread(target=self.__run)
        self.t.daemon = True
        self.t.start()

    def stop(self, waitForStop = True):
        for device in DEVICES.values():
            logging.info('[{}] Stop monitoring for {} [{}]'.format(device.deviceId, device.humanName, device.macAddress))

        self._stop = True
        if waitForStop:
            self.t.join()
            del self.t
            gc.collect()

    def isMonitoringAlive(self):
        return int(self.t.is_alive())

    def isValidMacAddress(sef, mac):
        allowed = re.compile(r"""
                         (
                             ^([0-9A-F]{2}[-]){5}([0-9A-F]{2})$
                            |^([0-9A-F]{2}[:]){5}([0-9A-F]{2})$
                         )
                         """,
                         re.VERBOSE|re.IGNORECASE)

        return re.search(allowed, mac)

    def isPollingRequested(self, phone):
        currentTime = int(datetime.now(timezone.utc).timestamp())
        if phone.isReachableLastPolling:
            nextPollDate = phone.lastPollDate + self.presentInterval
        else:
            nextPollDate = phone.lastPollDate + self.absentInterval

        logging.debug('mobile: {}, isReachableLastPolling: {} -> isPollingRequested: {} (delta:{}), lastPollDate: {}, nextPollDate: {}'.format(
            phone.humanName, phone.isReachableLastPolling, currentTime >= nextPollDate, int(currentTime - nextPollDate), phone.lastPollDate, nextPollDate))
        return (currentTime >= nextPollDate)

    def processResponse(self, data):
        logging.debug('Received response from device: {}: {}'.format(data['mac'], data['name']))
        mac = data['mac'].upper()
        if mac in self.macList:
            self.macList.remove(mac)
        for device in DEVICES.values():
            if device.macAddress == mac:
                logging.debug('[{}] {} ({}) is reachable'.format(device.deviceId, device.macAddress, device.humanName))            
                if device.setReachable() == True:
                    if self.notifier.setDeviceStatus(device):
                        device.mustUpdate = False
                break

    def processTimeout(self, data):
        logging.debug('Received timeout for device: {}'.format(data['mac']))
        mac = data['mac'].upper()
        if mac in self.macList:
            self.macList.remove(mac)
        for device in DEVICES.values():
            if device.macAddress == mac:
                logging.debug('[{}] {} ({}) is unreachable'.format(device.deviceId, device.macAddress, device.humanName))            
                if device.setNotReachable() == True:
                    if self.notifier.setDeviceStatus(device):
                        device.mustUpdate = False
                break        

    async def GetPhonesInformation(self):

        try:
            #First create and configure a raw socket
            sock = aiobtname.create_bt_socket(int(self.btController.getId()))
            self.nbConnectionFailure = 0
        except Exception as e:
            logging.error('Impossible de se connecter au bluetooth hci{}, exception: {}: {}'.format(self.btController.getId(), type(e), e))
            self.nbConnectionFailure += 1
            if self.nbConnectionFailure > 5:
                logging.error('Suspecting an issue with the bluetooth, stop monitoring')
                self.stop(False)
            return

        conn = None
        self.macList = []
        lastPollDate = int(datetime.now(timezone.utc).timestamp())
        for device in DEVICES.values():
            if self.isValidMacAddress(device.macAddress) and self.isPollingRequested(device):
                logging.debug('Adding [{}]: {} ({})'.format(device.deviceId, device.macAddress, device.humanName))
                self.macList.append(device.macAddress)
                device.lastPollDate = lastPollDate

        logging.debug('Number of devices to poll: {}'.format(len(self.macList)))
        if len(self.macList) != 0:
            # Randomize the order of the requests in the list.
            random.shuffle(self.macList)
            try:
                #create a connection with the raw socket
                event_loop = aio.get_event_loop()
                fac = event_loop._create_connection_transport(sock, aiobtname.BTNameRequester, None, None)
                conn, btctrl = await event_loop.create_task(fac)
                btctrl.processResponse = self.processResponse
                btctrl.processTimeout = self.processTimeout

                retries = 2
                #timetowait = 5.000  # 5 seconds, pagetimeout is 2500 slots (1562.50 ms)

                # New calculation to optimize the requests sent to the bluetooth controller
                # Before 4.0.0 the interval between 2 bluetooth requests was hardcoded to 50ms, which was working fine on Debian11,
                # but causing lots of issues on Debian12 and Debian13. 
                requestInterval = max(10, gcd(self.absentInterval, self.presentInterval))
                timetowait = float((requestInterval - 5) / retries)
                logging.debug('requestInterval: {}, loop: {}, timetowait: {}'.format(requestInterval, retries, timetowait))               

                #request = partial(btctrl.request, self.macList)
                await aio.sleep(0.1)
                for attempt in range(retries):
                    if len(self.macList) != 0:
                        #logging.debug('Sending bluetooth name request to {} devices (try {}/{})'.format(len(self.macList), attempt + 1, retries))
                        #request()
                        # btRequestInterval = 0.050  # legacy value, too agressive on Debian12, Debian13.
                        # make it minimum 500ms
                        btRequestInterval = max(float(0.500), float(timetowait / len(self.macList)))
                        logging.debug('attempt: {}/{}, number of mobiles: {}, btRequestInterval: {} '.format(attempt + 1, retries, len(self.macList), btRequestInterval))               

                        await btctrl.request(self.macList, btRequestInterval)
                        # Time for the devices to reply or to get timeout
                        await aio.sleep(timetowait)

            except Exception as e:
                logging.info('Exception: {}/{}'.format(type(e), e))
                logging.error('Erreur {} durant le monitoring des devices'.format(e))

            finally:
                if conn is not None:
                    conn.close()
                else:
                    sock.close()

            # list of mac address that are in unknown state (no Timeout, no answer)
            # force Polling at next round.
            await aio.sleep(1)
            for mac in self.macList:
                logging.warning('No response for mac {}'.format(mac))
                for device in DEVICES.values():
                    if mac == device.macAddress:
                        device.isReachableLastPolling = False

            if len(self.macList) == len(DEVICES.keys()):
                # there was no timeout and no response for all devices.
                self.nbSendFailure += 1

                if self.nbSendFailure == 1:
                    logging.warning('Executing a soft reset on HCI interface {}'.format(self.btController.getAdapter()))
                    self.btController.softResetBluetoothAdapter()                    

                elif self.nbSendFailure == 3:
                    logging.warning('Executing a hardreset reset on HCI interface {}'.format(self.btController.getAdapter()))
                    self.btController.hardResetBluetoothAdapter()

                elif self.nbSendFailure > 5:
                    logging.error('Suspecting an issue with the bluetooth, stop monitoring')
                    self.stop(False)
            else:
                self.nbSendFailure = 0

    def __run(self):
        sleepTime = gcd(self.absentInterval, self.presentInterval)
        logging.debug('sleeptime: {} GCD({}, {})'.format(sleepTime, self.absentInterval, self.presentInterval))
        while not self._stop:
            startTime = time.time()
            event_loop = aio.new_event_loop()
            try:
                coro = self.GetPhonesInformation()
                event_loop.run_until_complete(coro)
                # Process with periodic refresh
                currentTime = int(datetime.now(timezone.utc).timestamp())
                for device in DEVICES.values():
                    refreshDate = device.lastRefreshDate + 300
                    if currentTime >= refreshDate:
                        logging.debug('{}: periodic refresh (300s) --> status: {}'.format(device.humanName, device.isReachable))
                        device.lastRefreshDate = currentTime
                        self.notifier.setDeviceStatus(device)
            except Exception as e:
                logging.error('Unknow exception {} while monitoring mobiles'.format(e))

            finally:
                event_loop.close()
                endTime = time.time()

            elapsedTime = endTime - startTime
            remainingTime = max(0, sleepTime - elapsedTime)
            logging.debug('elapsed time {}, next loop in {} seconds'.format(elapsedTime, remainingTime))
            time.sleep(remainingTime)

"""
Convertisseur Phone <-> json
"""
class PhoneEncoder(json.JSONEncoder):
    def default(self, obj):  # pylint: disable=E0202
        if isinstance(obj, Phone):
            return obj.toJson()
        if obj is None:
            return ""
        # if isinstance(obj, Response):
        #     return obj.cleaned_data()

        return json.JSONEncoder.default(self, obj)


"""
Intercepte les demandes de Jeedom : update_device, insert_device et remove_device
"""
class JeedomHandler(socketserver.BaseRequestHandler):

    def handle(self):
        # self.request is the TCP socket connected to the client
        self.data = self.request.recv(1024)
        logging.debug('Message received in socket, length: {}'.format(len(self.data)))
        message = json.loads(self.data.decode())
        logging.debug(message)

        response = {'result': None, 'success': True}
        stop = False
        if message['apikey'] != _apikey:
            logging.error("Invalid apikey from socket : {}".format(self.data))
            return
        del message['apikey']

        action = message['action']
        args = message['args']

        if action == 'update_device' or action == 'insert_device':
            mid = args[0]
            name = args[1]
            macAddress = args[2]

            if mid in DEVICES:
                # update
                logging.debug('Update device in device: {}'.format(mid))
                DEVICES[mid].humanName = name
                DEVICES[mid].deviceId = int(mid)
                DEVICES[mid].macAddress = macAddress
                response['result'] = 'Update OK'
            else:
                # insert
                logging.debug('Add new device in device: {}'.format(mid))
                DEVICES[mid] = Phone(macAddress, mid)
                DEVICES[mid].humanName = name
                response['result'] = 'Insert OK'

        if action == 'remove_device':
            mid = args[0]
            if mid in DEVICES:
                del DEVICES[mid]
            response['result'] = 'Remove OK'

        if action == 'logdebug':
            logging.debug('Dynamically change log to debug')
            log = logging.getLogger()
            for hdlr in log.handlers[:]:
               log.removeHandler(hdlr)
               logging.basicConfig(level=logging.DEBUG,
                                   format=FORMAT, datefmt="%Y-%m-%d %H:%M:%S")
            response['result'] = 'logdebug OK'
            logging.debug('logging level is now DEBUG')

        if action == 'lognormal':
            logging.debug('Dynamically restore the default log level')
            log = logging.getLogger()
            for hdlr in log.handlers[:]:
               log.removeHandler(hdlr)
               logging.basicConfig(level=LOGLEVEL,
                                   format=FORMAT, datefmt="%Y-%m-%d %H:%M:%S")
            response['result'] = 'lognormal OK'

        if action == 'stop':
            logging.debug('Receive stop request from jeedom')
            stop = True

        self.request.sendall(json.dumps(response, cls=PhoneEncoder).encode())

        if stop == True:
            os.kill(os.getpid(),signal.SIGTERM)




"""
Converti le loglevel envoyer par jeedom
"""
def convert_log_level(level='error'):
    LEVELS = {'debug': logging.DEBUG,
              'info': logging.INFO,
              'notice': logging.WARNING,
              'warning': logging.WARNING,
              'error': logging.ERROR,
              'critical': logging.CRITICAL,
              'none': logging.NOTSET,
              'default': logging.INFO }
    return LEVELS.get(level, logging.NOTSET)

def handler(signum=None, frame=None):
    logging.debug("Signal %i caught, exiting..." % int(signum))
    print("Signal %i caught, exiting..." % int(signum))
    shutdown()

"""
shutdown: nettoie les ressources avant de quitter
"""
def shutdown():
    logging.info("=========== Shutdown ===========")
    logging.info("Stopping monitoring and heartbeat threads")
    try:
        monitoringThread.stop(False)
    except Exception:
        pass
    try:
        heartbeatThread.stop(False)
    except Exception:
        pass

    logging.info("Shutting down local server")
    try:    
        server.shutdown()
        server.server_close()
    except Exception:
        pass

    logging.info("Stopping  threads")
    try:
        notif.stop()
    except Exception:
        pass

    if (_sockfile != None and len(str(_sockfile)) > 0):
        logging.info("Removing Socket file " + str(_sockfile))
        if os.path.exists(_sockfile):
            os.remove(_sockfile)
    logging.info("Removing PID file " + str(_pidfile))
    if os.path.exists(_pidfile):
        os.remove(_pidfile)
    logging.info("Exit 0")
    logging.info("=================================")


### Init & Start
parser = argparse.ArgumentParser()
parser.add_argument('--loglevel', '--log-level', dest='loglevel', help='LOG Level', default='warning')
parser.add_argument('--socket', help='Daemon socket', default='')
parser.add_argument('--sockethost', '--socket-host', dest='sockethost', help='Daemon socket host', default='')
parser.add_argument('--socketport', '--socket-port', dest='socketport', help='Daemon socket port', default='0')
parser.add_argument('--pidfile', '--pid-file', dest='pidfile', help='PID File', default='/tmp/{}d.pid'.format(PLUGIN_NAME))
parser.add_argument('--apikey', '--api-key', dest='apikey', help='API Key', default='nokey')
parser.add_argument('--device', help='{} port'.format(PLUGIN_NAME), default='hci0')
parser.add_argument('--callback', help='Jeedom callback', default='http://localhost')
parser.add_argument('--daemonname', '--daemon-name', dest='daemonname', help='Name of the antenna', default='local')
parser.add_argument('--interval', '--absent-interval', help='Presence checking interval when phone is absent', default=20)
parser.add_argument('--present_interval', '--present-interval', dest='present_interval', help='Presence checking interval when phone is present', default=60)
parser.add_argument('--absentThreshold', '--absent-threshold', dest='absent_threshold', help='Time to consider a device absent', default=180)
parser.add_argument('--mqtt-host', dest='mqtt_host', help='MQTT broker host', default='')
parser.add_argument('--mqtt-port', dest='mqtt_port', help='MQTT broker port', default=1883)
parser.add_argument('--mqtt-protocol', dest='mqtt_protocol', help='MQTT broker protocol', default='mqtt')
parser.add_argument('--mqtt-username', dest='mqtt_username', help='MQTT broker authentication username', default='')
parser.add_argument('--mqtt-password', dest='mqtt_password', help='MQTT broker authentication password', default='')
parser.add_argument('--mqtt-topic', dest='mqtt_topic', help='MQTT base topic', default=PLUGIN_NAME)

args = parser.parse_args()

FORMAT = '[%(asctime)-15s][%(levelname)s][%(name)s](%(threadName)s) : %(message)s'
LOGLEVEL = convert_log_level(args.loglevel);
logging.basicConfig(level=LOGLEVEL,
                    format=FORMAT, datefmt="%Y-%m-%d %H:%M:%S")
urllib3_logger = logging.getLogger('urllib3')
urllib3_logger.setLevel(logging.CRITICAL)

# Recupere la version du plugin
try:
    with open(os.path.dirname(__file__) + '/version.txt', 'r') as fp:
        version = fp.read()
        version = version.rstrip('\r\n')
        fp.close()
except FileNotFoundError:
    version = '?.?.?'

logging.info('=========')
logging.info('Start {}d'.format(PLUGIN_NAME))
logging.info('Version: {}'.format(version))
logging.info('Log level : {}'.format(args.loglevel))
logging.info('Socket : {}'.format(args.socket))
logging.info('SocketHost : {}'.format(args.sockethost))
logging.info('SocketPort : {}'.format(args.socketport))
logging.info('PID file : {}'.format(args.pidfile))
logging.info('Device : {}'.format(args.device))
logging.info('Callback : {}'.format(args.callback))
logging.info('Daemon Name : {}'.format(args.daemonname))
logging.info('Polling Interval when device is Absent : {}'.format(args.interval))
logging.info('Polling Interval when device is Present : {}'.format(args.present_interval))
logging.info('Threshold to consider device Absent: {}'.format(args.absent_threshold))
logging.info('MQTT host: {}'.format(args.mqtt_host))
logging.info('MQTT port: {}'.format(args.mqtt_port))
logging.info('MQTT protocol: {}'.format(args.mqtt_protocol))
logging.info('MQTT username: {}'.format(args.mqtt_username))
if args.mqtt_password != '':
    logging.info('MQTT password: **********')
logging.info('MQTT base topic: {}'.format(args.mqtt_topic))
logging.info('Python version : {}'.format(sys.version))

_pidfile = args.pidfile
_sockfile = args.socket
_apikey = args.apikey



bt = BluetoothController(args.device, PAGE_TIMEOUT)
logging.info('Using bluetooth controller {} (id={}).'.format(bt.getAdapter(), bt.getId()))
if not bt.checkAndInit():
    sys.exit(1)

absentInterval = int(args.interval)
presentInterval = int(args.present_interval)
ABSENT_THRESHOLD = int(args.absent_threshold)

# Configuration du handler pour intercepter les commandes
# kill -9 et kill -15
signal.signal(signal.SIGINT, handler)
signal.signal(signal.SIGTERM, handler)

# Ecrit le PID du démon dans un fichier
pid = str(os.getpid())
logging.debug("Writing PID " + pid + " to " + str(args.pidfile))
with open(args.pidfile, 'w') as fp:
    fp.write("%s\n" % pid)
    fp.close()


# Create the connector to Jeedom
logging.info('Starting jeedom connector {}'.format(args.callback))
jconn = JeedomConnector(args.apikey, args.callback, args.daemonname, ABSENT_THRESHOLD)
if not jconn.test():
    logging.critical('Unable to establish communication with jeedom')
    sys.exit(1)

# Configure le notifier vers jeedom ou vers MQTT
if args.mqtt_host is not None and len(args.mqtt_host) > 0:
    logging.info('Starting MQTT connector {}://{}:{}/'.format(args.mqtt_protocol, args.mqtt_host, args.mqtt_port))
    try:
        notif = MqttNotifier(args.mqtt_host, args.mqtt_port, args.mqtt_topic, args.daemonname, username=args.mqtt_username, passwd=args.mqtt_password)
    except Exception as e:
        logging.critical('Unable to connect to MQTT {}://{}:{}/'.format(args.mqtt_protocol, args.mqtt_host, args.mqtt_port))
        logging.critical(' check MQTT protocol, host, port, username and password')
        sys.exit(1)
else:
    notif = JeedomNotifier(args.apikey, args.callback, args.daemonname)

# Démarre le serveur qui écoute les requests de jeedom
if args.socket != None and len(args.socket) > 0:
    logging.info('Use Unix socket for Jeedom -> daemon communication')
    if os.path.exists(args.socket):
        os.unlink(args.socket)
    server = socketserver.UnixStreamServer(args.socket, JeedomHandler)
else:
    try:
        logging.info('Use TCP socket for Jeedom -> daemon communication')
        socketserver.TCPServer.allow_reuse_address = True
        server = socketserver.TCPServer((args.sockethost, int(args.socketport)), JeedomHandler)
    except OSError as e:
        if e.errno == 98:
            logging.info('TCP socket in use, wait 5 seconds and retry')
            time.sleep(5)
            socketserver.TCPServer.allow_reuse_address = True
            try:
                server = socketserver.TCPServer((args.sockethost, int(args.socketport)), JeedomHandler)
            except:
                logging.error('Unable to create TCP socket for Jeedom to daemon communication. Exiting')
                sys.exit(1)


handlerThread = threading.Thread(target=server.serve_forever)
handlerThread.start()

# Récupération des devices dans Jeedom
DEVICES = jconn.getDevices()
#jconn.updateGlobalDevice()

# Démarrage du thread de monitoring des mobiles
monitoringThread = PhonesDetection(bt, absentInterval, presentInterval, notif)
monitoringThread.start()

# Demarrage des heartbeat vers jeedom
heartbeatThread = HeartbeatThread(notif, monitoringThread, version)
heartbeatThread.start()
