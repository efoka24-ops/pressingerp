<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Services\Audit;

final class AuthController extends Controller
{
    public function form(): void
    {
        if (Auth::user()) {
            redirect('/');
        }
        $this->view('auth/login', [
            'title'    => 'Connexion',
            'pinMode'  => input('mode') === 'pin',
        ], null);
    }

    /** Connexion par nom d'utilisateur et mot de passe : l'agence est celle du compte, jamais choisie ici. */
    public function login(): void
    {
        $login = $this->str('login');
        $pin = $this->str('pin');
        $ok = $pin !== '' ? Auth::attempt($login, $pin, true) : Auth::attempt($login, $this->str('password'));
        if (!$ok) {
            $this->fail('Nom d\'utilisateur ou mot de passe incorrect.', '/login' . ($pin !== '' ? '?mode=pin' : ''));
        }
        unset($_SESSION['_old']);
        Audit::log('auth.login', 'users', Auth::id());
        if (!empty(Auth::user()['must_change_password'])) {
            redirect('/mot-de-passe');   // mot de passe provisoire : à remplacer avant toute chose
        }
        $to = $_SESSION['_intended'] ?? '/';
        unset($_SESSION['_intended']);
        redirect(str_starts_with($to, '/') ? $to : '/');
    }

    /** Administrateur et direction : changer d'agence de travail une fois connecté (pas à la connexion). */
    public function switchAgency(): void
    {
        $agency = $this->int('agency_id');
        if (!Auth::role()?->canSwitchAgency() || !Database::value('SELECT id FROM agencies WHERE id = ?', [$agency])) {
            $this->fail('Changement d\'agence non autorisé.', '/');
        }
        $_SESSION['agency_id'] = $agency;
        Audit::log('auth.switch_agency', 'agencies', $agency);
        $this->back('/');
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('/login');
    }
}
