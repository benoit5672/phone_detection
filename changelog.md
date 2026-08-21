---
layout: default
title: Changelog
lang: fr_FR
---

# Changelog

## 2026-07-31 v4.0.0

### Changement majeur

ajout du MQTT pour recevoir les notifications de changement d'etat des mobiles et le heartbeat des antennes.
Pour utiliser MQTT il faut avoir le plugin MQTTManager installe, et selectionner "MQTT" dans la configuration du plugin. Si vous n'avez pas MQTTManager, ou que vous souhaitez le meme mode qu'auparavant, il faut selectionner le mode "legacy".
Le topic de base par defaut est "phone_detection", mais vous pouvez le configurer dans la page de configuration du plugin.
Les messages envoyes au MQTT broker sont:

    <base_topic>/<antenne>/heartbeat {infos}
    <base_topic>/<antenne>/status/<mobile name> {infos}

N'oubliez pas de mettre a jour les antennes, et de relancer les dependances !

Si vous utilisez MQTT, vous pouvez recevoir les messages des antennes sur un autre jeedom (de test par exemple). Pour cela, sur le jeedom de test, vous devez configurer les antennes pour lesquelles vous voulez recevoir les messages MQTT, mais uniquement le nom (identique au jeedom principal). Il ne faut pas entrer d'autres parametres comme l'adresse IP de l'antenne, ... Les antennes seront ainsi consideree comme "Non geree", et le plugin ne fera aucune action sur les antennes distantes (comme les arreter, mettre a jours les fichiers, recuperer les logs, ...). Ensuite, vous il faut creer les telephones, qui n'ont pas besoin d'avoir le meme nom, les messages MQTT sont traites sur la mac adresse recu dans le message pour identifier le telephone.

### Autres changements

Changement pour ameliorer la compatibilite avec Debian12 et Debian13.
Utilisation the l'API python pour piloter le driver bluetooth en evitant d'utiliser hciconfig. hciconfig n'est utilise que si l'interface bluetooth est "DOWN" pour la passer "UP".

> Comme pour Debian12, le driver bluetooth est moins robuste que dans Debian11. Pour eviter les erreurs bluetooth (visible sur la console) qui force le plugin a arreter l'antenne et a la redemarrer, j'ai change les intervales par defaut:
>
> * Intervalle de mise à jour quand le téléphone est absent: il passe de 15s a 20s
> * Intervalle de mise à jour quand le téléphone est présent: reste a 60s
> * Délai pour considérer le téléphone comme absent: il passe de 180s a 300s

Rappel: pour les intervales de temps, il est important de choisir l'intervalle de temps quand le telephone est present comme un multiple de quand il est absent (dans notre cas, 60s = 3x20s).

### Configuration des intervalles en fonction du nombre de mobiles

Un gros travail a ete fait pour eviter les erreurs bluetooth, avec un driver bluetooth sature par les requetes. Auparavant, le plugin envoye des requetes a un rythme de 50ms, qui pouvait generer des erreurs sur le driver, qui n'accepter plus de commande. C'est l'erreur (alive=0) qui apparaissait dans les messages. Pour eviter ce probleme, je calcule dynamiquement le temps d'attente, en fonction du nombre de mobiles concernes par une requete bluetooth, de l'intervalle de temps absent (20s par defaut), de l'intervalle de temps present (60s par defaut), le nombre d'essais pour un meme mobile (2)

    temps d'attente entre 2 requetes = ((PGCD(intervalPresent, intervalAbsent) - 5secondes) / essais) / nb Mobiles

Par exemple, si vous avez 4 mobiles, intervalAbsent = 20s, intervalPresent = 60s, essais = 2

    PGCD(20, 60) = 20
    temps d'attente = ((20 - 5) / 2) / 4 = 1,875ms

Exemple de logs, 4 mobiles, 1 present: on a 3 mobiles absents, donc toutes les 20s, et 1 mobile present toute les minutes.

    [2026-08-12 10:36:23][DEBUG][root](Thread-2 (__run)) : attempt: 1/2, number of mobiles: 3, btRequestInterval: 2.5
    [2026-08-12 10:36:43][DEBUG][root](Thread-2 (__run)) : attempt: 1/2, number of mobiles: 3, btRequestInterval: 2.5
    [2026-08-12 10:37:03][DEBUG][root](Thread-2 (__run)) : attempt: 1/2, number of mobiles: 4, btRequestInterval: 1.875
    [2026-08-12 10:37:23][DEBUG][root](Thread-2 (__run)) : attempt: 1/2, number of mobiles: 3, btRequestInterval: 2.5

Si vous avez beaucoup de messages (Arret de l'antenne local suite a un probleme reporte par l'antenne) reportee dans le log ou la fenetre de message, pensez a ajuster les intervalles de temps (present et absent).

### Meilleure gestion des erreurs du driver bluetooth

Dans les precedentes versions, quand le driver bluetooth etait en erreur, avec dans dmesg ou la console ce genre de messages:

    [154746.077162] Bluetooth: hci0: Controller not accepting commands anymore: ncmd = 0
    [154746.077930] Bluetooth: hci0: Injecting HCI hardware error event
    [154746.152527] Bluetooth: hci0: hardware error 0x00

Le probleme n'etait pas fixe sur l'antenne, mais l'antenne notifie jeedom du probleme (alive=0) dans les logs et les messages jeedom. Jeedom se chargeait d'arreter l'antenne, et si le redemarrage automatique etait active, alors, l'antenne etait egalement redemarree par jeedom.

Dans cette version 4.0, en cas de detection de probleme, l'antenne essaye d'abord de faire un "soft reset" du driver bluetooth. Si le probleme persiste, un "hard reset" est execute. Enfin, si le probleme n'est toujours pas resolu, alors, l'antenne notifie le probleme a jeedom, et on suit le meme processus que precedemment.

* soft reset: on reinitialise le driver bluetooth (hciconfig xxx reset)
* hard reset: on arrete le service bluetooth, on met l'interface xxx DOWN, on supprime le driver bluetooth du kernel, on reinstalle le driver bluetooth dans le kernel, on passe l'interface xxx UP, et on redemarre le service bluetooth (ouf!).

## 2024-12-26 v3.0.0

Changements pour être compatible avec Debian 12.

1. Utilisation de la librairie php phpseclib3 pour toutes les communications avec les antennes (SSH et SFTP).
2. Modification du script d'installation des dépendances.
3. Utilisations du même mécanisme de communication entre l'antenne locale et jeedom qu'entre les antennes distantes et jeedom.

> Important: L’utilisateur configuré doit être dans le groupe sudoers et avoir le droit de faire un sudo sans confirmer son mot de passe. Cette pratique est dangereuse d'un point de vue sécurité, et il est recommandé de créer un utilisateur qui ne pourra se connecter que depuis votre jeedom.

Si vous avez besoin d’aide pour la création et la configuration de cet utilisateur:

```text
1. sudo adduser jeedom
2. sudo visudo

(a la fin du fichier)
jeedom ALL=(ALL) NOPASSWD:ALL
```

Assurez-vous que vous pouvez vous connectez avec cet utilisateur "jeedom" dans notre exemple, et que la commande sudo ls ne demande pas de mot de passe. Si ce n'est pas le cas, le plugin ne fonctionnera pas sur l'antenne distante:

```text
su - jeedom
sudo ls
```

## 2024-03-03 v2.2.6

En cas de problème Bluetooth, le monitoring des devices est arrêté, et le problème est reporte par l'antenne au plugin phone_detection. Celui-ci arrête le daemon sur l'antenne distante. Le comportement était different pour le daemon local, qui reportait bien un problème, mais qui n’était pas arrêté par le plugin. Ce problème est maintenant fixé.

## 2024-03-03 v2.2.5

Correction autour du point (2) de la mise a jour précédente. Si l'interface passe down. on va arrêter le monitoring si
tous les mobiles ne retournent plus de réponses. Auparavant si on avait le message: "No response for mac XXX" 5 fois de suite, alors le monitoring s’arrêtait.

## 2024-03-01 v2.2.4

Amelioration autour de l’état de l'interface HCI:

1. Si l'interface HCI n'est pas UP au démarrage du demon, le demon va essayer de la passer UP. En cas d’échec le demon s’arrête. Si vous avez la gestion du demon active, le demon va être redémarré a intervale régulier par jeedom.
2. Si l'interface HCI passe DOWN alors que le demon tourne et monitor des telephones. Le demon va effectuer 5 sequences de monitoring avec la meme périodicité que d'habitude. Si le problème persiste, le demon va arrêter le monitoring, et informer Jeedom du problème, qui va stopper complètement le demon. Si vous avez la gestion du demon active, celui-ci va être redémarrer automatiquement par Jeedom, et l'interface DOWN devrait être fixe par le point (1).

Une fois la version 2.2.4 installe, n'oubliez pas de mettre a jour vos antennes, et de redémarrer le daemon local si vous l'utilisez.

## 2024-02-26 v2.2.3

Correction du problème 'sending frame failed (-19)' qui apparaissait sur des distributions autres que raspberry, quel que soit la version du kernel Linux.
Une fois la version 2.2.3 installée, n'oubliez pas de mettre a jour vos antennes, et de redémarrer le demon local si vous l'utilisez.

## 2024-01-04 v2.2.2

* Changement des paramètres de polling par default (10/30) vers (15/60). Il est recommande de ne pas descendre sous les 15 secondes pour la fréquence de polling des telephones absents.
* Fix pour le problème de droits avec l'antenne locale qui démarrait mais n’était pas autoriser a envoyer des requêtes Bluetooth.

## 2024-01-02 v2.2.1

Correction de bugs suite au passage en 2.2.0. Certains mobiles ne sont plus détectés, en fonction de différentes
conditions (ordre de polling, presence ou non du mobile, délai de réponse, ...).

## 2023-12-26 v2.2.0

Une nouvelle approche pour éviter les problèmes Bluetooth, principalement vu sous Debian 11 sur raspberry. Je n'avais pas ce problème sous Debian 10.
Au lieu d'avoir une approche multi-thread (un par mobile), les mobiles sont maintenant traites dans un seul thread, avec des appels asynchrones.
Cela se base sur la class aiobtname de François Wautier. Avec cette nouvelle approche, le monitoring de telephone est beaucoup plus stable, je
n'ai plus besoin de redémarrer le daemon phone_detection, ou de redémarrer mes antennes a intervals réguliers.

On se degage de pybluez, et de l'api bluez avec cette version, qui utilise directement les libraries python3 et les sockets HCI pour communiquer
avec les mobiles.

Il n'y a pas d'installation particulière de dépendances. Installer le plugin de manière classique, et mettez a jour vos antennes.

## 2023-11-03 v2.1.0

Pile un an apres la dernière modification majeure :)

Pas mal de fixes, et d'ameliorations autour de la gestion des antennes et du Bluetooth. N'oubliez pas de mettre a jour vos antennes !
  
* Fixe un problème sur l’arrêt des antennes via le plugin. Cela générait une exception, et le process phone_detectiond.py n’était pas toujours arrêté. En cas de redémarrage, une error "Socket already in use" était générée.
* Les logs des antennes étaient rapatriées toutes les 15 minutes sur le jeedom, et le fichier était re-initialise a chaque fois. Maintenant, les logs sont concaténées pour chaque antenne.
* la version indiquée par les antennes étaient folklorique. La version provenait de valeurs hardcodees dans le code php. Maintenant, un fichier version.txt est créé a l'installation ou la mise a jour du plugin phone_detection, et envoyé a chaque antenne via "envoyer les fichiers".
* Il arrivait que le driver Bluetooth soit inutilisable via l'API bluez. Il suffit en general d'un 'hciconfig hci0 reset' pour le rendre de nouveau opérationnel.
  * Cette commande est utilisée au démarrage du demon, si on ne parvient pas a initialiser la libraire Bluetooth avec le bon module Bluetooth.
  * Une fois que le demon tourne sur l'antenne, un thread est créé pour chaque mobile a surveiller. Auparavant, si une exception était générée car le module Bluetooth n’était pas opérationnel, le thread pouvait s’arrêter, et ainsi ne plus monitorer le mobile, bien que le demon soit vu actif par jeedom. Maintenant, le thread va essayer 3 fois de réinitialiser le module Bluetooth. En cas d’échec, le thread va s’arrêter, sinon il va reprendre une surveillance active du telephone.
  * Le demon sur l'antenne envoie de manière régulière des informations a jeedom pour lui indiquer qu'il est toujours vivant. J'ai ajoute plusieurs informations dans ce message envoyé. D'une part, la version du demon phone_detectiond.py qui tourne sur l'antenne. D'autre part, le nombre de thread actif, c'est a dire le nombre de telephone toujours surveille, et le nombre total de telephone normalement surveille. Si ce nombre est different, cela signifie qu'il y a une un problème Bluetooth que le daemon n'a pas sur résoudre tout seul. Dans ce cas, le plugin phone_detection sur jeedom va arrêter le demon. Si vous avez active la surveillance active du demon, celui-ci sera automatiquement redémarré.

J’espère avoir fixe les problèmes remontés récréments dans le forum, sinon, on aura en tout cas plus d'information pour comprendre les problèmes.
  
## 2022-11-03 v2.0.0

* Utilisation de pybluez pour effectuer un appel python au lieu d'un appel système de hciconfig pour la demande d'information du mobile.
  Il semble que cela solutionne les problèmes de blocage du daemon qui pouvait arriver sur raspberry.
  Installation de hcidump qui permet de surveiller en temps reel les paquets envoyés et reçus par l'antenne Bluetooth. Pour voir les paquets, il suffit d’exécuter la commande 'hcidump -t -X'

## 2021-10-18 v0.5.0

* Correction d'un problème de mise a jour de l'état du groupe de téléphone, suite a la perte d'une antenne. L'état était systématiquement passé a 0, même si certains téléphones étaient encore visible au travers d'autres antennes.

## 2021-05-26 v0.4.0

* Ajout du support "multi-antennes" permettant d’étendre la couverture Bluetooth gérer par le plugin. Le multi-antennes utilisent le même principe que le plugin BLEA, en utilisant des équipements distantes possédant une clé Bluetooth et envoyant les informations au plugin phone_detection installe sur Jeedom.
* Modification de l'interface 'configuration des équipements', pour être en phase avec le design 4.1 / 4.2. Cela comprend notamment la suppression du menu a gauche listant les équipements.

## v0.3

Version stable du plugin, avec une unique antenne gérée sur le serveur Jeedom.

## Documentation

[Documentation]({{site.baseurl}}/)
