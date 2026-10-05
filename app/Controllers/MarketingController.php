<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Services\Audit;
use App\Services\MarketingService;
use App\Services\ScenarioService;
use App\Services\SettingsService;

final class MarketingController extends Controller
{
    public function index(): void
    {
        $svc = new MarketingService();
        $campaigns = Database::all('SELECT * FROM campaigns ORDER BY created_at DESC LIMIT 50');
        foreach ($campaigns as &$c) {
            $c['results'] = $c['status'] === 'envoyee' ? $svc->results((int)$c['id']) : null;
        }
        unset($c);
        $this->view('marketing/index', [
            'title'     => 'Marketing & Fidélité',
            'segments'  => array_map('count', $svc->segments()),
            'labels'    => MarketingService::SEGMENTS,
            'channels'  => MarketingService::CHANNELS,
            'campaigns' => $campaigns,
            'scenarios' => Database::all('SELECT * FROM marketing_scenarios ORDER BY code'),
            'validated' => (bool)SettingsService::get('seg.validated', 0),
            'th'        => MarketingService::thresholds(),
            'loyalty'   => Config::get('loyalty'),
            'points'    => (int)Database::value('SELECT COALESCE(SUM(loyalty_points), 0) FROM clients'),
            'referrals' => (int)Database::value('SELECT COUNT(*) FROM clients WHERE referred_by IS NOT NULL AND created_at > NOW() - INTERVAL 90 DAY'),
            'queue'     => (int)Database::value("SELECT COUNT(*) FROM messages WHERE status = 'en_attente'"),
        ]);
    }

    public function store(): void
    {
        $req = $this->required(['name' => 'Nom', 'message' => 'Message']);
        $segment = array_key_exists($this->str('segment'), MarketingService::SEGMENTS) ? $this->str('segment') : 'tous';
        $channel = array_key_exists($this->str('channel'), MarketingService::CHANNELS) ? $this->str('channel') : 'sms';
        $id = Database::insert('campaigns', [
            'name'         => mb_substr($req['name'], 0, 150),
            'channel'      => $channel,
            'segment'      => $segment,
            'message'      => mb_substr($req['message'], 0, 640),
            'scheduled_at' => $this->str('scheduled_at') ? date('Y-m-d H:i:s', strtotime($this->str('scheduled_at'))) : null,
            'status'       => $this->str('scheduled_at') ? 'planifiee' : 'brouillon',
            'created_at'   => now(),
        ]);
        Audit::log('campaign.create', 'campaigns', $id);
        $this->ok('Campagne créée.', '/marketing');
    }

    public function scenario(string $code): void
    {
        try {
            ScenarioService::update($code, $this->str('body'), $this->int('cooldown_days', 90), $this->str('active') === '1');
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Scénario enregistré.', '/marketing');
    }

    public function send(string $id): void
    {
        try {
            $n = (new MarketingService())->send((int)$id);
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok("$n message(s) mis en file d'envoi.", '/marketing');
    }
}
