<?php
/* API de Conversões da Meta: funções comuns a capi.php e stripe-webhook.php.
   Configuração e dados ficam FORA do public_html, na pasta inicial da conta:
     ~/.ambar_capi.json   -> chaves (token da Meta e segredo do webhook da Stripe)
     ~/.ambar_capi/       -> contexto dos visitantes, controle de envios e log
   Este arquivo não faz nada se for aberto diretamente no navegador. */
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(404); exit; }

/* JSON sem escapar barras/acentos; UTF-8 inválido é substituído em vez de fazer o json_encode falhar */
define('CAPI_JSON', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

function capi_home() {
  $h = getenv('AMBAR_CAPI_HOME');               /* só para testes locais */
  return $h ? rtrim($h, '/\\') : dirname(__DIR__, 2);
}

function capi_config() {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $raw = @file_get_contents(capi_home() . '/.ambar_capi.json');
  $c = json_decode((string)$raw, true);
  if (!is_array($c)) $c = [];
  $cfg = $c + [
    'meta_token' => '',
    'stripe_webhook_secret' => '',
    'pixel_id' => '1648412173302853',
    'graph_version' => 'v23.0',
    'graph_base' => 'https://graph.facebook.com',
    'test_event_code' => '',
    'dry_run' => false,
    'allowed_hosts' => ['ambaralternativewebinar.com.br', 'www.ambaralternativewebinar.com.br'],
    'site_url' => 'https://ambaralternativewebinar.com.br/',
  ];
  foreach (['meta_token', 'stripe_webhook_secret', 'pixel_id', 'graph_version', 'graph_base', 'test_event_code', 'site_url'] as $k) $cfg[$k] = trim((string)$cfg[$k]);
  $cfg['allowed_hosts'] = array_map('strtolower', array_map('strval', (array)$cfg['allowed_hosts']));
  return $cfg;
}

function capi_dir($sub = '') {
  $d = capi_home() . '/.ambar_capi' . ($sub ? '/' . $sub : '');
  if (!is_dir($d)) @mkdir($d, 0700, true);
  return $d;
}

/* log sem dados pessoais */
function capi_log($msg) {
  @file_put_contents(capi_dir() . '/log.txt', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function capi_valid_ref($r) { return is_string($r) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $r); }

/* ---------- normalização + hash SHA-256 (padrão da Meta) ---------- */
function capi_h($v) { return ($v === null || $v === '') ? null : hash('sha256', $v); }
function capi_norm_email($e) {
  $e = strtolower(trim((string)$e));
  return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}
function capi_norm_phone($p) {
  $d = preg_replace('/\D+/', '', (string)$p);
  $d = ltrim($d, '0');
  if (strlen($d) === 10 || strlen($d) === 11) $d = '55' . $d;  /* número brasileiro sem DDI */
  return strlen($d) >= 12 ? $d : '';
}
function capi_norm_name($n) {
  $n = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$n), 'UTF-8') : strtolower(trim((string)$n));
  return trim((string)preg_replace('/[^\p{L}\s-]/u', '', $n));   /* UTF-8 inválido vira '' */
}
function capi_split_name($full) {
  $parts = preg_split('/\s+/', trim((string)$full), -1, PREG_SPLIT_NO_EMPTY);
  if (!$parts) return ['', ''];
  $first = array_shift($parts);
  return [$first, $parts ? end($parts) : ''];
}

/* monta user_data a partir de dados crus (e-mail, telefone, nome) + contexto do navegador */
function capi_user_data($pii, $ctx) {
  $em = capi_norm_email($pii['email'] ?? '');
  $ph = capi_norm_phone($pii['tel'] ?? '');
  $fn = capi_norm_name($pii['nome'] ?? '');
  $ln = capi_norm_name($pii['sobrenome'] ?? '');
  $u = array_filter([
    'em' => $em ? [capi_h($em)] : null,
    'ph' => $ph ? [capi_h($ph)] : null,
    'fn' => $fn ? [capi_h($fn)] : null,
    'ln' => $ln ? [capi_h($ln)] : null,
    'country' => [capi_h('br')],
    'external_id' => $em ? [capi_h($em)] : null,
    'client_ip_address' => $ctx['ip'] ?? null,
    'client_user_agent' => $ctx['ua'] ?? null,
    'fbp' => $ctx['fbp'] ?? null,
    'fbc' => $ctx['fbc'] ?? null,
  ]);
  return $u;
}

/* contexto do visitante (IP, navegador, cookies do Pixel), lido da própria requisição */
function capi_request_context() {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';   /* X-Forwarded-For não é usado: pode ser forjado por quem chama */
  $fbp = $_COOKIE['_fbp'] ?? '';
  $fbc = $_COOKIE['_fbc'] ?? '';
  return array_filter([
    'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
    'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400) ?: null,
    'fbp' => preg_match('/^fb\.\d\.\d+\.\d+$/', $fbp) ? $fbp : null,
    'fbc' => preg_match('/^fb\.\d\.\d+\.[A-Za-z0-9_-]+$/', $fbc) ? $fbc : null,
  ]);
}

function capi_ctx_save($ref, $data) {
  /* um ref nunca é reaproveitado: 'x' só cria se não existir (atômico), então não sobrescreve o de outra pessoa */
  $fh = @fopen(capi_dir('ctx') . '/' . $ref . '.json', 'x');
  if (!$fh) return false;
  fwrite($fh, json_encode($data, CAPI_JSON)); fclose($fh);
  capi_cleanup();
}
function capi_ctx_load($ref) {
  if (!capi_valid_ref($ref)) return [];
  $d = json_decode((string)@file_get_contents(capi_dir('ctx') . '/' . $ref . '.json'), true);
  return is_array($d) ? $d : [];
}
/* apaga contextos e marcas de envio com mais de 30 dias (no máximo uma varredura por dia) */
function capi_cleanup() {
  $marca = capi_dir() . '/ultima_limpeza';
  if (is_file($marca) && filemtime($marca) > time() - 86400) return;
  @touch($marca);
  foreach (['ctx', 'enviados', 'rl'] as $sub) {
    foreach (glob(capi_dir($sub) . '/*') ?: [] as $f) if (is_file($f) && filemtime($f) < time() - 30 * 86400) @unlink($f);
  }
}

/* envia um evento para a API de Conversões. Retorna true se a Meta aceitou. */
function capi_send($event, $label) {
  $c = capi_config();
  $payload = ['data' => json_encode([$event], CAPI_JSON)];
  if ($c['test_event_code'] !== '') $payload['test_event_code'] = $c['test_event_code'];

  if (!empty($c['dry_run'])) {
    @file_put_contents(capi_dir() . '/dryrun.jsonl', json_encode($event, CAPI_JSON) . "\n", FILE_APPEND | LOCK_EX);
    capi_log("DRY-RUN $label {$event['event_name']} id={$event['event_id']}");
    return true;
  }
  if ($c['meta_token'] === '') { capi_log("ERRO $label: sem meta_token na configuracao"); return false; }

  $payload['access_token'] = $c['meta_token'];
  $url = rtrim($c['graph_base'], '/') . '/' . $c['graph_version'] . '/' . rawurlencode($c['pixel_id']) . '/events';
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($payload),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
  ]);
  $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
  curl_close($ch);
  $d = json_decode((string)$res, true);
  if ($code === 200 && !empty($d['events_received'])) {
    capi_log("OK $label {$event['event_name']} id={$event['event_id']}" . ($c['test_event_code'] !== '' ? ' (teste)' : ''));
    return true;
  }
  $msg = $d['error']['message'] ?? ($err ?: 'resposta inesperada');
  capi_log("ERRO $label {$event['event_name']} id={$event['event_id']} http=$code: " . substr($msg, 0, 200));
  return false;
}

/* ---------- Zoho CRM via Zoho Flow (módulo "Webinar - Alavancagem em 2027") ----------
   A URL do webhook do Flow é secreta: fica em ~/.ambar_capi.json, chave "zoho_flow".
   O Flow cria ou atualiza o registro pelo Celular, então o mesmo cliente não duplica.
   Só vão os campos preenchidos, para um envio parcial não apagar o que já está no registro. */
function zoho_campos() {
  return [
    'nome_completo' => 120, 'celular' => 20, 'email' => 120, 'status' => 60, 'etapa' => 40, 'ingresso' => 40,
    'duvida_chat' => 2000, 'comprador_nome' => 120, 'comprador_email' => 120, 'comprador_telefone' => 20,
    'valor_ingresso' => 20, 'link_pagamento' => 120,
    'objetivo' => 120, 'tem_imoveis' => 120, 'usou_credito' => 20, 'credito_tipos' => 400,
    'disponivel' => 120, 'quando' => 120, 'aporte' => 120, 'profissao' => 120, 'cidade_estado' => 120,
    'pergunta_rafael' => 2000, 'autorizacao' => 10,
  ];
}
function zoho_texto($v, $max) {
  $v = is_scalar($v) ? (string)$v : '';
  if (!preg_match('//u', $v)) return '';                       /* UTF-8 inválido */
  $v = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v));
  return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}
/* Envia ao Flow. Retorna true se o Flow aceitou. */
function zoho_send($dados, $label) {
  $out = [];
  foreach (zoho_campos() as $k => $max) {
    if (!isset($dados[$k])) continue;
    $v = zoho_texto($dados[$k], $max);
    if ($k === 'celular' || $k === 'comprador_telefone') $v = capi_norm_phone($v);
    if ($k === 'email' || $k === 'comprador_email') $v = capi_norm_email($v);
    if ($v !== '') $out[$k] = $v;
  }
  if (empty($out['celular'])) { capi_log("ZOHO $label: sem celular, nao enviado"); return false; }

  $c = capi_config();
  if (!empty($c['dry_run'])) {
    @file_put_contents(capi_dir() . '/zoho-dryrun.jsonl', json_encode($out, CAPI_JSON) . "\n", FILE_APPEND | LOCK_EX);
    capi_log("DRY-RUN ZOHO $label");
    return true;
  }
  $url = trim((string)($c['zoho_flow'] ?? ''));
  $host = strtolower((string)parse_url($url, PHP_URL_HOST));
  if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !preg_match('/(^|\.)zoho\.com$/', $host)) {
    capi_log("ERRO ZOHO $label: zoho_flow ausente ou invalido na configuracao"); return false;
  }
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($out, CAPI_JSON),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_FOLLOWLOCATION => false,
  ]);
  curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
  curl_close($ch);
  if ($code >= 200 && $code < 300) { capi_log("OK ZOHO $label"); return true; }
  capi_log("ERRO ZOHO $label http=$code " . substr((string)$err, 0, 120));
  return false;
}

/* Confere a assinatura "Stripe-Signature" (t=...,v1=...) de um webhook. Recusa eventos com mais de 5 minutos. */
function stripe_assinatura_ok($body, $segredo) {
  if ($segredo === '') return false;
  $t = null; $assinaturas = [];
  foreach (explode(',', (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '')) as $parte) {
    $kv = explode('=', trim($parte), 2);
    if (count($kv) !== 2) continue;
    if ($kv[0] === 't' && ctype_digit($kv[1])) $t = (int)$kv[1];
    elseif ($kv[0] === 'v1') $assinaturas[] = $kv[1];
  }
  if ($t === null || !$assinaturas || abs(time() - $t) > 300) return false;
  $esperada = hash_hmac('sha256', $t . '.' . $body, $segredo);
  foreach ($assinaturas as $a) if (hash_equals($esperada, $a)) return true;
  return false;
}

/* Limite de chamadas por IP (IP real da conexão). Usa trava no arquivo para que chamadas
   simultâneas não passem todas juntas. Retorna false quando o limite estourou. */
function capi_limite($prefixo, $max, $janela) {
  $fh = @fopen(capi_dir('rl') . '/' . $prefixo . sha1((string)($_SERVER['REMOTE_ADDR'] ?? 'x')), 'c+');
  if (!$fh) return true;
  flock($fh, LOCK_EX);
  $hits = json_decode((string)stream_get_contents($fh), true);
  $hits = array_values(array_filter(is_array($hits) ? $hits : [], function ($t) use ($janela) { return is_int($t) && $t > time() - $janela; }));
  $ok = count($hits) < $max;
  if ($ok) { $hits[] = time(); ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($hits)); }
  flock($fh, LOCK_UN); fclose($fh);
  return $ok;
}
