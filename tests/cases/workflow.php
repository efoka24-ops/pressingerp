<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Domain\IncidentType;
use App\Domain\Step;
use App\Services\LossService;
use App\Services\SettingsService;
use App\Services\WorkflowService;

function fx_treatment(string $code): int
{
    return (int)Database::value('SELECT id FROM treatments WHERE code = ?', [$code]);
}

/** Pièce de test à l'étape donnée, avec un traitement éventuel. */
function fx_piece(?string $treatment = null, string $step = 'tri', string $status = 'a_traiter', int $price = 2000): int
{
    $g = fx_garment(fx_order(fx_agency(), fx_client()));
    Database::update('garments', [
        'treatment_id' => $treatment ? fx_treatment($treatment) : null, 'step' => $step, 'status' => $status, 'price' => $price,
    ], 'id = :id', ['id' => $g]);
    return $g;
}

/** Fait avancer la pièce jusqu'au contrôle qualité en suivant le parcours et renvoie les étapes traversées. */
function walk(int $garmentId, array $until = [Step::Controle]): array
{
    $wf = new WorkflowService();
    $seen = [];
    for ($i = 0; $i < 12; $i++) {
        $g = $wf->garment($garmentId);
        $step = Step::from($g['step']);
        $seen[] = $step->value;
        if (in_array($step, $until, true)) {
            return $seen;
        }
        $wf->complete($garmentId, null, $step === Step::Emballage ? 'R-1' : null);
    }
    throw new RuntimeException('parcours sans fin : ' . implode(',', $seen));
}

test('parcours : chaque traitement suit ses propres étapes puis contrôle qualité', function () {
    Auth::actAs(fx_user('atelier', fx_agency()));
    same(['tri', 'detachage', 'lavage', 'sechage', 'repassage', 'finition', 'controle'], walk(fx_piece('complet')));
    same(['tri', 'repassage', 'finition', 'controle'], walk(fx_piece('repassage')));
    same(['tri', 'lavage', 'sechage', 'repassage', 'finition', 'controle'], walk(fx_piece('lavage_repassage')));
    same(['tri', 'detachage', 'lavage', 'repassage', 'finition', 'controle'], walk(fx_piece('nettoyage_sec')));
    same(['tri', 'detachage', 'lavage', 'sechage', 'repassage', 'finition', 'controle'], walk(fx_piece(null)), 'sans traitement : parcours complet');
});

test('parcours : une reprise vers une étape hors parcours reprend la suite prévue', function () {
    $g = ['step' => 'lavage', 'treatment_id' => fx_treatment('repassage')];
    same(Step::Repassage, WorkflowService::nextStep($g));
    same(Step::Controle, WorkflowService::nextStep(['step' => 'finition', 'treatment_id' => fx_treatment('repassage')]));
    same(Step::Emballage, WorkflowService::nextStep(['step' => 'controle', 'treatment_id' => null]));
    same(null, WorkflowService::nextStep(['step' => 'retire', 'treatment_id' => null]));
});

test('RG5 : terminer sans prise en charge déclenche la prise en charge, tracée avec l\'opérateur', function () {
    $op = fx_user('atelier', fx_agency());
    Auth::actAs($op);
    $g = fx_piece('complet');
    (new WorkflowService())->complete($g);
    $events = array_reverse(WorkflowService::events($g));
    $actions = array_column($events, 'action');
    same(['prise_en_charge', 'termine', 'entree'], $actions, 'trace complète');
    same((int)$op['id'], (int)$events[0]['user_id'], 'opérateur de la prise en charge');
    same('detachage', Database::value('SELECT step FROM garments WHERE id = ?', [$g]));
    same('a_traiter', Database::value('SELECT status FROM garments WHERE id = ?', [$g]), 'prochain poste : à traiter');
});

test('RG5 : une pièce prise en charge ne peut être terminée que par son opérateur ou un superviseur', function () {
    $agency = fx_agency();
    $a = fx_user('atelier', $agency);
    $b = fx_user('atelier', $agency);
    $g = fx_piece('complet');
    Auth::actAs($a);
    (new WorkflowService())->start($g);
    Auth::actAs($b);
    throws(fn() => (new WorkflowService())->complete($g), 'prise en charge par');
    throws(fn() => (new WorkflowService())->start($g), 'déjà prise en charge');
    Auth::actAs(fx_user('superviseur', $agency));
    (new WorkflowService())->complete($g);
    same('detachage', Database::value('SELECT step FROM garments WHERE id = ?', [$g]));
});

test('RG5 : le contrôle qualité et les étapes finales ne se valident pas depuis l\'atelier', function () {
    Auth::actAs(fx_user('atelier', fx_agency()));
    throws(fn() => (new WorkflowService())->complete(fx_piece('complet', 'controle')), 'Qualité');
    throws(fn() => (new WorkflowService())->start(fx_piece('complet', 'pret', 'a_traiter')), 'Aucune action');
});

test('emballage : l\'emplacement de rangement est obligatoire', function () {
    Auth::actAs(fx_user('atelier', fx_agency()));
    $g = fx_piece('complet', 'emballage');
    Database::insert('quality_checks', ['garment_id' => $g, 'user_id' => Auth::id(), 'result' => 'conforme', 'created_at' => now()]);   // le contrôle qualité a eu lieu
    throws(fn() => (new WorkflowService())->complete($g), 'rail');
    (new WorkflowService())->complete($g, null, 'R-07');
    same('pret', Database::value('SELECT step FROM garments WHERE id = ?', [$g]));
});

test('incident : type et description obligatoires, la pièce est bloquée puis débloquée', function () {
    Auth::actAs(fx_user('atelier', fx_agency()));
    $wf = new WorkflowService();
    $g = fx_piece('complet');
    throws(fn() => $wf->block($g, IncidentType::Tache, '   '), 'Décrivez');
    $id = $wf->block($g, IncidentType::Tache, 'Tache de vin tenace');
    same('bloque', Database::value('SELECT status FROM garments WHERE id = ?', [$g]));
    same('normal', Database::value('SELECT severity FROM incidents WHERE id = ?', [$id]));
    throws(fn() => $wf->block($g, IncidentType::Machine, 'autre'), 'déjà bloquée');
    throws(fn() => $wf->complete($g), 'bloquée');
    $wf->unblock($g, 'Détachant spécial appliqué');
    same('a_traiter', Database::value('SELECT status FROM garments WHERE id = ?', [$g]));
    $inc = Database::one('SELECT * FROM incidents WHERE id = ?', [$id]);
    ok($inc['resolved_at'] !== null && $inc['resolution'] === 'Détachant spécial appliqué', 'incident résolu et motivé');
});

test('incident critique : sévérité critique et journalisation prioritaire (RG7)', function () {
    Auth::actAs(fx_user('atelier', fx_agency()));
    $g = fx_piece('complet');
    $id = (new WorkflowService())->block($g, IncidentType::Endommage, 'Brûlure au repassage');
    same('critical', Database::value('SELECT severity FROM incidents WHERE id = ?', [$id]));
    $a = Database::one("SELECT * FROM audit_log WHERE action = 'incident.critical' ORDER BY id DESC LIMIT 1");
    ok($a !== null && (int)$a['entity_id'] === $g, 'incident critique non journalisé');
    same(9, count(IncidentType::cases()), 'les 9 types du cahier des charges');
});

test('scan : recherche manuelle quand le QR est illisible (SE5)', function () {
    $order = fx_order(fx_agency(), fx_client());
    $g1 = fx_garment($order);
    $code = (string)Database::value('SELECT code FROM garments WHERE id = ?', [$g1]);
    $number = (string)Database::value('SELECT number FROM orders WHERE id = ?', [$order]);
    same([$code], array_column(WorkflowService::findGarments($code), 'code'), 'code exact');
    same([$code], array_column(WorkflowService::findGarments(strtolower($code)), 'code'), 'casse ignorée');
    ok(in_array($code, array_column(WorkflowService::findGarments($number), 'code'), true), 'par numéro de commande');
    ok(in_array($code, array_column(WorkflowService::findGarments(substr($code, 3, 10)), 'code'), true), 'par fragment');
    same([], WorkflowService::findGarments('ab'), 'trop court');
    same([], WorkflowService::findGarments('%'), 'les jokers ne ratissent pas toute la base');
});

test('sinistre : déclaration bloquante puis indemnité plafonnée à 10 × le prix (D8)', function () {
    $agency = fx_agency();
    Auth::actAs(fx_user('atelier', $agency));
    $g = fx_piece('complet', 'lavage', 'a_traiter', 3000);
    $loss = new LossService();
    throws(fn() => $loss->declare($g, 'perdu', ''), 'Décrivez');
    throws(fn() => $loss->declare($g, 'autre', 'x'), 'invalide');
    $id = $loss->declare($g, 'perdu', 'Introuvable après le séchage');
    same('bloque', Database::value('SELECT status FROM garments WHERE id = ?', [$g]), 'pièce bloquée');
    throws(fn() => $loss->declare($g, 'perdu', 'doublon'), 'déjà en attente');

    Auth::actAs(fx_user('manager', $agency));
    same(30000, LossService::cap($g));
    throws(fn() => $loss->decide($id, true, 30001, 'trop'), 'plafond');
    throws(fn() => $loss->decide($id, true, 0, 'x'), 'montant');
    throws(fn() => $loss->decide($id, true, 20000, ''), 'Motif');
    $loss->decide($id, true, 20000, 'Accord client');
    same('acceptee', Database::value('SELECT status FROM garment_losses WHERE id = ?', [$id]));
    throws(fn() => $loss->decide($id, false, 0, 'encore'), 'déjà été traité');
    $a = Database::one("SELECT * FROM audit_log WHERE action = 'loss.decide' ORDER BY id DESC LIMIT 1");
    same('Accord client', $a['reason']);
});

test('sinistre : le plafond suit le paramètre d\'indemnisation', function () {
    Auth::actAs(fx_user('admin', fx_agency()));
    SettingsService::set('compensation.max_factor', '3', 'test');
    same(6000, LossService::cap(fx_piece(null, 'tri', 'a_traiter', 2000)));
});

test('parcours : les étapes sont toujours dans l\'ordre canonique et le contrôle qualité reste obligatoire', function () {
    foreach (Database::all('SELECT steps FROM treatments') as $t) {
        $steps = explode(',', $t['steps']);
        $canon = array_values(array_intersect(WorkflowService::WORK_STEPS, $steps));
        same($canon, $steps, 'ordre canonique : ' . $t['steps']);
    }
    $route = array_map(fn($s) => $s->value, WorkflowService::route(fx_treatment('repassage')));
    ok(in_array('controle', $route, true) && in_array('emballage', $route, true) && $route[0] === 'tri', 'contrôle et emballage obligatoires');
});
