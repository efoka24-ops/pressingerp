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
            'agencies' => Database::all('SELECT id, name, is_workshop FROM agencies ORDER BY is_workshop, name'),
            'pinMode'  => input('mode') === 'pin',
        ], null);
    }

    public function login(): void
    {
        $login = $this->str('login');
        $pin = $this->str('pin');
        $ok = $pin !== '' ? Auth::attempt($login, $pin, true) : Auth::attempt($login, $this->str('password'));
        if (!$ok) {
            $this->fail('Identifiant ou mot de passe incorrect.', '/login' . ($pin !== '' ? '?mode=pin' : ''));
        }
        if ($agency = $this->int('agency_id')) {
            $_SESSION['agency_id'] = $agency;
        }
        unset($_SESSION['_old']);
        Audit::log('auth.login', 'users', Auth::id());
        $to = $_SESSION['_intended'] ?? '/';
        unset($_SESSION['_intended']);
        redirect(str_starts_with($to, '/') ? $to : '/');
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('/login');
    }
}
