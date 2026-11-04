<?php
/** Processo de trabalho da prova de concorrência (ver concurrency-db.php). Espera um instante comum e executa UMA operação. */
require __DIR__ . '/../../src/bootstrap.php';
$a = json_decode($argv[1], true);
while (microtime(true) < $a['at']) { /* barreira: todos os processos começam juntos */ }
$user = Db::one('SELECT * FROM users WHERE id = ?', [$a['user']]);
try {
    switch ($a['op']) {
        case 'open': $r = Floor::open($a['in'], $user, $a['key']); break;
        case 'round': $r = Floor::sendRound((int)$a['tab'], $a['items'], $user, $a['key']); break;
        case 'transition': $r = Orders::transition((int)$a['id'], $a['to'], (int)$a['version'], $user, $a['reason'] ?? null); $r = ['status' => $r['status'], 'version' => $r['version']]; break;
        case 'pay': $r = Floor::registerPayment((int)$a['tab'], (int)$a['amount'], 'cash', $user, $a['key']); break;
        case 'close': Floor::closeTab((int)$a['tab'], $user); $r = ['closed' => true]; break;
        case 'create': $r = Orders::create($a['in'], $a['key']); $r = ['id' => $r['id'] ?? null, 'dup' => $r['duplicate'] ?? null]; break;
        case 'bill': Floor::requestBill((int)$a['tab'], $user); $r = ['bill' => true]; break;
        default: throw new Exception('op?');
    }
    echo json_encode(['ok' => true, 'r' => $r, 't' => round(microtime(true), 3)]);
} catch (HttpError $e) { echo json_encode(['ok' => false, 'http' => $e->status, 'msg' => $e->getMessage()]); }
catch (Throwable $e) { echo json_encode(['ok' => false, 'erro' => get_class($e) . ': ' . $e->getMessage()]); }
