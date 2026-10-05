'use strict';
// Test du client SMTP contre un faux serveur : node tests/smtp/smtp.test.js  (nécessite PHP en ligne de commande)
const net = require('net');
const path = require('path');
const { execFile } = require('child_process');

const received = [];
let cur = null;
const server = net.createServer((sock) => {
  cur = { auth: [], from: null, rcpt: [], data: '', cmds: [] };
  let inData = false, buf = '', authStep = 0;
  sock.on('error', () => {});   // un client qui coupe brutalement ne doit pas faire tomber le faux serveur
  const say = (s) => sock.write(s + '\r\n');
  say('220 faux.smtp ESMTP prêt');
  sock.on('data', (chunk) => {
    buf += chunk.toString('utf8');
    for (;;) {
      if (inData) {
        const end = buf.indexOf('\r\n.\r\n');
        if (end < 0) return;
        cur.data = buf.slice(0, end); buf = buf.slice(end + 5); inData = false; received.push(cur); say('250 2.0.0 Message accepté'); continue;
      }
      const nl = buf.indexOf('\r\n');
      if (nl < 0) return;
      const line = buf.slice(0, nl); buf = buf.slice(nl + 2);
      if (authStep === 1) { cur.auth.push(Buffer.from(line, 'base64').toString()); authStep = 2; say('334 UGFzc3dvcmQ6'); continue; }
      if (authStep === 2) { cur.auth.push(Buffer.from(line, 'base64').toString()); authStep = 0; say(cur.auth[1] === 'secret' ? '235 2.7.0 Authentification réussie' : '535 5.7.8 Identifiants invalides'); continue; }
      cur.cmds.push(line.split(' ')[0]);
      if (/^EHLO/i.test(line)) { sock.write('250-faux.smtp\r\n250 AUTH PLAIN LOGIN\r\n'); }
      else if (/^AUTH LOGIN/i.test(line)) { authStep = 1; say('334 VXNlcm5hbWU6'); }
      else if (/^MAIL FROM/i.test(line)) { cur.from = line; say('250 OK'); }
      else if (/^RCPT TO/i.test(line)) { cur.rcpt.push(line); say(/inconnu@/.test(line) ? '550 5.1.1 Destinataire inconnu' : '250 OK'); }
      else if (/^DATA/i.test(line)) { inData = true; say('354 Fin par <CRLF>.<CRLF>'); }
      else if (/^QUIT/i.test(line)) { say('221 Au revoir'); sock.end(); }
      else { say('500 commande inconnue'); }
    }
  });
});

const run = (port, mode) => new Promise((resolve) => execFile('php', [path.join(__dirname, 'send.php'), String(port), mode], { timeout: 30000 }, (e, out) => resolve(String(out))));
let failures = 0;
const check = (label, ok, detail) => { console.log((ok ? 'ok    ' : 'ECHEC ') + label + (ok ? '' : ' — ' + detail)); if (!ok) failures++; };

server.listen(0, '127.0.0.1', async () => {
  const port = server.address().port;

  let out = await run(port, 'ok');
  check('passerelle configurée', /configuré/.test(out) && !/NON/.test(out), out);
  check('envoi réussi', /envoyé [0-9a-f]+@test\.cm/.test(out), out);
  const m = received[0];
  check('authentification avec les bons identifiants', m && m.auth[0] === 'noreply@test.cm' && m.auth[1] === 'secret', JSON.stringify(m && m.auth));
  check('enveloppe : expéditeur et destinataire', m && /MAIL FROM:<noreply@test\.cm>/.test(m.from) && m.rcpt.length === 1 && /<client@example\.com>/.test(m.rcpt[0]), JSON.stringify(m));
  const [head, body] = m.data.split('\r\n\r\n');
  const header = (n) => (head.match(new RegExp('^' + n + ': (.*)$', 'mi')) || [])[1];
  check('en-têtes standards', header('MIME-Version') === '1.0' && /charset=UTF-8/.test(header('Content-Type')) && header('Content-Transfer-Encoding') === 'base64' && /^<[0-9a-f]+@test\.cm>$/.test(header('Message-ID')), head);
  check('expéditeur nommé et encodé', /^=\?UTF-8\?B\?.+\?= <noreply@test\.cm>$/.test(header('From')) && Buffer.from(header('From').match(/\?B\?(.+)\?=/)[1], 'base64').toString() === 'Pressing é', header('From'));
  const subject = Buffer.from(header('Subject').match(/\?B\?(.+)\?=/)[1], 'base64').toString();
  check('sujet UTF-8 décodable', subject.startsWith('Sujet accentué éè'), subject);
  check('aucune injection d\'en-tête par le sujet', !/^Bcc:/mi.test(head) && !/evil@example\.com/.test(m.rcpt.join()), head);
  const text = Buffer.from(body.replace(/\r\n/g, ''), 'base64').toString();
  check('corps UTF-8 intact, y compris une ligne commençant par un point', text === 'Ligne 1 avec accents éàù\n.ligne commençant par un point\nFin', JSON.stringify(text));
  check('lignes de 76 caractères au plus', body.split('\r\n').every((l) => l.length <= 76), '');
  check('séquence SMTP correcte', JSON.stringify(m.cmds.filter((c) => c !== 'EHLO')) === JSON.stringify(['AUTH', 'MAIL', 'RCPT', 'DATA', 'QUIT']), JSON.stringify(m.cmds));

  out = await run(port, 'badpass');
  check('mauvais mot de passe : erreur claire, rien d\'envoyé', /ERREUR.*authentification.*535/.test(out) && received.length === 1, out);
  check('le message d\'erreur ne divulgue ni le mot de passe ni son encodage', !/bWF1dmFpcw|mauvais/.test(out), out);
  out = await run(port, 'badrcpt');
  check('destinataire refusé : erreur claire', /ERREUR.*RCPT.*550/.test(out), out);
  out = await run(port, 'verify');
  check('vérification de connexion sans envoi', /Connexion et authentification réussies/.test(out) && received.length === 1, out);
  out = await run(1, 'ok');
  check('serveur injoignable : erreur claire', /ERREUR : Connexion SMTP impossible/.test(out), out);

  server.close();
  console.log(failures ? failures + ' échec(s)' : 'Tous les contrôles SMTP passent');
  process.exit(failures ? 1 : 0);
});
