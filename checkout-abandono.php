<?php
/* Mensagem de abandono de checkout (WhatsApp). Roda pelo CRON do cPanel, a cada 1 minuto:
     php /caminho/do/site/checkout-abandono.php
   Pega quem foi para o checkout há "wa_abandono_min" minutos (padrão 5) e não teve compra aprovada,
   e envia o template "wa_tpl_abandono" (corpo {{1}} = primeiro nome; botão {{1}} = código do finalizar.php).
   Cada ida ao checkout recebe no máximo UMA mensagem. Não roda pelo navegador. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/capi-lib.php';
date_default_timezone_set('America/Sao_Paulo');

/* uma execução por vez (se uma demorar, a próxima do cron não atropela) */
$trava = fopen(capi_dir() . '/abandono.lock', 'c');
if (!$trava || !flock($trava, LOCK_EX | LOCK_NB)) exit(0);

$w = wa_config();
$espera = $w['wa_abandono_min'] * 60;
$agora = time();
$MAX_IDADE = 6 * 3600;     /* não manda abandono de quem foi ao checkout há mais de 6 horas (cron parado, etc.) */
$MAX_TENTATIVAS = 3;

foreach (glob(capi_dir('checkout') . '/*.json') ?: [] as $arq) {
  $ref = basename($arq, '.json');
  $d = checkout_load($ref);
  if (!$d || ($d['status'] ?? '') !== 'aberto' || !empty($d['abandono']['ok']) || !empty($d['abandono']['pulado'])) continue;
  $ts = (int)($d['ts'] ?? 0);
  if ($agora - $ts < $espera) continue;

  $pular = null;
  if ($agora - $ts > $MAX_IDADE) $pular = 'antigo';
  else {
    $m = comprou_tel_marca($d['tel'] ?? '');
    if ($m && is_file($m) && filemtime($m) >= $ts - 86400) $pular = 'ja comprou';   /* comprou por outra ida ao checkout */
  }
  if ((int)($d['abandono_tentativas'] ?? 0) >= $MAX_TENTATIVAS) $pular = 'falhou ' . $MAX_TENTATIVAS . 'x';
  if ($pular) {
    checkout_update($ref, function ($x) use ($pular, $agora) { if (!$x) return null; $x['abandono'] = ['ts' => $agora, 'pulado' => $pular]; return $x; });
    continue;
  }

  /* reserva o envio (o webhook da compra pode chegar agora: se marcou "comprou", não envia) */
  $reservado = checkout_update($ref, function ($x) use ($agora) {
    if (!$x || ($x['status'] ?? '') !== 'aberto' || !empty($x['abandono']['ok'])) return null;
    $x['abandono_tentativas'] = (int)($x['abandono_tentativas'] ?? 0) + 1;
    $x['abandono'] = ['ts' => $agora, 'ok' => false, 'detalhe' => 'enviando'];
    return $x;
  });
  if (!$reservado) continue;

  $primeiro = capi_split_name($d['nome'] ?? '')[0] ?: 'tudo bem';
  [$ok, $det] = wa_enviar_template($d['tel'], $d['nome'] ?? '', $w['wa_tpl_abandono'], [$primeiro], $d['codigo'] ?? '', 'abandono');
  checkout_update($ref, function ($x) use ($ok, $det) { if (!$x) return null; $x['abandono'] = ['ts' => time(), 'ok' => $ok, 'detalhe' => $det]; return $x; });
}

flock($trava, LOCK_UN);
fclose($trava);
