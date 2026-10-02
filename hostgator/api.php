<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function initial_state(array $bank): array {
    return [
        'phase' => 'lobby', 'scores' => [5, 5, 5, 5], 'winScore' => 16, 'out' => [], 'players' => [],
        'questions' => $bank, 'used' => [], 'current' => null, 'round' => 0,
        'winner' => null, 'summary' => null, 'roulette' => null, 'mines' => null, 'vote' => null,
        'betsUsed' => [[], [], [], []], 'streaks' => [0, 0, 0, 0],
        'skipNext' => [false, false, false, false], 'streakWheel' => null,
        'notice' => 'Aguardando o criador iniciar.'
    ];
}

function pick_question(array &$s): ?array {
    $available = array_values(array_filter($s['questions'],
        static fn($q) => !in_array($q['id'], $s['used'], true)));
    if (!$available) return null;
    $q = $available[random_int(0, count($available) - 1)];
    $s['used'][] = $q['id'];
    return $q;
}

function allowed_bets(array $s, int $team): array {
    if ($s['phase'] === 'bet' && in_array($team, $s['current']['blockedTeams'] ?? [], true)) return [];
    $score = $s['scores'][$team];
    $max = $score < 0 ? min(3, -$score) : 3;
    $withinScore = array_values(array_filter([1, 2, 3], static fn($bet) => $bet <= $max));
    $used = $s['betsUsed'][$team] ?? [];
    $unused = array_values(array_diff($withinScore, $used));
    // A negative score can make every unused value temporarily unavailable.
    return $unused ?: $withinScore;
}

function shuffle_question_words(string $question): string {
    $words = preg_split('/\s+/u', trim($question), -1, PREG_SPLIT_NO_EMPTY);
    if (!$words || count($words) < 3) return $question;
    $original = $words;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        shuffle($words);
        if ($words !== $original) break;
    }
    return implode(' ', $words);
}

function settle_round(array &$s): void {
    $team = $s['summary']['team'];
    foreach ([0, 1, 2, 3] as $i) {
        if ($s['scores'][$i] >= $s['winScore'] && !in_array($i, $s['out'], true)) {
            $s['phase'] = 'finished'; $s['winner'] = $i; $s['current'] = null; return;
        }
    }
    $others = array_values(array_filter([0, 1, 2, 3],
        static fn($i) => $i !== $team && !in_array($i, $s['out'], true)));
    foreach (array_merge([$team], $others) as $i) {
        if ($s['scores'][$i] <= -4 && !in_array($i, $s['out'], true)) {
            $s['phase'] = 'survival_wait'; $s['current'] = ['team' => $i]; return;
        }
    }
    $s['phase'] = 'review'; $s['current'] = null;
}

function roulette_color(int $slot): string {
    if ($slot === 0 || $slot === 18) return 'green';
    return $slot < 18
        ? ($slot % 2 === 0 ? 'black' : 'red')
        : ($slot % 2 === 0 ? 'red' : 'black');
}

function begin_vote(array &$s, string $type, int $team, int $now): void {
    $s['vote'] = ['type' => $type, 'team' => $team, 'deadline' => $now + 10000,
        'eligible' => array_values(array_map(static fn($p) => $p['id'],
            array_filter($s['players'], static fn($p) => $p['team'] === $team))),
        'ballots' => []];
}

function vote_tallies(array $s): array {
    $eligible = $s['vote']['eligible'] ?? [];
    $current = array_column($s['players'], 'id');
    $counts = ['yes' => 0, 'no' => 0, 'red' => 0, 'black' => 0, 'green' => 0];
    foreach ($s['vote']['ballots'] ?? [] as $id => $choice) {
        if (!in_array($id, $eligible, true) || !in_array($id, $current, true)) continue;
        if (isset($counts[$choice])) $counts[$choice]++;
    }
    return $counts;
}

function mine_receive_tally(array $s): array {
    $eligible = $s['mines']['receiveEligible'] ?? [];
    $current = array_column($s['players'], 'id');
    $active = array_values(array_intersect($eligible, $current));
    $votes = array_intersect(array_keys($s['mines']['receiveVotes'] ?? []), $active);
    return ['count' => count($votes), 'required' => max(1, (int) ceil(count($active) / 2))];
}

function begin_mines(array &$s, int $now): void {
    $board = array_fill(0, 25, 'diamond');
    $slots = range(0, 24);
    shuffle($slots);
    foreach (array_slice($slots, 0, 8) as $slot) $board[$slot] = 'mine';
    $s['mines']['status'] = 'playing';
    $s['mines']['board'] = $board;
    $s['mines']['opened'] = [];
    $s['mines']['diamonds'] = 0;
    $s['mines']['receiveEligible'] = array_values(array_map(static fn($p) => $p['id'],
        array_filter($s['players'], static fn($p) => $p['team'] === $s['mines']['team'])));
    $s['mines']['receiveVotes'] = [];
    $s['mines']['deadline'] = $now + 45000;
    $s['phase'] = 'mines';
    $s['notice'] = 'Campo minado: abra uma casa antes de receber o bônus.';
}

function conclude_vote(array &$s, int $now): void {
    $tally = vote_tallies($s);
    $play = $tally['yes'] > $tally['no'];
    $type = $s['vote']['type'];
    $s['vote'] = null;
    if (!$play) {
        if ($type === 'roulette') $s['roulette'] = null;
        else $s['mines'] = null;
        $s['notice'] = 'A equipe decidiu não jogar ' . ($type === 'roulette' ? 'a roleta' : 'o campo minado') . '.';
        settle_round($s);
        return;
    }
    if ($type === 'roulette') {
        $s['phase'] = 'roulette_choice';
        $s['roulette']['status'] = 'choice';
        begin_vote($s, 'color', $s['roulette']['team'], $now);
        $s['notice'] = 'A equipe votou para jogar a roleta. Vote em uma cor.';
    } else begin_mines($s, $now);
}

function conclude_color_vote(array &$s, int $now): void {
    $counts = vote_tallies($s);
    $colors = ['red', 'black', 'green'];
    $highest = max($counts['red'], $counts['black'], $counts['green']);
    $tied = array_values(array_filter($colors, static fn($color) => $counts[$color] === $highest));
    $choice = $tied[random_int(0, count($tied) - 1)];
    $voters = array_values(array_map(static fn($p) => $p['name'],
        array_filter($s['players'], static fn($p) =>
            in_array($p['id'], $s['vote']['eligible'], true) &&
            ($s['vote']['ballots'][$p['id']] ?? null) === $choice)));
    $player = 'Equipe ' . ['Azul', 'Laranja', 'Amarela', 'Roxa'][$s['roulette']['team']] .
        ($voters ? ' (votos: ' . implode(', ', $voters) . ')' : ' (sorteio automático)');
    $s['vote'] = null;
    start_roulette_spin($s, $choice, $player, $now);
}

function start_roulette_spin(array &$s, string $choice, ?string $player, int $now): void {
    $slot = random_int(0, 35);
    $s['roulette']['status'] = 'spin';
    $s['roulette']['choice'] = $choice;
    $s['roulette']['player'] = $player;
    $s['roulette']['slot'] = $slot;
    $s['roulette']['result'] = roulette_color($slot);
    $s['roulette']['startedAt'] = $now;
    $s['roulette']['endsAt'] = $now + 6000;
    unset($s['roulette']['deadline']);
    $s['phase'] = 'roulette_spin';
    $s['notice'] = 'A roleta está girando!';
}

function settle_roulette(array &$s): void {
    $r = $s['roulette'];
    $won = $r['choice'] === $r['result'];
    $delta = $won ? $r['stake'] * ($r['choice'] === 'green' ? 1.5 : 0.5) : -$r['stake'];
    $roulette_changes = [0, 0, 0, 0];
    $s['scores'][$r['team']] = round($s['scores'][$r['team']] + $delta, 1);
    $s['summary']['changes'][$r['team']] = round($s['summary']['changes'][$r['team']] + $delta, 1);
    $roulette_changes[$r['team']] += $delta;
    $others = array_values(array_filter([0, 1, 2, 3],
        static fn($i) => $i !== $r['team'] && !in_array($i, $s['out'], true)));
    shuffle($others);
    $units = (int) round(abs($delta) * 2);
    for ($n = 0; $n < $units && $others; $n++) {
        $team = $n < count($others) ? $others[$n] : $others[random_int(0, count($others) - 1)];
        $transfer = $won ? -0.5 : 0.5;
        $s['scores'][$team] = round($s['scores'][$team] + $transfer, 1);
        $s['summary']['changes'][$team] = round($s['summary']['changes'][$team] + $transfer, 1);
        $roulette_changes[$team] = round($roulette_changes[$team] + $transfer, 1);
    }
    $s['summary']['roulette'] = [
        'choice' => $r['choice'], 'result' => $r['result'], 'slot' => $r['slot'],
        'won' => $won, 'delta' => $delta, 'stake' => $r['stake'],
        'player' => $r['player'] ?? null, 'changes' => $roulette_changes
    ];
    $s['roulette']['status'] = 'result';
    $names = ['Azul', 'Laranja', 'Amarela', 'Roxa'];
    $s['notice'] = 'Roleta: equipe ' . $names[$r['team']] .
        ($won ? ' ganhou ' : ' perdeu ') . abs($delta) . ' ponto(s).';
    settle_round($s);
}

function settle_mines(array &$s, bool $hitMine, ?string $player = null): void {
    $m = &$s['mines'];
    $bonus = round($m['stake'] * $m['diamonds'] / 10, 1);
    $delta = $hitMine ? -$bonus : $bonus;
    $changes = [0.0, 0.0, 0.0, 0.0];
    $team = $m['team'];
    $s['scores'][$team] = round($s['scores'][$team] + $delta, 1);
    $s['summary']['changes'][$team] = round($s['summary']['changes'][$team] + $delta, 1);
    $changes[$team] = $delta;
    $others = array_values(array_filter([0, 1, 2, 3],
        static fn($i) => $i !== $team && !in_array($i, $s['out'], true)));
    // Distribute tenths, preserving the total score across teams.
    for ($n = 0, $units = (int) round(abs($delta) * 10); $n < $units && $others; $n++) {
        $recipient = $others[random_int(0, count($others) - 1)];
        $transfer = $hitMine ? 0.1 : -0.1;
        $s['scores'][$recipient] = round($s['scores'][$recipient] + $transfer, 1);
        $s['summary']['changes'][$recipient] = round($s['summary']['changes'][$recipient] + $transfer, 1);
        $changes[$recipient] = round($changes[$recipient] + $transfer, 1);
    }
    $m['status'] = 'result';
    $m['outcome'] = $hitMine ? 'mine' : 'received';
    $m['player'] = $player ?? $m['player'] ?? null;
    unset($m['deadline']);
    $s['summary']['mines'] = ['diamonds' => $m['diamonds'], 'bonus' => $bonus,
        'delta' => $delta, 'outcome' => $m['outcome'], 'player' => $m['player'],
        'changes' => $changes];
    $s['notice'] = $hitMine
        ? 'Mina encontrada! O bônus acumulado virou uma perda de ' . $bonus . ' ponto(s).'
        : 'A equipe recebeu ' . $bonus . ' ponto(s) do campo minado.';
    settle_round($s);
}

function finish_round(array &$s, bool $correct): void {
    $c = $s['current'];
    $s['streaks'][$c['team']] = $correct ? min(3, $s['streaks'][$c['team']] + 1) : 0;
    $before = $s['scores'];
    $others = array_values(array_filter([0, 1, 2, 3],
        static fn($i) => $i !== $c['team'] && !in_array($i, $s['out'], true)));
    $sign = $correct ? 1 : -1;
    $s['scores'][$c['team']] = round($s['scores'][$c['team']] + $sign * $c['bet'], 1);
    shuffle($others);
    $selected = array_slice($others, 0, min(count($others), $c['bet'] === 3 ? 2 : $c['bet']));
    foreach ($selected as $i) $s['scores'][$i] = round($s['scores'][$i] - $sign, 1);
    for ($n = count($selected); $n < $c['bet']; $n++) {
        if ($selected) {
            $i = $selected[random_int(0, count($selected) - 1)];
            $s['scores'][$i] = round($s['scores'][$i] - $sign, 1);
        }
    }
    $names = ['Azul', 'Laranja', 'Amarela', 'Roxa'];
    $s['notice'] = $names[$c['team']] . ($correct ? ' acertou e ganhou ' : ' errou e perdeu ') . $c['bet'] . ' ponto(s).';
    $s['summary'] = [
        'type' => 'bet', 'team' => $c['team'], 'bet' => $c['bet'], 'correct' => $correct,
        'bettor' => $c['bettor'] ?? null, 'responder' => $c['responder'] ?? null,
        'question' => $c['question']['text'],
        'correctAnswer' => $c['question']['options'][$c['question']['correct']],
        'changes' => array_map(static fn($after, $old) => round($after - $old, 1), $s['scores'], $before)
    ];
    // A equipe que alcança 16 pontos vence antes de qualquer minigame opcional.
    foreach ($s['scores'] as $team => $score) {
        if ($score >= $s['winScore'] && !in_array($team, $s['out'], true)) {
            $s['phase'] = 'finished';
            $s['winner'] = $team;
            $s['current'] = null;
            $s['roulette'] = null;
            $s['mines'] = null;
            return;
        }
    }
    if ($correct && $others) {
        $s['phase'] = 'roulette_offer';
        $s['roulette'] = ['status' => 'offer', 'team' => $c['team'],
            'stake' => $c['bet']];
        begin_vote($s, 'roulette', $c['team'], (int) floor(microtime(true) * 1000));
        $s['current'] = null;
        $s['notice'] = 'A equipe ' . $names[$c['team']] . ' recebeu uma chance na roleta!';
        return;
    }
    $s['roulette'] = null;
    if (!$correct && $others) {
        $s['mines'] = ['status' => 'offer', 'team' => $c['team'], 'stake' => $c['bet']];
        $s['phase'] = 'mines_offer';
        begin_vote($s, 'mines', $c['team'], (int) floor(microtime(true) * 1000));
        $s['current'] = null;
        $s['notice'] = 'A equipe votará se quer jogar o campo minado.';
        return;
    }
    settle_round($s);
}

function expire_round(array &$s): bool {
    $now = (int) floor(microtime(true) * 1000);
    if ($s['phase'] === 'streak_spin' && $now >= ($s['streakWheel']['endsAt'] ?? PHP_INT_MAX)) {
        $team = $s['streakWheel']['team'];
        $outcome = $s['streakWheel']['outcome'];
        $s['streakWheel']['status'] = 'result';
        $names = ['Azul', 'Laranja', 'Amarela', 'Roxa'];
        if ($outcome === 'win') {
            $s['winner'] = $team;
            $s['phase'] = 'finished';
            $s['current'] = null;
            $s['notice'] = 'Vitória imediata da equipe ' . $names[$team] . ' na roleta especial!';
        } else {
            if ($outcome === 'skip') {
                $s['skipNext'][$team] = true;
                $s['notice'] = 'Equipe ' . $names[$team] . ' não poderá apostar na próxima pergunta.';
            } elseif ($outcome === 'lose') {
                $s['scores'][$team] = round($s['scores'][$team] - 3, 1);
                $s['notice'] = 'Equipe ' . $names[$team] . ' perdeu 3 pontos na roleta especial.';
            } else {
                $s['notice'] = 'Nada aconteceu com a equipe ' . $names[$team] . ' na roleta especial.';
            }
            settle_round($s);
        }
        return true;
    }
    if ($s['phase'] === 'roulette_offer' && !($s['vote'] ?? null)) {
        begin_vote($s, 'roulette', $s['roulette']['team'], $now);
        return true;
    }
    if (in_array($s['phase'], ['roulette_offer', 'mines_offer'], true) &&
        $now >= ($s['vote']['deadline'] ?? PHP_INT_MAX)) {
        conclude_vote($s, $now);
        return true;
    }
    if ($s['phase'] === 'mines') {
        if (!isset($s['mines']['receiveEligible'])) {
            $s['mines']['receiveEligible'] = array_values(array_map(static fn($p) => $p['id'],
                array_filter($s['players'], static fn($p) => $p['team'] === $s['mines']['team'])));
            $s['mines']['receiveVotes'] = [];
            return true;
        }
        $receive = mine_receive_tally($s);
        if ($s['mines']['opened'] && $receive['count'] >= $receive['required']) {
            settle_mines($s, false);
            return true;
        }
    }
    if ($s['phase'] === 'mines' && $now >= ($s['mines']['deadline'] ?? PHP_INT_MAX)) {
        $closed = array_values(array_diff(range(0, 24), $s['mines']['opened']));
        $slot = $closed[random_int(0, count($closed) - 1)];
        $s['mines']['opened'][] = $slot;
        if ($s['mines']['board'][$slot] === 'mine') settle_mines($s, true);
        else {
            $s['mines']['diamonds']++;
            if ($s['mines']['diamonds'] === 17) settle_mines($s, false);
            else {
                $s['mines']['deadline'] = $now + 10000;
                $s['notice'] = 'Tempo esgotado: o sistema abriu um diamante. A equipe pode continuar ou votar para receber.';
            }
        }
        return true;
    }
    if ($s['phase'] === 'roulette_choice' && !($s['vote'] ?? null)) {
        begin_vote($s, 'color', $s['roulette']['team'], $now);
        return true;
    }
    if ($s['phase'] === 'roulette_choice' &&
        $now >= ($s['vote']['deadline'] ?? PHP_INT_MAX)) {
        conclude_color_vote($s, $now);
        return true;
    }
    if ($s['phase'] === 'roulette_spin' && $now >= ($s['roulette']['endsAt'] ?? PHP_INT_MAX)) {
        settle_roulette($s);
        return true;
    }
    $deadline = $s['current']['deadline'] ?? null;
    if ($deadline === null || $now < $deadline) return false;
    if ($s['phase'] === 'answer') {
        finish_round($s, false);
    } elseif ($s['phase'] === 'survival') {
        $team = $s['current']['team'];
        $s['streaks'][$team] = 0;
        $q = $s['current']['question'];
        $s['out'][] = $team;
        $names = ['Azul', 'Laranja', 'Amarela', 'Roxa'];
        $s['notice'] = $names[$team] . ' foi eliminada.';
        $s['summary'] = ['type' => 'survival', 'team' => $team, 'correct' => false,
            'responder' => null,
            'question' => $q['text'], 'correctAnswer' => $q['options'][$q['correct']],
            'changes' => [0, 0, 0, 0]];
        $s['phase'] = 'review'; $s['current'] = null;
        $alive = array_values(array_filter([0, 1, 2, 3], static fn($i) => !in_array($i, $s['out'], true)));
        if (count($alive) === 1) { $s['winner'] = $alive[0]; $s['phase'] = 'finished'; }
    } else {
        return false;
    }
    return true;
}

function public_state(array $s, ?array $player, bool $owner): array {
    $s['clientVersion'] = 'game-settings-1';
    $s['betOptions'] = array_map(static fn($team) => allowed_bets($s, $team), [0, 1, 2, 3]);
    if (!$owner) unset($s['questions']);
    if (isset($s['current']['question'])) {
        $q = $s['current']['question'];
        if (!$owner && $s['phase'] === 'answer' && !empty($s['current']['scrambled']))
            $q['text'] = $s['current']['displayText'];
        $s['current']['question'] = ['id' => $q['id'], 'text' => $q['text'], 'options' => $q['options']];
        if ($owner) $s['current']['question']['correct'] = $q['correct'];
    }
    if (isset($s['mines']['board'])) {
        $board = $s['mines']['board'];
        $revealed = ($s['mines']['status'] ?? '') === 'result';
        $opened = $s['mines']['opened'];
        $s['mines']['tiles'] = array_map(static fn($i) => $revealed || in_array($i, $opened, true)
            ? $board[$i] : null, range(0, 24));
        unset($s['mines']['board']);
    }
    if (($s['mines']['status'] ?? '') === 'playing') {
        $tally = mine_receive_tally($s);
        $s['mines']['receiveCount'] = $tally['count'];
        $s['mines']['receiveRequired'] = $tally['required'];
        $s['mines']['myReceiveVote'] = $player && isset($s['mines']['receiveVotes'][$player['id']]);
        $s['mines']['canReceiveVote'] = $player &&
            in_array($player['id'], $s['mines']['receiveEligible'], true);
    }
    if ($s['mines'] ?? null) unset($s['mines']['receiveVotes'], $s['mines']['receiveEligible']);
    if ($s['vote'] ?? null) {
        $tally = vote_tallies($s);
        foreach ($tally as $choice => $count) $s['vote'][$choice] = $count;
        $s['vote']['myVote'] = $player ? ($s['vote']['ballots'][$player['id']] ?? null) : null;
        $s['vote']['canVote'] = $player && in_array($player['id'], $s['vote']['eligible'], true);
        unset($s['vote']['ballots'], $s['vote']['eligible']);
    }
    $s['players'] = array_map(static fn($p) => $owner
        ? ['id' => $p['id'], 'name' => $p['name'], 'team' => $p['team']]
        : ['name' => $p['name'], 'team' => $p['team']], $s['players']);
    $s['me'] = $player ? ['name' => $player['name'], 'team' => $player['team']] : null;
    $s['owner'] = $owner;
    return $s;
}

function result(int $status = 200, array $body = ['ok' => true], ?array $cookie = null): array {
    return [$status, $body, $cookie];
}

function handle(string $route, string $method, array $body, array &$s, bool &$dirty, bool $owner): array {
    $now = (int) floor(microtime(true) * 1000);
    foreach (['streaks' => [0, 0, 0, 0], 'skipNext' => [false, false, false, false], 'streakWheel' => null, 'winScore' => 16] as $key => $default) {
        if (!array_key_exists($key, $s)) {
            $s[$key] = $default;
            $dirty = true;
        }
    }
    foreach ($s['scores'] as &$score) {
        $rounded = round((float)$score, 1);
        if ($score != $rounded) {
            $score = $rounded;
            $dirty = true;
        }
    }
    unset($score);
    if (!isset($s['betsUsed'])) {
        $s['betsUsed'] = [[], [], [], []];
        if (($s['summary']['type'] ?? null) === 'bet') {
            $s['betsUsed'][$s['summary']['team']] = [$s['summary']['bet']];
        } elseif ($s['phase'] === 'answer' && isset($s['current']['bet'])) {
            $s['betsUsed'][$s['current']['team']] = [$s['current']['bet']];
        }
        $dirty = true;
    }
    if (expire_round($s)) $dirty = true;
    $player = null;
    foreach ($s['players'] as $p) {
        if (isset($_COOKIE['player']) && hash_equals((string)$p['id'], (string)$_COOKIE['player'])) {
            $player = $p; break;
        }
    }
    if ($route === 'state' && $method === 'GET') return result(200, public_state($s, $player, $owner));
    if ($method !== 'POST') return result(405, ['error' => 'Método inválido.']);

    if ($route === 'join') {
        $name = trim((string)($body['name'] ?? ''));
        $team = filter_var($body['team'] ?? null, FILTER_VALIDATE_INT);
        if ($name === '' || strlen($name) > 128 || $team === false || $team < 0 || $team > 3)
            return result(400, ['error' => 'Informe nome e equipe.']);
        if (in_array($team, $s['out'], true)) return result(400, ['error' => 'Equipe eliminada.']);
        $id = bin2hex(random_bytes(16));
        $s['players'][] = ['id' => $id, 'name' => $name, 'team' => $team];
        $dirty = true;
        return result(200, ['ok' => true], ['player', $id]);
    }
    if ($route === 'leave') {
        if (!$player) return result(401, ['error' => 'Você não está em uma equipe.']);
        if ($s['phase'] !== 'lobby') return result(400, ['error' => 'Só é possível sair antes do jogo começar.']);
        $s['players'] = array_values(array_filter($s['players'],
            static fn($p) => $p['id'] !== $player['id']));
        $dirty = true;
        return result(200, ['ok' => true], ['player', '', time() - 3600]);
    }
    if ($route === 'bet') {
        if (!$player) return result(401, ['error' => 'Entre no jogo.']);
        $bet = filter_var($body['bet'] ?? null, FILTER_VALIDATE_INT);
        if ($s['phase'] !== 'bet') return result(400, ['error' => 'Aposta encerrada.']);
        if ($now < $s['current']['opensAt']) return result(400, ['error' => 'Aguarde os 5 segundos.']);
        if (in_array($player['team'], $s['out'], true)) return result(400, ['error' => 'Equipe eliminada.']);
        $team = $player['team'];
        if (in_array($team, $s['current']['blockedTeams'] ?? [], true))
            return result(403, ['error' => 'Sua equipe não pode apostar nesta pergunta.']);
        $options = allowed_bets($s, $team);
        if ($bet === false || !in_array($bet, $options, true))
            return result(400, ['error' => 'Aposta indisponível neste ciclo.']);
        if (!isset($s['betsUsed'])) $s['betsUsed'] = [[], [], [], []];
        $used = $s['betsUsed'][$team];
        // If debt leaves only a previously used value, allow it without consuming the cycle.
        if (!in_array($bet, $used, true)) {
            $used[] = $bet;
            $s['betsUsed'][$team] = count($used) === 3 ? [] : $used;
        }
        $s['current']['team'] = $player['team'];
        $s['current']['bet'] = $bet;
        $s['current']['bettor'] = $player['name'];
        $s['current']['deadline'] = $now + 30000;
        $s['current']['scrambled'] = random_int(1, 3) === 1;
        if ($s['current']['scrambled']) {
            $s['current']['displayText'] = shuffle_question_words($s['current']['question']['text']);
            if ($s['current']['displayText'] === $s['current']['question']['text'])
                $s['current']['scrambled'] = false;
        }
        $s['phase'] = 'answer';
        $names = ['Azul', 'Laranja', 'Amarela', 'Roxa'];
        $s['notice'] = $names[$player['team']] . ' apostou ' . $bet . ' ponto(s).';
        $dirty = true;
        return result();
    }
    if ($route === 'answer') {
        if (!$player) return result(401, ['error' => 'Entre no jogo.']);
        $option = filter_var($body['option'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($s['phase'], ['answer', 'survival'], true) || $s['current']['team'] !== $player['team'])
            return result(400, ['error' => 'Sua equipe não pode responder.']);
        if ($option === false || $option < 0 || $option > 3) return result(400, ['error' => 'Escolha uma alternativa.']);
        $correct = $option === $s['current']['question']['correct'];
        $s['current']['responder'] = $player['name'];
        if ($s['phase'] === 'answer') finish_round($s, $correct);
        else {
            $s['streaks'][$player['team']] = $correct ? min(3, $s['streaks'][$player['team']] + 1) : 0;
            $q = $s['current']['question'];
            if (!$correct) $s['out'][] = $player['team'];
            $names = ['Azul', 'Laranja', 'Amarela', 'Roxa'];
            $s['notice'] = $names[$player['team']] . ($correct ? ' sobreviveu.' : ' foi eliminada.');
            $s['summary'] = ['type' => 'survival', 'team' => $player['team'], 'correct' => $correct,
                'responder' => $player['name'],
                'question' => $q['text'], 'correctAnswer' => $q['options'][$q['correct']],
                'changes' => [0, 0, 0, 0]];
            $s['phase'] = 'review'; $s['current'] = null;
        }
        $alive = array_values(array_filter([0, 1, 2, 3], static fn($i) => !in_array($i, $s['out'], true)));
        if (count($alive) === 1) { $s['winner'] = $alive[0]; $s['phase'] = 'finished'; }
        $dirty = true;
        return result();
    }
    if ($route === 'streak-open') {
        if (!$player || $s['phase'] !== 'review' || ($s['streaks'][$player['team']] ?? 0) < 3 ||
            in_array($player['team'], $s['out'], true))
            return result(403, ['error' => 'A roleta especial ainda não está disponível para sua equipe.']);
        $s['roulette'] = null;
        $s['mines'] = null;
        $s['streakWheel'] = ['team' => $player['team'], 'player' => $player['name'], 'status' => 'offer'];
        $s['phase'] = 'streak_offer';
        $s['notice'] = 'A equipe abriu a roleta especial.';
        $dirty = true;
        return result();
    }
    if ($route === 'streak-cancel') {
        if (!$player || $s['phase'] !== 'streak_offer' || $s['streakWheel']['team'] !== $player['team'])
            return result(403, ['error' => 'A roleta especial não pode ser fechada.']);
        $s['streakWheel'] = null;
        $s['phase'] = 'review';
        $s['notice'] = 'A equipe adiou a roleta especial.';
        $dirty = true;
        return result();
    }
    if ($route === 'streak-spin') {
        if (!$player || $s['phase'] !== 'streak_offer' || $s['streakWheel']['team'] !== $player['team'])
            return result(403, ['error' => 'Apenas a equipe pode girar sua roleta especial.']);
        // 300 equally likely draws: victory 9/300 (3%); each other outcome 97/300.
        $draw = random_int(0, 299);
        $outcome = $draw < 9 ? 'win' : ['nothing', 'skip', 'lose'][intdiv($draw - 9, 97)];
        $slots = ['win' => [0], 'nothing' => [1, 4, 7],
            'skip' => [2, 5, 8], 'lose' => [3, 6, 9]];
        $slot = $slots[$outcome][random_int(0, count($slots[$outcome]) - 1)];
        $s['streakWheel']['slot'] = $slot;
        $s['streakWheel']['outcome'] = $outcome;
        $s['streakWheel']['player'] = $player['name'];
        $s['streakWheel']['status'] = 'spin';
        $s['streakWheel']['startedAt'] = $now;
        $s['streakWheel']['endsAt'] = $now + 6000;
        $s['streaks'][$player['team']] = 0;
        $s['phase'] = 'streak_spin';
        $s['notice'] = 'Roleta especial girando!';
        $dirty = true;
        return result();
    }
    if ($route === 'bonus-vote') {
        if (!$player || !in_array($s['phase'], ['roulette_offer', 'mines_offer'], true) ||
            !$s['vote'] || !in_array($player['id'], $s['vote']['eligible'], true))
            return result(403, ['error' => 'Você não pode votar nesta rodada.']);
        $choice = (string)($body['choice'] ?? '');
        if (!in_array($choice, ['yes', 'no'], true))
            return result(400, ['error' => 'Escolha um voto válido.']);
        $s['vote']['ballots'][$player['id']] = $choice;
        $dirty = true;
        return result();
    }
    if ($route === 'roulette-choose') {
        if (!$player || $s['phase'] !== 'roulette_choice' ||
            !$s['vote'] || !in_array($player['id'], $s['vote']['eligible'], true))
            return result(403, ['error' => 'Apenas a equipe da rodada pode escolher.']);
        $choice = (string)($body['color'] ?? '');
        if (!in_array($choice, ['red', 'black', 'green'], true))
            return result(400, ['error' => 'Escolha uma cor válida.']);
        $s['vote']['ballots'][$player['id']] = $choice;
        $dirty = true;
        return result();
    }
    if ($route === 'mines-open' || $route === 'mines-receive') {
        if (!$player || $s['phase'] !== 'mines' || $player['team'] !== $s['mines']['team'])
            return result(403, ['error' => 'Apenas a equipe da rodada pode jogar.']);
        if ($route === 'mines-receive') {
            if (!$s['mines']['opened'])
                return result(400, ['error' => 'Abra pelo menos uma casa antes de receber.']);
            if (!in_array($player['id'], $s['mines']['receiveEligible'], true))
                return result(403, ['error' => 'Você não pode votar nesta rodada.']);
            $s['mines']['receiveVotes'][$player['id']] = true;
            $receive = mine_receive_tally($s);
            if ($receive['count'] >= $receive['required']) settle_mines($s, false, $player['name']);
            else $s['notice'] = $receive['count'] . ' de ' . $receive['required'] . ' confirmação(ões) para receber.';
        } else {
            $slot = filter_var($body['slot'] ?? null, FILTER_VALIDATE_INT);
            if ($slot === false || $slot < 0 || $slot > 24 ||
                in_array($slot, $s['mines']['opened'], true))
                return result(400, ['error' => 'Escolha uma casa fechada.']);
            $s['mines']['opened'][] = $slot;
            $s['mines']['player'] = $player['name'];
            if ($s['mines']['board'][$slot] === 'mine') {
                settle_mines($s, true, $player['name']);
            } else {
                $s['mines']['diamonds']++;
                $s['notice'] = $player['name'] . ' encontrou um diamante!';
                if ($s['mines']['diamonds'] === 17) settle_mines($s, false, $player['name']);
            }
        }
        $dirty = true;
        return result();
    }

    if (!$owner) return result(403, ['error' => 'Apenas o criador pode fazer isso.']);
    if ($route === 'game-settings') {
        if ($s['phase'] === 'finished')
            return result(400, ['error' => 'A partida terminou. Reinicie o jogo para editar os pontos.']);
        if (array_key_exists('winScore', $body)) {
            $target = filter_var($body['winScore'], FILTER_VALIDATE_INT);
            if ($target === false || $target < 6 || $target > 100)
                return result(400, ['error' => 'A meta deve ser um número inteiro entre 6 e 100.']);
            $s['winScore'] = $target;
        } elseif (array_key_exists('team', $body) && array_key_exists('score', $body)) {
            $team = filter_var($body['team'], FILTER_VALIDATE_INT);
            $score = filter_var($body['score'], FILTER_VALIDATE_FLOAT);
            if ($team === false || $team < 0 || $team > 3 || $score === false ||
                $score < -100 || $score > 100 || abs($score * 10 - round($score * 10)) > 0.000001)
                return result(400, ['error' => 'Use uma equipe válida e pontos entre −100 e 100, com até uma casa decimal.']);
            $s['scores'][$team] = round($score, 1);
        } else {
            return result(400, ['error' => 'Informe a meta ou a pontuação de uma equipe.']);
        }
        if ($s['phase'] !== 'lobby') {
            $eligible = array_filter([0, 1, 2, 3], static fn($i) =>
                !in_array($i, $s['out'], true) && $s['scores'][$i] >= $s['winScore']);
            if ($eligible) {
                usort($eligible, static fn($a, $b) => $s['scores'][$b] <=> $s['scores'][$a]);
                $s['winner'] = $eligible[0];
                $s['phase'] = 'finished';
                $s['current'] = null;
                $s['vote'] = null;
                $s['roulette'] = null;
                $s['mines'] = null;
                $s['streakWheel'] = null;
                $s['notice'] = 'A equipe atingiu a meta de pontos após um ajuste do criador.';
            }
        }
        $dirty = true;
        return result();
    }
    if ($route === 'remove-player') {
        $id = (string)($body['id'] ?? '');
        if ($id === '') return result(400, ['error' => 'Participante inválido.']);
        $before = count($s['players']);
        $s['players'] = array_values(array_filter($s['players'],
            static fn($p) => $p['id'] !== $id));
        if (count($s['players']) === $before)
            return result(404, ['error' => 'Participante não encontrado.']);
        $dirty = true;
        return result();
    }
    if ($route === 'next') {
        if (!in_array($s['phase'], ['lobby', 'review', 'survival_wait'], true))
            return result(400, ['error' => 'Conclua a rodada atual.']);
        $survival = $s['phase'] === 'survival_wait';
        $team = $s['current']['team'] ?? null;
        $q = pick_question($s);
        if (!$q) return result(400, ['error' => 'Cadastre mais perguntas.']);
        $s['summary'] = null;
        $s['roulette'] = null;
        $s['mines'] = null;
        $s['vote'] = null;
        $s['streakWheel'] = null;
        if ($survival) {
            $s['phase'] = 'survival';
            $s['current'] = ['team' => $team, 'question' => $q, 'deadline' => $now + 30000];
            $names = ['Azul', 'Laranja', 'Amarela', 'Roxa'];
            $s['notice'] = 'Sobrevivência: ' . $names[$team];
        } else {
            $s['round']++;
            $s['phase'] = 'bet';
            $blocked = array_keys(array_filter($s['skipNext']));
            $s['skipNext'] = [false, false, false, false];
            $s['current'] = ['question' => $q, 'opensAt' => $now + 5000, 'blockedTeams' => $blocked];
            $s['notice'] = 'Apostas abrem em 5 segundos.';
        }
        $dirty = true;
        return result();
    }
    if ($route === 'save') {
        $text = trim((string)($body['text'] ?? ''));
        $options = $body['options'] ?? null;
        $correct = filter_var($body['correct'] ?? null, FILTER_VALIDATE_INT);
        if ($text === '' || strlen($text) > 1000 || !is_array($options) || count($options) !== 4 ||
            $correct === false || $correct < 0 || $correct > 3)
            return result(400, ['error' => 'Preencha pergunta, quatro opções e resposta.']);
        $options = array_map(static fn($x) => trim((string)$x), $options);
        foreach ($options as $x) if ($x === '' || strlen($x) > 500)
            return result(400, ['error' => 'Preencha pergunta, quatro opções e resposta.']);
        $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
        $found = false;
        foreach ($s['questions'] as &$q) {
            if ($id !== false && $q['id'] === $id) {
                if (($s['current']['question']['id'] ?? null) === $id)
                    return result(400, ['error' => 'Pergunta em uso.']);
                $q = ['id' => $id, 'text' => $text, 'options' => $options, 'correct' => $correct];
                $found = true; break;
            }
        }
        unset($q);
        if (!$found) {
            $ids = array_column($s['questions'], 'id');
            $s['questions'][] = ['id' => ($ids ? max($ids) : 0) + 1, 'text' => $text,
                'options' => $options, 'correct' => $correct];
        }
        $dirty = true;
        return result();
    }
    if ($route === 'delete') {
        $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
        if (($s['current']['question']['id'] ?? null) === $id) return result(400, ['error' => 'Pergunta em uso.']);
        $s['questions'] = array_values(array_filter($s['questions'], static fn($q) => $q['id'] !== $id));
        $s['used'] = array_values(array_filter($s['used'], static fn($x) => $x !== $id));
        $dirty = true;
        return result();
    }
    if ($route === 'reset') {
        $questions = $s['questions']; $players = $s['players']; $winScore = $s['winScore'];
        $s = initial_state($questions); $s['players'] = $players; $s['winScore'] = $winScore;
        $dirty = true;
        return result();
    }
    if ($route === 'clear-teams') {
        $s['players'] = [];
        $dirty = true;
        return result();
    }
    return result(404, ['error' => 'Ação desconhecida.']);
}

try {
    $private = dirname(__DIR__) . '/bet_bd1_private';
    $config = require $private . '/config.php';
    $route = (string)($_GET['route'] ?? '');
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $body = $method === 'POST' ? json_decode((string)file_get_contents('php://input'), true) : [];
    if (!is_array($body)) $body = [];
    $owner = isset($_COOKIE['owner']) && hash_equals($config['cookie_token'], (string)$_COOKIE['owner']);
    if ($route === 'owner' && $method === 'POST') {
        $candidate = hash('sha256', 'bet-bd1:' . (string)($body['code'] ?? ''));
        if (!hash_equals($config['owner_hash'], $candidate)) send(403, ['error' => 'Código incorreto.']);
        setcookie('owner', $config['cookie_token'], ['expires' => time() + 604800, 'path' => '/',
            'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        send(200, ['ok' => true]);
    }
    $file = $private . '/state.json';
    $fp = fopen($file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('Estado indisponível');
    $raw = stream_get_contents($fp);
    $dirty = $raw === '';
    $s = $dirty ? initial_state(json_decode((string)file_get_contents($private . '/question-bank.json'), true, 512, JSON_THROW_ON_ERROR))
        : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    [$status, $output, $cookie] = handle($route, $method, $body, $s, $dirty, $owner);
    if ($dirty) {
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    if ($cookie) setcookie($cookie[0], $cookie[1], ['expires' => $cookie[2] ?? time() + 604800, 'path' => '/',
        'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    send($status, $output);
} catch (Throwable $error) {
    error_log('Bet BD1: ' . $error->getMessage());
    send(503, ['error' => 'Jogo temporariamente indisponível.']);
}

