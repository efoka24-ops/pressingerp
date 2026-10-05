<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Domain\Role;
use App\Services\Messaging\Gateways;

/**
 * Demande d'ouverture d'un pressing : déposée publiquement par le futur responsable, validée (ou refusée) par le super administrateur.
 * À la validation : l'agence et le compte du responsable sont créés ensemble, et le responsable reçoit par e-mail son identifiant
 * et un mot de passe provisoire qu'il doit changer à sa première connexion.
 */
final class AgencyRequestService
{
    private const MAX_PER_HOUR = 3;

    /** @param array<string,mixed> $in */
    public function submit(array $in, ?string $ip = null): int
    {
        $f = [];
        foreach (['pressing_name' => 'Nom du pressing', 'city' => 'Ville', 'address' => 'Adresse', 'phone' => 'Téléphone', 'manager_name' => 'Nom du responsable', 'email' => 'E-mail'] as $k => $label) {
            $f[$k] = trim((string)($in[$k] ?? ''));
            if ($f[$k] === '') {
                throw new \DomainException("$label obligatoire.");
            }
        }
        if (mb_strlen($f['pressing_name']) < 3 || mb_strlen($f['pressing_name']) > 100) {
            throw new \DomainException('Nom du pressing : 3 à 100 caractères.');
        }
        if (mb_strlen($f['address']) < 5) {
            throw new \DomainException('Adresse précise obligatoire (quartier, rue, repère).');
        }
        if (count(preg_split('/\s+/', $f['manager_name'])) < 2) {
            throw new \DomainException('Indiquez le prénom et le nom du responsable.');
        }
        if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('Adresse e-mail invalide : les identifiants y seront envoyés.');
        }
        $f['phone'] = ClientService::normalizePhone($f['phone']);
        if ($err = ClientService::phoneError($f['phone'])) {
            throw new \DomainException($err);
        }
        if (Database::value("SELECT id FROM agency_requests WHERE email = ? AND status = 'en_attente'", [mb_strtolower($f['email'])])) {
            throw new \DomainException('Une demande est déjà en cours d\'examen pour cette adresse e-mail.');
        }
        $ipHash = $ip ? hash('sha256', $ip . '|' . Config::get('audit.key', '')) : null;
        if ($ipHash && (int)Database::value('SELECT COUNT(*) FROM agency_requests WHERE ip_hash = ? AND created_at >= ?', [$ipHash, date('Y-m-d H:i:s', time() - 3600)]) >= self::MAX_PER_HOUR) {
            throw new \DomainException('Trop de demandes depuis votre connexion. Réessayez plus tard.');
        }
        $id = Database::insert('agency_requests', [
            'pressing_name' => $f['pressing_name'], 'city' => mb_substr($f['city'], 0, 80), 'address' => mb_substr($f['address'], 0, 200), 'phone' => $f['phone'],
            'manager_name' => mb_substr($f['manager_name'], 0, 100), 'email' => mb_strtolower($f['email']), 'message' => ($m = trim((string)($in['message'] ?? ''))) !== '' ? mb_substr($m, 0, 500) : null,
            'ip_hash' => $ipHash, 'created_at' => now(),
        ]);
        Audit::log('agency_request.submit', 'agency_requests', $id, ['pressing' => $f['pressing_name'], 'city' => $f['city']]);
        AlertService::safe(fn() => AlertService::raise('agency_request', 'areq:' . $id, "Demande d'ouverture de pressing : {$f['pressing_name']} ({$f['city']}), responsable {$f['manager_name']}.", 'agency_request', $id));
        return $id;
    }

    /**
     * Valide la demande : crée l'agence et le responsable, envoie ses identifiants par e-mail.
     * @return array{agency_id:int,login:string,password:string,mail:string,mail_error:?string}
     */
    public function approve(int $id, string $code = '', string $name = ''): array
    {
        $this->assertSuperAdmin();
        $created = Database::transaction(function () use ($id, $code, $name): array {
            $r = Database::one('SELECT * FROM agency_requests WHERE id = ? FOR UPDATE', [$id]) ?? throw new \DomainException('Demande introuvable.');
            if ($r['status'] !== 'en_attente') {
                throw new \DomainException('Cette demande a déjà été traitée.');
            }
            $name = trim($name) !== '' ? trim($name) : $r['pressing_name'];
            $code = strtoupper(trim($code)) !== '' ? strtoupper(trim($code)) : self::freeCode($r['city']);
            if (!preg_match('/^[A-Z0-9]{2,10}$/', $code)) {
                throw new \DomainException('Code agence : 2 à 10 lettres ou chiffres.');
            }
            if (Database::value('SELECT id FROM agencies WHERE code = ?', [$code])) {
                throw new \DomainException("Le code $code existe déjà : choisissez-en un autre.");
            }
            $agency = Database::insert('agencies', ['code' => $code, 'name' => mb_substr($name, 0, 100), 'phone' => $r['phone'], 'is_workshop' => 0]);
            Audit::log('agency.create', 'agencies', $agency, ['request' => $id], null, ['code' => $code, 'name' => $name]);
            $login = self::freeLogin($r['manager_name']);
            $password = self::password();
            $uid = Database::insert('users', [
                'agency_id' => $agency, 'name' => $r['manager_name'], 'login' => $login, 'role' => Role::Manager->value, 'active' => 1,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'must_change_password' => 1,
            ]);
            Audit::log('user.create', 'users', $uid, ['login' => $login, 'request' => $id], null, ['role' => Role::Manager->value, 'agency_id' => $agency]);
            Database::update('agency_requests', ['status' => 'validee', 'reviewed_by' => Auth::id(), 'reviewed_at' => now(), 'agency_id' => $agency, 'user_id' => $uid], 'id = :id', ['id' => $id]);
            Audit::log('agency_request.approve', 'agency_requests', $id, ['agency_id' => $agency, 'user_id' => $uid]);
            return ['request' => $r, 'agency_id' => $agency, 'login' => $login, 'password' => $password, 'agency_name' => $name];
        });
        AlertService::safe(fn() => AlertService::close('areq:' . $id, 'demande traitée'));

        $mail = $this->mail($created['request']['email'], 'Votre pressing est validé : vos identifiants', $this->welcomeBody($created));
        Database::update('agency_requests', ['mail_status' => $mail['status']], 'id = :id', ['id' => $id]);
        return ['agency_id' => $created['agency_id'], 'login' => $created['login'], 'password' => $created['password'], 'mail' => $mail['status'], 'mail_error' => $mail['error']];
    }

    /** @return array{mail:string,mail_error:?string} */
    public function reject(int $id, string $reason): array
    {
        $this->assertSuperAdmin();
        $reason = trim($reason);
        if (mb_strlen($reason) < 8) {
            throw new \DomainException('Motif du refus obligatoire (8 caractères minimum) : il est communiqué au demandeur.');
        }
        $r = Database::transaction(function () use ($id, $reason): array {
            $r = Database::one('SELECT * FROM agency_requests WHERE id = ? FOR UPDATE', [$id]) ?? throw new \DomainException('Demande introuvable.');
            if ($r['status'] !== 'en_attente') {
                throw new \DomainException('Cette demande a déjà été traitée.');
            }
            Database::update('agency_requests', ['status' => 'refusee', 'reviewed_by' => Auth::id(), 'reviewed_at' => now(), 'reject_reason' => mb_substr($reason, 0, 255)], 'id = :id', ['id' => $id]);
            Audit::log('agency_request.reject', 'agency_requests', $id, [], null, null, $reason);
            return $r;
        });
        AlertService::safe(fn() => AlertService::close('areq:' . $id, 'demande traitée'));
        $mail = $this->mail($r['email'], 'Votre demande d\'ouverture de pressing', "Bonjour {$r['manager_name']},\n\nVotre demande d'ouverture du pressing « {$r['pressing_name']} » n'a pas pu être acceptée pour le moment.\n\nMotif : $reason\n\nVous pouvez déposer une nouvelle demande une fois ce point réglé.\n");
        Database::update('agency_requests', ['mail_status' => $mail['status']], 'id = :id', ['id' => $id]);
        return ['mail' => $mail['status'], 'mail_error' => $mail['error']];
    }

    // ---------------------------------------------------------------- Aides

    private function assertSuperAdmin(): void
    {
        if (Auth::role() !== Role::Admin) {
            throw new \DomainException('Seul le super administrateur valide les demandes.');
        }
    }

    /** @return array{status:string,error:?string} envoye | echec | non_configure */
    private function mail(string $to, string $subject, string $body): array
    {
        $gw = Gateways::for('email');
        if (!$gw || !$gw->configured()) {
            return ['status' => 'non_configure', 'error' => 'Le canal e-mail n\'est pas configuré.'];
        }
        try {
            $gw->send($to, $subject, $body);
            return ['status' => 'envoye', 'error' => null];
        } catch (\Throwable $e) {
            return ['status' => 'echec', 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function welcomeBody(array $c): string
    {
        $url = rtrim((string)Config::get('app.url', ''), '/') . '/login';
        return "Bonjour {$c['request']['manager_name']},\n\n"
            . "Votre pressing « {$c['agency_name']} » est validé. Voici vos identifiants pour accéder à votre espace :\n\n"
            . "Adresse : $url\n"
            . "Nom d'utilisateur : {$c['login']}\n"
            . "Mot de passe provisoire : {$c['password']}\n\n"
            . "Pour votre sécurité, vous devrez choisir un nouveau mot de passe dès votre première connexion. Ne partagez pas ces identifiants.\n";
    }

    private static function password(): string
    {
        return substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 12) . random_int(10, 99);
    }

    /** Nom d'utilisateur « prenom.nom » en minuscules sans accents, rendu unique. */
    public static function freeLogin(string $fullName): string
    {
        $ascii = strtolower(strtr($fullName, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
            'À' => 'a', 'Â' => 'a', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Î' => 'i', 'Ô' => 'o', 'Ù' => 'u', 'Û' => 'u', 'Ç' => 'c',
        ]));
        $parts = array_values(array_filter(preg_split('/[^a-z0-9]+/', $ascii) ?: []));
        $base = count($parts) >= 2 ? $parts[0] . '.' . $parts[count($parts) - 1] : ($parts[0] ?? 'responsable');
        $base = substr($base, 0, 34);
        $login = $base;
        for ($i = 2; Database::value('SELECT id FROM users WHERE login = ?', [$login]); $i++) {
            $login = $base . $i;
        }
        return $login;
    }

    /** Code agence de 3 lettres tiré de la ville (GAR pour Garoua), suffixé d'un numéro s'il est pris. */
    public static function freeCode(string $city): string
    {
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', strtr($city, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'É' => 'E', 'ç' => 'c', 'à' => 'a'])) ?? '');
        $base = substr($letters ?: 'AGE', 0, 3);
        $code = $base;
        for ($i = 2; Database::value('SELECT id FROM agencies WHERE code = ?', [$code]); $i++) {
            $code = $base . $i;
        }
        return $code;
    }
}
