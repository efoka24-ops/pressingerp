# Test de bout en bout de la réception hors-ligne

Lance un vrai Chrome sans interface, coupe réellement le réseau (CDP) et vérifie : autorisation du poste, service worker,
rechargement hors réseau depuis le cache, saisie de commandes avec numéros réservés, tickets, synchronisation au retour du
réseau (ordre, une seule fois), plage de numéros épuisée. L'API est simulée par un petit serveur local : la logique serveur
est couverte par `tests/cases/offline.php`.

```bash
cd tests/e2e && npm init -y && npm install puppeteer-core
CHROME="C:/Program Files/Google/Chrome/Application/chrome.exe" node offline.e2e.js
```

Prérequis : Node 18+, PHP en ligne de commande (rend la vue), Chrome ou Edge installé.
