<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\AccountService;

/** Changement de mot de passe de l'utilisateur connecté ; obligatoire à la première connexion avec un mot de passe provisoire. */
final class AccountController extends Controller
{
    public function form(): void
    {
        $forced = (bool)(Auth::user()['must_change_password'] ?? false);
        $this->view('auth/password', ['title' => 'Mon mot de passe', 'forced' => $forced], $forced ? 'public' : 'layout');
    }

    public function change(): void
    {
        try {
            AccountService::changePassword((array)Auth::user(), $this->str('current'), (string)($_POST['new'] ?? ''), (string)($_POST['confirm'] ?? ''));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage(), '/mot-de-passe');
        }
        $this->ok('Mot de passe modifié.', '/');
    }
}
