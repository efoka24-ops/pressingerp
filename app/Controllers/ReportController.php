<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Services\ReportService;

/** Rapports journalier et mensuel, imprimables (Imprimer / PDF depuis le navigateur). */
final class ReportController extends Controller
{
    public function daily(): void
    {
        $ag = Auth::resolveAgencyScope($this->int('agence'), 'rapport.jour');
        $date = $this->str('date') ?: date('Y-m-d');
        try {
            $r = ReportService::daily($date, $ag);
        } catch (\DomainException $e) {
            $this->fail($e->getMessage(), '/rapports/journalier');
        }
        $this->view('reports/daily', ['title' => 'Rapport journalier ' . $date, 'r' => $r, 'agencyName' => $this->agencyName($ag), 'agencies' => $this->agencies()]);
    }

    public function monthly(): void
    {
        $ag = Auth::resolveAgencyScope($this->int('agence'), 'rapport.mois');
        $month = $this->str('mois') ?: date('Y-m');
        try {
            $r = ReportService::monthly($month, $ag);
        } catch (\DomainException $e) {
            $this->fail($e->getMessage(), '/rapports/mensuel');
        }
        $this->view('reports/monthly', ['title' => 'Rapport mensuel ' . $month, 'r' => $r, 'agencyName' => $this->agencyName($ag), 'agencies' => $this->agencies()]);
    }

    private function agencyName(int $ag): string
    {
        return $ag ? (string)Database::value('SELECT name FROM agencies WHERE id = ?', [$ag]) : 'Groupe consolidé';
    }

    private function agencies(): array
    {
        return Auth::scopedAgencyId() ? [] : Database::all('SELECT id, name FROM agencies WHERE is_workshop = 0 ORDER BY id');
    }
}
