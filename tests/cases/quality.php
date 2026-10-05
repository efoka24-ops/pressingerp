<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Domain\GarmentStatus;
use App\Domain\Step;
use App\Services\OrderService;
use App\Services\QualityGate;
use App\Services\QualityService;
use App\Services\WorkflowService;

/** Pièce au contrôle qualité (parcours complet). */
function fx_at_control(?int $order = null): int
{
    $g = $order ? fx_garment($order) : fx_garment(fx_order(fx_agency(), fx_client()));
    Database::update('garments', ['step' => 'controle', 'status' => 'a_traiter', 'treatment_id' => fx_treatment('complet')], 'id = :id', ['id' => $g]);
    return $g;
}

test('qualité RG8 : sans contrôle conforme, l\'emballage et « Prêt » sont refusés côté serveur', function () {
    Auth::actAs(fx_user('atelier', fx_agency()));
    $wf = new WorkflowService();
    $g = fx_at_control();
    throws(fn() => $wf->moveTo($g, Step::Emballage, GarmentStatus::ATraiter), 'Contrôle qualité non validé');
    throws(fn() => $wf->moveTo($g, Step::Pret, GarmentStatus::Termine), 'Contrôle qualité non validé');
    // une pièce arrivée à l'emballage sans contrôle (donnée incohérente) ne peut pas non plus avancer
    $bad = fx_piece('complet', 'emballage');
    throws(fn() => $wf->complete($bad, null, 'R-1'), 'Contrôle qualité non validé');
    same('emballage', Database::value('SELECT step FROM garments WHERE id = ?', [$bad]), 'la pièce n\'a pas bougé');
});

test('qualité : un contrôle conforme ouvre le passage jusqu\'à « Prêt »', function () {
    $agency = fx_agency();
    Auth::actAs(fx_user('qualite', $agency));
    $g = fx_at_control();
    same('conforme', (new QualityService())->check($g, [], null, null));
    same('emballage', Database::value('SELECT step FROM garments WHERE id = ?', [$g]));
    Auth::actAs(fx_user('atelier', $agency));
    (new WorkflowService())->complete($g, null, 'R-12');
    same('pret', Database::value('SELECT step FROM garments WHERE id = ?', [$g]));
});

test('qualité RG9 : une reprise exige un motif et une étape, renvoie la pièce et annule toute validation', function () {
    Auth::actAs(fx_user('qualite', fx_agency()));
    $svc = new QualityService();
    $g = fx_at_control();
    throws(fn() => $svc->check($g, ['repassage'], null, Step::Repassage), 'Motif de reprise obligatoire');
    throws(fn() => $svc->check($g, ['repassage'], 'Motif inventé', Step::Repassage), 'Motif de reprise obligatoire');
    throws(fn() => $svc->check($g, ['repassage'], 'Repassage imparfait', null), 'étape de reprise');
    throws(fn() => $svc->check($g, ['repassage'], 'Repassage imparfait', Step::Controle), 'étape de reprise');
    same('reprise', $svc->check($g, ['repassage'], 'Repassage imparfait', Step::Repassage));
    $row = Database::one('SELECT step, status, rework_count FROM garments WHERE id = ?', [$g]);
    same('repassage', $row['step']);
    same('a_reprendre', $row['status']);
    same(1, (int)$row['rework_count']);
    // la pièce revient au contrôle : aucune validation ancienne ne subsiste, il faut un nouveau contrôle conforme
    Database::update('garments', ['step' => 'controle', 'status' => 'a_traiter'], 'id = :id', ['id' => $g]);
    throws(fn() => (new WorkflowService())->moveTo($g, Step::Emballage, GarmentStatus::ATraiter), 'Contrôle qualité non validé');
    same('conforme', $svc->check($g, [], null, null));
    same('emballage', Database::value('SELECT step FROM garments WHERE id = ?', [$g]));
});

test('qualité : le contrôle compte les 9 critères et refuse une pièce hors contrôle', function () {
    same(9, count(QualityService::CRITERIA));
    Auth::actAs(fx_user('qualite', fx_agency()));
    throws(fn() => (new QualityService())->check(fx_piece('complet', 'lavage'), [], null, null), 'pas au contrôle');
});

test('dérogation : réservée aux responsables, motivée, tracée, et débloque seulement cette pièce', function () {
    $agency = fx_agency();
    $gate = new QualityGate();
    $g = fx_at_control();
    $other = fx_at_control();

    foreach (['atelier', 'qualite', 'comptoir', 'superviseur'] as $role) {
        Auth::actAs(fx_user($role, $agency));
        throws(fn() => $gate->override($g, 'Client pressé, validation orale du gérant'), 'réservée à un responsable');
    }
    Auth::actAs(fx_user('manager', $agency));
    throws(fn() => $gate->override($g, 'court'), 'Motif détaillé');
    throws(fn() => $gate->override(fx_piece('complet', 'lavage'), 'Pièce pas au contrôle du tout'), 'attente de contrôle');

    $gate->override($g, 'Client pressé, validation orale du gérant');
    same('emballage', Database::value('SELECT step FROM garments WHERE id = ?', [$g]), 'la pièce passe à l\'emballage');
    ok(QualityGate::passed($g), 'dérogation valide');
    ok(!QualityGate::passed($other), 'l\'autre pièce reste bloquée');
    $o = Database::one('SELECT * FROM quality_overrides WHERE garment_id = ?', [$g]);
    same('Client pressé, validation orale du gérant', $o['reason']);
    $a = Database::one("SELECT * FROM audit_log WHERE action = 'quality.override' AND entity_id = ?", [$g]);
    ok($a !== null && $a['reason'] === $o['reason'], 'audit avec motif');
    ok(in_array('derogation', array_column(WorkflowService::events($g), 'action'), true), 'événement dans l\'historique de la pièce');

    Auth::actAs(fx_user('atelier', $agency));
    (new WorkflowService())->complete($g, null, 'R-3');
    same('pret', Database::value('SELECT step FROM garments WHERE id = ?', [$g]));
    throws(fn() => (new WorkflowService())->moveTo($other, Step::Pret, GarmentStatus::Termine), 'Contrôle qualité non validé');
});

test('dérogation : une reprise ultérieure l\'annule', function () {
    $agency = fx_agency();
    Auth::actAs(fx_user('direction', $agency));
    $g = fx_at_control();
    (new QualityGate())->override($g, 'Validation exceptionnelle de la direction');
    ok(QualityGate::passed($g));
    // la pièce est contrôlée plus tard et rejetée : la dérogation ne vaut plus
    Database::update('garments', ['step' => 'controle'], 'id = :id', ['id' => $g]);
    Database::run('UPDATE quality_overrides SET created_at = ? WHERE garment_id = ?', [date('Y-m-d H:i:s', time() - 3600), $g]);
    Auth::actAs(fx_user('qualite', $agency));
    (new QualityService())->check($g, ['odeur'], 'Odeur', Step::Lavage);
    Database::update('garments', ['step' => 'controle', 'status' => 'a_traiter'], 'id = :id', ['id' => $g]);
    ok(!QualityGate::passed($g), 'la reprise postérieure annule la dérogation');
});

test('retrait : une commande dont une pièce n\'a pas de contrôle valide ne peut pas être remise', function () {
    Auth::actAs(fx_user('direction', fx_agency()));
    $order = fx_order(fx_agency(), fx_client());
    $g = fx_garment($order);
    Database::update('garments', ['step' => 'pret', 'status' => 'termine'], 'id = :id', ['id' => $g]);
    Database::update('orders', ['status' => 'pret', 'paid' => 5000], 'id = :id', ['id' => $order]);
    throws(fn() => (new OrderService())->pickup($order, null), 'contrôle qualité');
    same('pret', Database::value('SELECT step FROM garments WHERE id = ?', [$g]), 'pièce non remise');
    Database::insert('quality_checks', ['garment_id' => $g, 'user_id' => Auth::id(), 'result' => 'conforme', 'created_at' => now()]);
    (new OrderService())->pickup($order, null);
    same('retire', Database::value('SELECT step FROM garments WHERE id = ?', [$g]));
});
