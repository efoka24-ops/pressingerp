-- Le compte « admin » créé à l'installation devient administrateur système (rôle ajouté en phase 1)
UPDATE users SET role = 'admin' WHERE login = 'admin' AND role = 'direction'
